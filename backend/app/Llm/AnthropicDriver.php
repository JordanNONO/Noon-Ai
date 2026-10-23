<?php

namespace App\Llm;

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use App\Models\AiModel;

/** Modèles Claude via le SDK PHP officiel d'Anthropic (streaming + outils). */
class AnthropicDriver implements LlmDriver
{
    public function chat(AiModel $model, string $system, array $messages, array $tools, bool $advanced, ?callable $onText): LlmResponse
    {
        $client = new Client(apiKey: $model->resolvedApiKey(), baseUrl: $model->base_url ?: null);

        $params = [
            'model' => $model->model_name,
            'maxTokens' => 32000,
            'system' => $system,
            'messages' => $this->convert($messages),
            // L'effort règle la profondeur de réflexion ; « Avancé » dans un combo l'augmente
            'outputConfig' => ['effort' => $advanced ? 'high' : 'medium'],
            // Si Claude décline une demande, l'API relance automatiquement sur un modèle de repli
            'betas' => ['server-side-fallback-2026-07-01'],
            'fallbacks' => 'default',
        ];
        if ($tools) {
            $params['tools'] = array_map(fn ($t) => [
                'name' => $t['name'],
                'description' => $t['description'],
                'inputSchema' => $t['parameters'],
                'eagerInputStreaming' => true,
            ], $tools);
        }

        $stream = $client->beta->messages->createStream(...$params);
        $acc = MessageAccumulator::forBetaMessages();
        foreach ($stream as $event) {
            $acc->accumulate($event);
            if ($onText && $event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                $onText($event->delta->text);
            }
        }
        $msg = $acc->message();

        $text = '';
        $calls = [];
        foreach ($msg->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $calls[] = ['id' => $block->id, 'name' => $block->name, 'args' => is_array($block->input) ? $block->input : (array) $block->input];
            }
        }
        if ($msg->stopReason === 'refusal') {
            $text = $text ?: 'Cette demande a été refusée par le modèle. Reformulez-la ou choisissez un autre modèle.';
            $calls = [];
        }
        // Sortie coupée par la limite : on n'exécute pas un outil dont les arguments sont peut-être incomplets
        if ($msg->stopReason === 'max_tokens') {
            $calls = [];
        }

        return new LlmResponse(
            text: $text,
            toolCalls: $calls,
            tokensIn: (int) $msg->usage->inputTokens + (int) ($msg->usage->cacheReadInputTokens ?? 0) + (int) ($msg->usage->cacheCreationInputTokens ?? 0),
            tokensOut: (int) $msg->usage->outputTokens,
            stopReason: $msg->stopReason,
            raw: $msg->content,
        );
    }

    private function convert(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'tool') {
                $out[] = ['role' => 'user', 'content' => array_map(fn ($r) => [
                    'type' => 'tool_result', 'toolUseID' => $r['id'], 'content' => $r['content'], 'isError' => (bool) ($r['is_error'] ?? false),
                ], $m['results'])];

                continue;
            }
            if ($m['role'] === 'assistant') {
                // Contenu natif (blocs de réflexion compris) renvoyé tel quel pendant la boucle d'outils
                if (($m['driver'] ?? null) === 'anthropic' && $m['raw'] !== null) {
                    $out[] = ['role' => 'assistant', 'content' => $m['raw']];
                } else {
                    $blocks = [];
                    if (trim((string) $m['content']) !== '') {
                        $blocks[] = ['type' => 'text', 'text' => $m['content']];
                    }
                    foreach ($m['tool_calls'] ?? [] as $c) {
                        $blocks[] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => (object) $c['args']];
                    }
                    $out[] = ['role' => 'assistant', 'content' => $blocks ?: [['type' => 'text', 'text' => '…']]];
                }

                continue;
            }
            $content = [];
            foreach ($m['images'] ?? [] as $img) {
                $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $img['mime'], 'data' => $img['data']]];
            }
            $content[] = ['type' => 'text', 'text' => (string) $m['content']];
            $out[] = ['role' => 'user', 'content' => $content];
        }

        return $out;
    }
}
