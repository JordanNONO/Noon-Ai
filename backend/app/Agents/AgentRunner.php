<?php

namespace App\Agents;

use App\Llm\LlmGateway;
use App\Llm\ModelRouter;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\KnowledgeEntry;
use App\Models\Language;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Fait répondre un assistant : prompt système, langue de réponse, savoirs validés,
 * outils, boucle d'appels d'outils, suivi des tokens.
 */
class AgentRunner
{
    /** Séparateur entre la réponse en langue bamiléké et sa traduction française. */
    public const TRANSLATION_MARKER = '---FR---';

    public function __construct(private LlmGateway $gateway, private ModelRouter $router) {}

    /**
     * @param  array  $history  messages neutres des tours précédents
     * @param  array{connector_ids?: ?array, model_id?: ?int, advanced?: bool, stream?: bool, extra_system?: string, images?: array, language?: ?string}  $opt
     * @param  callable(array): void  $emit  événements pour l'interface (step, delta, document, pending, notice)
     */
    public function run(Agent $agent, User $user, Conversation $conv, array $history, string $input, array $opt, callable $emit, ?int $messageId = null): RunResult
    {
        $belt = new ToolBelt($agent, $user, $conv, $opt['connector_ids'] ?? null);
        $tools = $belt->definitions();
        $candidates = $this->router->candidates($agent, $opt['model_id'] ?? null, $opt['advanced'] ?? false);
        $language = Language::find($opt['language'] ?? $conv->language ?? $user->language ?? 'fr') ?? Language::find('fr');

        // Savoirs validés pertinents, fournis d'office : même un modèle sans outils en profite
        $preloaded = ToolBelt::searchKnowledge($input, $language?->isBamileke() ? $language->code : null);
        $belt->sourceIds = $preloaded->pluck('id')->all();
        $system = $this->systemPrompt($agent, $user, $belt, $language, $preloaded, $opt['extra_system'] ?? '');

        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $input, 'images' => $opt['images'] ?? []];

        $result = new RunResult;
        $result->language = $language?->code ?? 'fr';
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

            if (! $resp->toolCalls || ++$rounds > config('noon.max_tool_rounds')) {
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
                    'letter' => $connector?->letter ?? (str_contains($call['name'], 'knowledge') ? 'B' : 'D'),
                    'hue' => $connector?->hue ?? 'ochre',
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

        [$result->text, $result->translation] = self::splitTranslation($result->text);

        if ($belt->sourceIds) {
            KnowledgeEntry::whereIn('id', $belt->sourceIds)->increment('uses');
            $result->sources = KnowledgeEntry::whereIn('id', $belt->sourceIds)->get()
                ->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'type' => $e->type, 'language' => $e->language])->values()->all();
        }
        $result->connectorsUsed = collect($result->steps)->pluck('label')->map(fn ($l) => Str::before($l, ' — '))
            ->unique()->reject(fn ($n) => in_array($n, ['Document', 'Bibliothèque des savoirs']))->values()->all();

        return $result;
    }

    /** @return array{0: string, 1: ?string} réponse et traduction française éventuelle */
    public static function splitTranslation(string $text): array
    {
        if (! str_contains($text, self::TRANSLATION_MARKER)) {
            return [trim($text), null];
        }
        [$main, $fr] = explode(self::TRANSLATION_MARKER, $text, 2);

        return [trim($main), trim($fr) ?: null];
    }

    public function systemPrompt(Agent $agent, User $user, ToolBelt $belt, ?Language $language, $preloaded, string $extra = ''): string
    {
        $now = now('Africa/Douala');
        $connectors = $belt->connectors->map(fn ($c) => '- '.$c->name.' : '.$c->description)->join("\n") ?: '- aucun';
        $knowledge = $preloaded->isEmpty() ? '(aucun savoir validé ne correspond directement à la demande)'
            : $preloaded->map(fn ($e) => json_encode($e->forModel(), JSON_UNESCAPED_UNICODE))->join("\n");
        $langRules = $this->languageRules($user, $language);
        $large = $user->large_text ? "\n- La personne préfère des réponses très courtes et très simples." : '';

        return trim(<<<TXT
{$agent->system_prompt}

---
Contexte Noon IA.
Vous êtes « {$agent->name} ». Nous sommes le {$now->translatedFormat('l j F Y')}, il est {$now->format('H:i')} (heure du Cameroun).
Vous parlez avec {$user->firstName()}.

Langue de la conversation :
{$langRules}

Savoirs validés par la communauté, pertinents pour cette demande (à privilégier et à citer) :
{$knowledge}

Connecteurs disponibles :
{$connectors}

Règles :
- Parlez simplement, avec chaleur et respect, comme à quelqu'un de la famille. Pas de jargon. Phrases courtes.
- Mise en forme légère : quelques listes si utile, pas de tableaux compliqués.
- Pour la langue et la culture bamiléké, appuyez-vous d'abord sur la Bibliothèque des savoirs (outil search_knowledge). Si vous n'êtes pas sûr d'un mot, d'une coutume ou d'un fait, dites-le franchement au lieu d'inventer, et indiquez que les usages varient d'un village à l'autre.
- N'inventez ni chiffres, ni noms, ni dates.
- Santé : donnez des conseils généraux prudents et recommandez toujours de consulter un médecin ou un centre de santé pour un problème sérieux ; en cas d'urgence, dites d'appeler les secours immédiatement.
- Toute action qui envoie ou publie quelque chose attend l'accord de la personne : n'affirmez jamais qu'elle est faite tant que l'outil ne l'a pas confirmé.
- Pour un long texte (lettre, discours, programme), utilisez l'outil create_document puis résumez en quelques lignes.{$large}
{$extra}
TXT);
    }

    private function languageRules(User $user, ?Language $language): string
    {
        if (! $language || $language->code === 'fr') {
            return "- Répondez en français. Si la personne écrit dans une autre langue, répondez dans sa langue.";
        }
        if (! $language->isBamileke()) {
            return "- Répondez en {$language->name}.";
        }
        $native = $language->native_name ? " ({$language->native_name})" : '';
        $rules = "- La personne parle {$language->name}{$native}. Répondez en {$language->name}, avec des phrases simples et courantes, en vous aidant des savoirs validés ci-dessus et de l'outil search_knowledge.\n"
            ."- Votre maîtrise de cette langue est limitée : préférez des formulations simples et sûres, et ne fabriquez pas de mots.";
        if ($user->bilingual_replies) {
            $rules .= "\n- Après votre réponse en {$language->name}, écrivez sur une ligne seule exactement ".self::TRANSLATION_MARKER." puis la même réponse en français.";
        } else {
            $rules .= "\n- Si vous n'arrivez pas à exprimer une idée en {$language->name}, ajoutez-la en français.";
        }

        return $rules;
    }

    private function record(User $user, Agent $agent, AiModel $model, Conversation $conv, int $in, int $out): void
    {
        UsageLog::create([
            'user_id' => $user->id,
            'group_id' => $user->group_id,
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
