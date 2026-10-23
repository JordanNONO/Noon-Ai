<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $agents = Agent::accessibleBy($user)->with('connectors', 'creator')->orderByDesc('is_default')->orderBy('name')->get();

        return [
            'agents' => $agents->map(fn ($a) => $a->toPublic($user))->values(),
            'themes' => collect(Agent::THEMES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ];
    }

    public function show(Request $request, Agent $agent)
    {
        $user = $request->user();
        abort_unless(Agent::accessibleBy($user)->whereKey($agent->id)->exists(), 404);

        return ['agent' => $agent->load('connectors', 'creator')->toPublic($user, $agent->canEdit($user))];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $agent = Agent::create($data + [
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'initial' => mb_strtoupper(mb_substr($data['name'], 0, 1)),
            'created_by' => $request->user()->id,
            // Un membre crée un assistant personnel ; un administrateur peut le partager à tous
            'is_public' => $request->user()->isAdmin() ? ($data['is_public'] ?? true) : false,
        ]);
        $agent->connectors()->sync($request->input('connector_ids', []));

        return ['agent' => $agent->load('connectors', 'creator')->toPublic($request->user(), true)];
    }

    public function update(Request $request, Agent $agent)
    {
        abort_unless($agent->canEdit($request->user()), 403);
        $data = $this->validated($request, true);
        if (! $request->user()->isAdmin()) {
            unset($data['is_public']);
        }
        if (isset($data['name'])) {
            $data['initial'] = mb_strtoupper(mb_substr($data['name'], 0, 1));
        }
        $agent->update($data);
        if ($request->has('connector_ids')) {
            $agent->connectors()->sync($request->input('connector_ids', []));
        }

        return ['agent' => $agent->load('connectors', 'creator')->toPublic($request->user(), true)];
    }

    public function destroy(Request $request, Agent $agent)
    {
        abort_unless($agent->canEdit($request->user()) && ! $agent->is_default, 403);
        $agent->delete();

        return response()->noContent();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$req}|string|max:60",
            'description' => "{$req}|string|max:240",
            'system_prompt' => "{$req}|string|max:20000",
            'theme' => ['sometimes', Rule::in(array_keys(Agent::THEMES))],
            'hue' => 'sometimes|string|max:20',
            'category' => 'sometimes|string|exists:routing_rules,category',
            'starters' => 'sometimes|array|max:6',
            'starters.*' => 'string|max:120',
            'ai_model_id' => 'sometimes|nullable|integer|exists:ai_models,id',
            'is_public' => 'sometimes|boolean',
            'connector_ids' => 'sometimes|array',
            'connector_ids.*' => 'integer|exists:connectors,id',
        ]);
    }
}
