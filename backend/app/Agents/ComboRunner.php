<?php

namespace App\Agents;

use App\Llm\LlmException;
use App\Models\Combo;
use App\Models\ComboRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Exécute un parcours : les assistants s'enchaînent, chacun reçoit le travail du précédent.
 * Une étape « validation humaine » met le parcours en pause jusqu'à l'accord de la personne.
 */
class ComboRunner
{
    public function __construct(private AgentRunner $runner) {}

    public function start(Combo $combo, User $user, ?string $input, callable $emit): Message
    {
        $conv = Conversation::create([
            'user_id' => $user->id,
            'title' => $combo->name,
            'agent_id' => $combo->steps()->first()?->agent_id,
            'combo_id' => $combo->id,
            'language' => $user->language,
            'last_message_at' => now(),
        ]);
        $emit(['type' => 'conversation', 'conversation' => $conv->toSummary()]);
        $conv->messages()->create(['role' => 'user', 'content' => $input ?: 'Lancer le parcours « '.$combo->name.' »']);

        $run = ComboRun::create(['combo_id' => $combo->id, 'user_id' => $user->id, 'conversation_id' => $conv->id, 'status' => 'running', 'outputs' => [], 'input' => $input]);
        $combo->update(['last_run_at' => now()]);

        return $this->continue($run, $emit);
    }

    public function continue(ComboRun $run, callable $emit): Message
    {
        $combo = $run->combo;
        $steps = $combo->steps()->with('agent')->get()->values();
        $conv = $run->conversation;
        $user = $run->user;
        $outputs = $run->outputs ?? [];

        $msg = $conv->messages()->create(['role' => 'assistant', 'agent_id' => $steps[$run->current_step]->agent_id ?? null, 'status' => 'streaming', 'steps' => []]);
        $emit(['type' => 'start', 'message_id' => $msg->id]);
        $cards = [];
        $document = null;
        $tokensIn = $tokensOut = 0;
        $modelId = null;

        try {
            for ($i = $run->current_step; $i < $steps->count(); $i++) {
                $step = $steps[$i];
                $card = ['id' => 'step-'.$step->id, 'letter' => $step->agent->initial, 'hue' => $step->agent->hue, 'label' => "Étape {$step->position} · {$step->agent->name} — {$step->task}", 'status' => 'En cours', 'state' => 'running'];
                $emit(['type' => 'step', 'step' => $card]);

                $res = $this->runner->run($step->agent, $user, $conv, [], $this->stepInput($step, $run->input, $outputs), [
                    'connector_ids' => $step->connector_ids ?: null,
                    'advanced' => $step->model_tier === 'advanced',
                    'stream' => false,
                    'language' => $conv->language,
                    'extra_system' => "Vous êtes l'étape {$step->position} du parcours « {$combo->name} ». Produisez directement le résultat demandé, sans poser de question.",
                ], fn ($e) => in_array($e['type'], ['step', 'pending', 'notice']) ? $emit($e) : null, $msg->id);

                $outputs[$i] = ['agent' => $step->agent->name, 'text' => $res->text, 'translation' => $res->translation, 'document' => $res->document];
                $document = $res->document ?? $document;
                $tokensIn += $res->tokensIn;
                $tokensOut += $res->tokensOut;
                $modelId = $res->model?->id;
                $card['status'] = 'Terminé';
                $card['state'] = 'done';
                $cards[] = $card;
                $cards = array_merge($cards, $res->steps);
                $emit(['type' => 'step', 'step' => $card]);

                $run->update(['outputs' => $outputs, 'current_step' => $i + 1]);

                if ($step->requires_validation && $i < $steps->count() - 1) {
                    $next = $steps[$i + 1];
                    $gate = PendingAction::create([
                        'conversation_id' => $conv->id, 'message_id' => $msg->id, 'user_id' => $user->id, 'agent_id' => $step->agent_id,
                        'combo_run_id' => $run->id, 'kind' => 'combo_gate',
                        'summary' => "Valider le travail de {$step->agent->name} avant de passer à {$next->agent->name}",
                        'status' => 'pending',
                    ]);
                    $cards[] = ['id' => 'gate-'.$gate->id, 'letter' => $next->agent->initial, 'hue' => $next->agent->hue, 'label' => "Étape {$next->position} · {$next->agent->name} — attend votre validation", 'status' => 'En attente', 'state' => 'pending', 'pending_id' => $gate->id];
                    $run->update(['status' => 'waiting']);
                    $emit(['type' => 'pending', 'action' => $gate->toPublic()]);

                    return $this->finish($msg, $res->text, $res->translation, $cards, $document, $tokensIn, $tokensOut, $modelId, 'waiting', $emit);
                }
            }
            $run->update(['status' => 'done']);
            $last = end($outputs) ?: ['text' => '', 'translation' => null];

            return $this->finish($msg, $last['text'], $last['translation'], $cards, $document, $tokensIn, $tokensOut, $modelId, 'done', $emit);
        } catch (\Throwable $e) {
            Log::warning($e);
            $run->update(['status' => 'failed']);
            $text = $e instanceof LlmException ? $e->getMessage() : 'Le parcours s\'est interrompu. Réessayez dans un instant.';

            return $this->finish($msg, $text, null, $cards, $document, $tokensIn, $tokensOut, $modelId, 'error', $emit);
        }
    }

    private function stepInput($step, ?string $input, array $outputs): string
    {
        $parts = [$step->prompt];
        if ($input) {
            $parts[] = "Demande de départ : {$input}";
        }
        $prev = end($outputs);
        if ($prev) {
            $text = $prev['document']['body'] ?? $prev['text'];
            $parts[] = match ($step->handoff) {
                'summary' => "Résumé du travail de l'étape précédente ({$prev['agent']}) :\n".mb_strimwidth($text, 0, 1500, '…'),
                'files' => $prev['document'] ? "Document produit à l'étape précédente : {$prev['document']['title']}\n{$prev['document']['body']}" : "Résultat de l'étape précédente :\n{$prev['text']}",
                default => "Résultat complet de l'étape précédente ({$prev['agent']}) :\n{$text}",
            };
        }

        return implode("\n\n", $parts);
    }

    private function finish(Message $msg, string $text, ?string $translation, array $cards, ?array $doc, int $in, int $out, ?int $modelId, string $status, callable $emit): Message
    {
        $msg->update(['content' => $text, 'translation' => $translation, 'steps' => $cards, 'document' => $doc, 'tokens_in' => $in, 'tokens_out' => $out, 'ai_model_id' => $modelId, 'status' => $status]);
        $msg->conversation->update(['last_message_at' => now()]);
        $msg->refresh()->load('agent', 'model', 'pendingActions');
        $emit(['type' => 'done', 'message' => $msg->toPublic(), 'conversation' => $msg->conversation->toSummary()]);

        return $msg;
    }
}
