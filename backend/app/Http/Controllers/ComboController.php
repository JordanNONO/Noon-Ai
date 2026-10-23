<?php

namespace App\Http\Controllers;

use App\Agents\ComboRunner;
use App\Http\Controllers\Concerns\StreamsEvents;
use App\Models\Agent;
use App\Models\Combo;
use App\Models\ComboRun;
use Cron\CronExpression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComboController extends Controller
{
    use StreamsEvents;

    public function index()
    {
        return ['combos' => Combo::orderByDesc('is_favorite')->orderBy('name')->get()->map->toPublic()->values()];
    }

    public function show(Combo $combo)
    {
        return ['combo' => $combo->toPublic()];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $combo = DB::transaction(function () use ($data, $request) {
            $combo = Combo::create($this->comboFields($data) + ['created_by' => $request->user()->id]);
            $this->syncSteps($combo, $data['steps'], $request);

            return $combo;
        });

        return ['combo' => $combo->toPublic()];
    }

    public function update(Request $request, Combo $combo)
    {
        $this->canEdit($request, $combo);
        $data = $this->validated($request);
        DB::transaction(function () use ($combo, $data, $request) {
            $combo->update($this->comboFields($data));
            $this->syncSteps($combo, $data['steps'], $request);
        });

        return ['combo' => $combo->fresh()->toPublic()];
    }

    public function toggleFavorite(Request $request, Combo $combo)
    {
        $combo->update(['is_favorite' => ! $combo->is_favorite]);

        return ['combo' => $combo->toPublic()];
    }

    public function destroy(Request $request, Combo $combo)
    {
        $this->canEdit($request, $combo);
        $combo->delete();

        return response()->noContent();
    }

    public function run(Request $request, Combo $combo, ComboRunner $runner)
    {
        abort_if($combo->steps()->doesntExist(), 422, 'Ce parcours n\'a aucune étape.');
        $input = $request->validate(['input' => 'nullable|string|max:20000'])['input'] ?? null;
        $user = $request->user();

        return $this->sse(fn ($emit) => $runner->start($combo, $user, $input, $emit));
    }

    public function resume(Request $request, ComboRun $run, ComboRunner $runner)
    {
        abort_unless($run->user_id === $request->user()->id, 404);
        abort_unless($run->status === 'waiting', 422, 'Ce parcours n\'attend pas de validation.');
        $run->update(['status' => 'running']);

        return $this->sse(fn ($emit) => $runner->continue($run, $emit));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'trigger_type' => 'required|in:manual,schedule',
            'schedule_cron' => 'nullable|required_if:trigger_type,schedule|string|max:60',
            'schedule_label' => 'nullable|string|max:80',
            'is_favorite' => 'sometimes|boolean',
            'steps' => 'required|array|min:1|max:8',
            'steps.*.agent_id' => 'required|integer|exists:agents,id',
            'steps.*.task' => 'required|string|max:160',
            'steps.*.prompt' => 'required|string|max:8000',
            'steps.*.model_tier' => 'nullable|in:standard,advanced',
            'steps.*.handoff' => 'nullable|in:full,summary,files',
            'steps.*.requires_validation' => 'nullable|boolean',
            'steps.*.connector_ids' => 'nullable|array',
            'steps.*.connector_ids.*' => 'integer|exists:connectors,id',
        ]);
        if ($data['trigger_type'] === 'schedule' && ! CronExpression::isValidExpression($data['schedule_cron'])) {
            throw ValidationException::withMessages(['schedule_cron' => 'Planification invalide.']);
        }

        return $data;
    }

    private function comboFields(array $data): array
    {
        return [
            'name' => $data['name'],
            'trigger_type' => $data['trigger_type'],
            'schedule_cron' => $data['trigger_type'] === 'schedule' ? $data['schedule_cron'] : null,
            'schedule_label' => $data['trigger_type'] === 'schedule' ? ($data['schedule_label'] ?? null) : null,
            'is_favorite' => $data['is_favorite'] ?? false,
        ];
    }

    private function syncSteps(Combo $combo, array $steps, Request $request): void
    {
        $combo->steps()->delete();
        foreach (array_values($steps) as $i => $s) {
            abort_unless(Agent::accessibleBy($request->user())->whereKey($s['agent_id'])->exists(), 422, 'Assistant non disponible.');
            $combo->steps()->create([
                'position' => $i + 1,
                'agent_id' => $s['agent_id'],
                'task' => $s['task'],
                'prompt' => $s['prompt'],
                'model_tier' => $s['model_tier'] ?? 'standard',
                'handoff' => $s['handoff'] ?? 'full',
                'requires_validation' => (bool) ($s['requires_validation'] ?? false),
                'connector_ids' => $s['connector_ids'] ?? [],
            ]);
        }
    }

    private function canEdit(Request $request, Combo $combo): void
    {
        abort_unless($request->user()->isAdmin() || $combo->created_by === $request->user()->id, 403);
    }
}
