<?php

namespace App\Agents;

use App\Llm\LlmGateway;
use App\Llm\ModelRouter;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Fait travailler un agent : prompt système, outils de ses connecteurs,
 * boucle d'appels d'outils, suivi des tokens.
 */
class AgentRunner
{
    public function __construct(private LlmGateway $gateway, private ModelRouter $router) {}

    /**
     * @param  array  $history  messages neutres des tours précédents
     * @param  array{connector_ids?: ?array, model_id?: ?int, advanced?: bool, all_connectors?: bool, stream?: bool, extra_system?: string, images?: array}  $opt
     * @param  callable(array): void  $emit  événements pour l'interface (step, delta, document, pending)
     */
    public function run(Agent $agent, User $user, Conversation $conv, array $history, string $input, array $opt, callable $emit, ?int $messageId = null): RunResult
    {
        $belt = new ToolBelt($agent, $user, $conv, $opt['connector_ids'] ?? null, $opt['all_connectors'] ?? false);
        $tools = $belt->definitions();
        $candidates = $this->router->candidates($agent, $opt['model_id'] ?? null, $opt['advanced'] ?? false);
        $system = $this->systemPrompt($agent, $user, $belt, $opt['extra_system'] ?? '');

        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $input, 'images' => $opt['images'] ?? []];

        $result = new RunResult;
        $stream = $opt['stream'] ?? true;
        $rounds = 0;
        $model = null;

        while (true) {
            $onText = $stream ? function (string $t) use ($emit, $agent) {
                $emit(['type' => 'delta', 'text' => $t, 'agent_id' => $agent->id]);
            } : null;
            $onFallback = function (AiModel $m) use ($emit) {
                $emit(['type' => 'notice', 'text' => "{$m->name} indisponible, bascule sur le modèle suivant…"]);
            };

            // Une fois un modèle choisi, on le garde pendant toute la boucle d'outils
            [$resp, $model] = $this->gateway->chat($model ? collect([$model]) : $candidates, $system, $messages, $tools, $opt['advanced'] ?? false, $onText, $onFallback);
            $result->model = $model;
            $result->tokensIn += $resp->tokensIn;
            $result->tokensOut += $resp->tokensOut;
            $this->record($user, $agent, $model, $conv, $resp->tokensIn, $resp->tokensOut);

            if (! $resp->toolCalls || ++$rounds > config('optiplus.max_tool_rounds')) {
                $result->text .= $resp->text;
                break;
            }

            if ($resp->text !== '') {
                $result->text .= $resp->text."\n\n";
                $stream && $emit(['type' => 'delta', 'text' => "\n\n", 'agent_id' => $agent->id]);
            }
            $messages[] = ['role' => 'assistant', 'content' => $resp->text, 'tool_calls' => $resp->toolCalls, 'raw' => $resp->raw, 'driver' => $model->driver];

            $results = [];
            foreach ($resp->toolCalls as $call) {
                $connector = $belt->connectorFor($call['name']);
                $step = [
                    'id' => (string) Str::uuid(),
                    'letter' => $connector?->letter ?? 'D',
                    'hue' => $connector?->hue ?? 'sage',
                    'label' => $belt->label($call['name'], $call['args']),
                    'status' => 'En cours',
                    'state' => 'running',
                ];
                $emit(['type' => 'step', 'step' => $step]);

                $out = $belt->call($call['name'], $call['args'], $messageId);
                $step['status'] = match ($out['status']) {
                    'done' => 'Terminé',
                    'pending' => 'En attente',
                    default => 'Échec',
                };
                $step['state'] = $out['status'] === 'done' ? 'done' : ($out['status'] === 'pending' ? 'pending' : 'error');
                if ($out['status'] === 'pending') {
                    $step['label'] .= ' (attend votre accord)';
                    $step['pending_id'] = $out['pending']->id;
                    $result->pending[] = $out['pending'];
                    $emit(['type' => 'pending', 'action' => $out['pending']->toPublic()]);
                }
                $emit(['type' => 'step', 'step' => $step]);
                $result->steps[] = $step;
                if ($belt->document) {
                    $result->document = $belt->document;
                    $emit(['type' => 'document', 'document' => $belt->document]);
                }
                $results[] = ['id' => $call['id'], 'name' => $call['name'], 'content' => $out['content'], 'is_error' => $out['status'] === 'error'];
            }
            $messages[] = ['role' => 'tool', 'results' => $results];
        }

        $result->connectorsUsed = collect($result->steps)->pluck('label')->map(fn ($l) => Str::before($l, ' — '))->unique()->reject(fn ($n) => $n === 'Document')->values()->all();

        return $result;
    }

    public function systemPrompt(Agent $agent, User $user, ToolBelt $belt, string $extra = ''): string
    {
        $now = now('Europe/Paris');
        $connectors = $belt->connectors->map(fn ($c) => '- '.$c->name.' : '.$c->description)->join("\n") ?: '- aucun connecteur disponible pour cette demande';
        $local = $this->router->isLocalOnly($agent) ? "\nLes données traitées sont sensibles : elles restent sur les serveurs de HIS." : '';

        return trim(<<<TXT
{$agent->system_prompt}

---
Contexte OptiPlus (plateforme interne d'agents IA de {$this->workspace()}).
Vous êtes l'agent « {$agent->name} ». Nous sommes le {$now->translatedFormat('l j F Y')}, il est {$now->format('H:i')} (heure de Paris).
Vous travaillez pour {$user->name}{$this->serviceLabel($user)}.

Connecteurs disponibles :
{$connectors}

Règles :
- Répondez en français, de façon claire et concise, en Markdown.
- Toute action qui envoie, publie, modifie ou exécute quelque chose peut être soumise à l'accord de l'utilisateur : n'affirmez jamais qu'elle est faite tant que l'outil ne l'a pas confirmé.
- N'inventez ni chiffres, ni noms, ni dates : si une information manque, signalez-la entre crochets, par exemple [DATE À CONFIRMER].
- Pour un livrable long (brouillon, rapport, newsletter), utilisez l'outil create_document puis résumez en quelques lignes.{$local}
{$extra}
TXT);
    }

    private function workspace(): string
    {
        return config('optiplus.workspace');
    }

    private function serviceLabel(User $user): string
    {
        return $user->service ? " (pôle {$user->service->name})" : '';
    }

    private function record(User $user, Agent $agent, AiModel $model, Conversation $conv, int $in, int $out): void
    {
        UsageLog::create([
            'user_id' => $user->id,
            'service_id' => $user->service_id,
            'agent_id' => $agent->id,
            'ai_model_id' => $model->id,
            'conversation_id' => $conv->id,
            'tokens_in' => $in,
            'tokens_out' => $out,
            'cost' => $model->cost($in, $out),
            'created_at' => now(),
        ]);
    }
}
