<?php

namespace App\Http\Controllers;

use App\Llm\LlmGateway;
use App\Models\AiModel;
use App\Models\RoutingRule;
use App\Models\Setting;
use Illuminate\Http\Request;

class ModelController extends Controller
{
    public function index(Request $request)
    {
        $models = AiModel::orderBy('priority')->get();

        return [
            'families' => collect(AiModel::FAMILIES)->map(fn ($f, $k) => $f + [
                'key' => $k,
                'count' => $models->where('family', $k)->count(),
                'enabled' => $models->where('family', $k)->where('is_enabled', true)->count(),
            ])->values(),
            'models' => $models->map->toPublic()->values(),
            'rules' => RoutingRule::with('model')->orderBy('sort')->get()->map->toPublic()->values(),
            'auto_fallback' => (bool) Setting::get('auto_fallback', true),
            'can_manage' => $request->user()->isAdmin(),
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['letter'] ??= mb_strtoupper(mb_substr($data['name'], 0, 1));
        $model = AiModel::create($data);

        return ['model' => $model->toPublic()];
    }

    public function update(Request $request, AiModel $model)
    {
        $data = $this->validated($request, true);
        if (array_key_exists('api_key', $data) && ($data['api_key'] === null || $data['api_key'] === '')) {
            unset($data['api_key']); // champ vide = clé inchangée
        }
        $model->update($data);

        return ['model' => $model->fresh()->toPublic()];
    }

    public function destroy(AiModel $model)
    {
        $model->delete();

        return response()->noContent();
    }

    /** Petit essai réel du modèle. */
    public function test(AiModel $model, LlmGateway $gateway)
    {
        try {
            $started = microtime(true);
            [$resp] = $gateway->chat(collect([$model]), 'Répondez en une phrase courte, en français.', [['role' => 'user', 'content' => 'Dites bonjour à la famille.']], []);

            return ['ok' => true, 'reply' => $resp->text, 'ms' => (int) ((microtime(true) - $started) * 1000)];
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function updateRule(Request $request, RoutingRule $rule)
    {
        $rule->update($request->validate([
            'ai_model_id' => 'nullable|integer|exists:ai_models,id',
            'local_only' => 'sometimes|boolean',
        ]));

        return ['rule' => $rule->load('model')->toPublic()];
    }

    public function updateFallback(Request $request)
    {
        Setting::put('auto_fallback', (bool) $request->validate(['auto_fallback' => 'required|boolean'])['auto_fallback']);

        return ['auto_fallback' => (bool) Setting::get('auto_fallback', true)];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$req}|string|max:80",
            'provider_label' => "{$req}|string|max:80",
            'family' => "{$req}|in:local,free,paid",
            'driver' => "{$req}|in:ollama,openai,anthropic",
            'model_name' => "{$req}|string|max:120",
            'base_url' => 'sometimes|nullable|url|max:255',
            'api_key' => 'sometimes|nullable|string|max:500',
            'api_key_env' => 'sometimes|nullable|string|max:80',
            'letter' => 'sometimes|string|max:2',
            'hue' => 'sometimes|string|max:20',
            'description' => 'sometimes|nullable|string|max:300',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:30',
            'warning' => 'sometimes|nullable|string|max:200',
            'is_enabled' => 'sometimes|boolean',
            'supports_tools' => 'sometimes|boolean',
            'supports_vision' => 'sometimes|boolean',
            'is_advanced' => 'sometimes|boolean',
            'cost_in_per_mtok' => 'sometimes|numeric|min:0',
            'cost_out_per_mtok' => 'sometimes|numeric|min:0',
            'priority' => 'sometimes|integer|min:0|max:1000',
        ]);
    }
}
