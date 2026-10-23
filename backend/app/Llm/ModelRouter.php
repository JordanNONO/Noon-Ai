<?php

namespace App\Llm;

use App\Billing\Quota;
use App\Models\Agent;
use App\Models\User;
use App\Models\AiModel;
use App\Models\RoutingRule;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Choisit les modèles à essayer, dans l'ordre, en appliquant les « Règles de choix ».
 * Une règle « locale uniquement » garde la conversation sur les serveurs de Noon.
 */
class ModelRouter
{
    /** @return Collection<int, AiModel> */
    public function candidates(?Agent $agent, ?int $explicitModelId = null, bool $advanced = false, ?User $user = null): Collection
    {
        $rule = RoutingRule::where('category', $agent?->category ?? 'general')->first();
        $localOnly = (bool) $rule?->local_only;
        // Les modèles payants sont réservés aux offres qui les incluent
        $premium = ! $user || $user->isAdmin() || Quota::plan($user)->premium_models;

        $pool = AiModel::where('is_enabled', true)->orderBy('priority')->get()
            ->filter(fn (AiModel $m) => $m->isConfigured())
            ->filter(fn (AiModel $m) => ! $localOnly || $m->isLocal())
            ->filter(fn (AiModel $m) => $premium || $m->family !== 'paid')
            ->values();

        if ($pool->isEmpty()) {
            throw new LlmException($localOnly
                ? "Aucun modèle « Sur nos serveurs » n'est activé : la règle « {$rule->label} » interdit d'utiliser une API externe. Un administrateur peut en activer un dans Modèles IA."
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
            if ($advanced && $idx === false && $m->is_advanced) {
                $rank -= 1;
            }

            return [$rank, $m->priority];
        })->values();

        return Setting::get('auto_fallback', true) ? $sorted : $sorted->take(1);
    }
}
