<?php

namespace App\Agents;

use App\Llm\LlmException;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Traite un message de la personne : enregistrement, réponse de l'assistant, historique. */
class ChatService
{
    public function __construct(private AgentRunner $runner) {}

    /**
     * @param  array{content: string, agent_id?: ?int, model_id?: ?int, connector_ids?: ?array, attachment_ids?: array, language?: ?string, audio_path?: ?string}  $data
     */
    public function send(User $user, ?Conversation $conv, array $data, callable $emit): Message
    {
        $agent = ! empty($data['agent_id'])
            ? Agent::accessibleBy($user)->findOrFail($data['agent_id'])
            : ($conv?->agent ?? Agent::default());

        if (! $conv) {
            $conv = Conversation::create([
                'user_id' => $user->id,
                'title' => $this->titleFrom($data['content']),
                'agent_id' => $agent->id,
                'language' => $data['language'] ?? $user->language,
                'connector_ids' => $data['connector_ids'] ?? null,
                'ai_model_id' => $data['model_id'] ?? null,
            ]);
            $emit(['type' => 'conversation', 'conversation' => $conv->toSummary()]);
        } else {
            $conv->fill(array_filter([
                'agent_id' => $agent->id,
                'language' => $data['language'] ?? null,
                'ai_model_id' => $data['model_id'] ?? null,
            ]));
            if (array_key_exists('connector_ids', $data)) {
                $conv->connector_ids = $data['connector_ids'];
            }
            $conv->save();
        }

        $attachments = ! empty($data['attachment_ids'])
            ? Attachment::where('user_id', $user->id)->whereIn('id', $data['attachment_ids'])->get()
            : collect();

        $history = $this->history($conv);

        $userMsg = $conv->messages()->create([
            'role' => 'user',
            'content' => $data['content'],
            'language' => $conv->language,
            'audio_path' => $data['audio_path'] ?? null,
            'attachments' => $attachments->map->toPublic()->values()->all(),
        ]);
        $emit(['type' => 'user_message', 'message' => $userMsg->toPublic()]);

        $assistant = $conv->messages()->create(['role' => 'assistant', 'agent_id' => $agent->id, 'status' => 'streaming', 'language' => $conv->language]);
        $emit(['type' => 'start', 'message_id' => $assistant->id, 'agent' => ['id' => $agent->id, 'name' => $agent->name, 'initial' => $agent->initial, 'hue' => $agent->hue]]);

        [$input, $images] = $this->withAttachments($data['content'], $attachments);

        try {
            $result = $this->runner->run($agent, $user, $conv, $history, $input, [
                'connector_ids' => $conv->connector_ids,
                'model_id' => $conv->ai_model_id,
                'language' => $conv->language,
                'images' => $images,
            ], $emit, $assistant->id);

            $assistant->update([
                'content' => $result->text,
                'translation' => $result->translation,
                'language' => $result->language,
                'steps' => $result->steps,
                'document' => $result->document,
                'sources' => $result->sources,
                'ai_model_id' => $result->model?->id,
                'tokens_in' => $result->tokensIn,
                'tokens_out' => $result->tokensOut,
                'status' => $result->pending ? 'waiting' : 'done',
            ]);
        } catch (LlmException $e) {
            $assistant->update(['content' => $e->messageFor($user), 'status' => 'error']);
        } catch (\Throwable $e) {
            Log::error($e);
            $assistant->update(['content' => 'Une erreur est survenue. Réessayez dans un instant.', 'status' => 'error']);
        }

        $conv->update(['last_message_at' => now()]);
        $assistant->refresh()->load('agent', 'model', 'pendingActions');
        $emit(['type' => 'done', 'message' => $assistant->toPublic(), 'conversation' => $conv->fresh()->toSummary()]);

        return $assistant;
    }

    /** Historique neutre des 20 derniers messages, traduction comprise pour le contexte. */
    public function history(Conversation $conv): array
    {
        return $conv->messages()->whereIn('status', ['done', 'waiting'])->reorder('id', 'desc')->limit(20)->get()->reverse()
            ->map(function (Message $m) {
                $content = (string) $m->content;
                if ($m->translation) {
                    $content .= "\n".AgentRunner::TRANSLATION_MARKER."\n".$m->translation;
                }

                return ['role' => $m->role === 'user' ? 'user' : 'assistant', 'content' => $content];
            })->filter(fn ($m) => trim($m['content']) !== '')->values()->all();
    }

    private function titleFrom(string $text): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $text));

        return $t === '' ? 'Nouvelle conversation' : mb_strimwidth(mb_strtoupper(mb_substr($t, 0, 1)).mb_substr($t, 1), 0, 60, '…');
    }

    /** @return array{0: string, 1: array} texte enrichi des fichiers et images en base64 */
    private function withAttachments(string $content, $attachments): array
    {
        $images = [];
        $parts = [$content];
        foreach ($attachments as $a) {
            if (str_starts_with($a->mime, 'image/')) {
                $images[] = ['mime' => $a->mime, 'data' => base64_encode(Storage::disk('local')->get($a->path))];
                $parts[] = "[Image jointe : {$a->name}]";
            } elseif ($a->extracted_text) {
                $parts[] = "[Fichier joint : {$a->name}]\n".mb_substr($a->extracted_text, 0, 60000);
            }
        }

        return [implode("\n\n", $parts), $images];
    }
}
