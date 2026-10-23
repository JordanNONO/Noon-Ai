<?php

namespace App\Llm;

use App\Models\Agent;
use App\Models\AiModel;
use App\Models\RoutingRule;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Choisit les modèles à essayer, dans l'ordre, en appliquant les « Règles de choix » :
 * les catégories sensibles (santé, RH) ne quittent jamais les serveurs de HIS.
 */
class ModelRouter
{
    /** @return Collection<int, AiModel> */
    public function candidates(?Agent $agent, ?int $explicitModelId = null, bool $advanced = false): Collection
    {
        $rule = RoutingRule::where('category', $agent?->category ?? 'general')->first();
        $localOnly = (bool) $rule?->local_only;

        $pool = AiModel::where('is_enabled', true)->orderBy('priority')->get()
            ->filter(fn (AiModel $m) => $m->isConfigured())
            ->filter(fn (AiModel $m) => ! $localOnly || $m->isLocal())
            ->values();

        if ($pool->isEmpty()) {
            throw new LlmException($localOnly
                ? "Aucun modèle hébergé chez HIS n'est activé : les données « {$rule->label} » ne peuvent pas être envoyées à une API externe. Activez un modèle « Sur nos serveurs » dans Modèles IA."
                : 'Aucun modèle IA n\'est activé et configuré. Un administrateur peut en activer un dans Modèles IA.');
        }

        $preferred = array_values(array_filter([
            $explicitModelId,
            $agent?->ai_model_id,
            $rule?->ai_model_id,
        ]));

        $sorted = $pool->sortBy(function (AiModel $m) use ($preferred, $advanced) {
            $idx = array_search($m->id, $preferred, true);
            $rank = $idx === false ? 10 : $idx;
            if ($advanced && $idx === false) {
                $rank -= $m->is_advanced ? 1 : 0;
            }

            return [$rank, $m->priority];
        })->values();

        return Setting::get('auto_fallback', true) ? $sorted : $sorted->take(1);
    }

    public function isLocalOnly(?Agent $agent): bool
    {
        return (bool) RoutingRule::where('category', $agent?->category ?? 'general')->value('local_only');
    }
}
