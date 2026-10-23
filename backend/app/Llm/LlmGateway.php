<?php

namespace App\Llm;

use App\Models\AiModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/** Appelle le premier modèle disponible et bascule sur le suivant en cas d'échec. */
class LlmGateway
{
    public function driver(AiModel $model): LlmDriver
    {
        return $model->driver === 'anthropic' ? app(AnthropicDriver::class) : app(OpenAiCompatibleDriver::class);
    }

    /**
     * @param  Collection<int, AiModel>  $candidates
     * @return array{0: LlmResponse, 1: AiModel}
     */
    public function chat(Collection $candidates, string $system, array $messages, array $tools, bool $advanced = false, ?callable $onText = null, ?callable $onFallback = null): array
    {
        $errors = [];
        foreach ($candidates as $model) {
            $streamed = false;
            $relay = $onText ? function (string $t) use (&$streamed, $onText) {
                $streamed = true;
                $onText($t);
            } : null;
            try {
                $resp = $this->driver($model)->chat($model, $system, $this->forModel($messages, $model), $tools, $advanced, $relay);

                return [$resp, $model];
            } catch (\Throwable $e) {
                Log::warning("Modèle {$model->name} indisponible : ".$e->getMessage());
                $errors[] = $model->name.' : '.mb_strimwidth($e->getMessage(), 0, 200, '…');
                // Si du texte a déjà été envoyé à l'écran, on ne peut pas recommencer proprement
                if ($streamed) {
                    throw new LlmException('La réponse a été interrompue ('.$model->name.').');
                }
                $onFallback && $onFallback($model, $e);
            }
        }

        throw new LlmException('Noon n\'arrive pas à réfléchir pour le moment : ses modèles d\'IA ne répondent pas. Réessayez dans un instant.', 0, implode(' · ', $errors));
    }

    /** Le contenu natif d'un fournisseur ne sert qu'à ce même fournisseur. */
    private function forModel(array $messages, AiModel $model): array
    {
        return array_map(function ($m) use ($model) {
            if ($m['role'] === 'assistant' && ($m['driver'] ?? null) !== $model->driver) {
                $m['raw'] = null;
            }

            return $m;
        }, $messages);
    }
}
