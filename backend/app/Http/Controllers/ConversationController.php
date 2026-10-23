<?php

namespace App\Http\Controllers;

use App\Agents\ChatService;
use App\Http\Controllers\Concerns\StreamsEvents;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    use StreamsEvents;

    public function index(Request $request)
    {
        $q = $request->user()->conversations()->with('agent')->orderByDesc('last_message_at')->orderByDesc('id');
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")
                ->orWhereHas('messages', fn ($m) => $m->where('content', 'like', "%{$s}%")));
        }

        return ['conversations' => $q->limit(100)->get()->map->toSummary()->values()];
    }

    public function show(Request $request, Conversation $conversation)
    {
        $this->own($request, $conversation);
        $conversation->load(['agent.connectors', 'messages.agent', 'messages.model', 'messages.pendingActions']);

        return [
            'conversation' => $conversation->toSummary() + [
                'connector_ids' => $conversation->connector_ids,
                'ai_model_id' => $conversation->ai_model_id,
                'combo_id' => $conversation->combo_id,
            ],
            'agent' => $conversation->agent?->toPublic($request->user()),
            'messages' => $conversation->messages->map->toPublic()->values(),
        ];
    }

    public function update(Request $request, Conversation $conversation)
    {
        $this->own($request, $conversation);
        $conversation->update($request->validate([
            'title' => 'sometimes|string|max:120',
            'is_pinned' => 'sometimes|boolean',
        ]));

        return ['conversation' => $conversation->toSummary()];
    }

    public function destroy(Request $request, Conversation $conversation)
    {
        $this->own($request, $conversation);
        $conversation->delete();

        return response()->noContent();
    }

    /** Nouveau message, dans une conversation existante ou nouvelle. Réponse en flux. */
    public function send(Request $request, ChatService $chat, ?Conversation $conversation = null)
    {
        if ($conversation) {
            $this->own($request, $conversation);
        }
        $data = $request->validate([
            'content' => 'required|string|max:100000',
            'agent_id' => 'nullable|integer|exists:agents,id',
            'model_id' => 'nullable|integer|exists:ai_models,id',
            'connector_ids' => 'nullable|array',
            'connector_ids.*' => 'integer',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'integer',
            'language' => ['nullable', Rule::exists('languages', 'code')],
            'audio_token' => 'nullable|string|max:120',
        ]);
        if (! empty($data['audio_token'])) {
            $path = 'voice/'.$request->user()->id.'/'.basename($data['audio_token']);
            $data['audio_path'] = Storage::disk('local')->exists($path) ? $path : null;
        }
        $user = $request->user();

        return $this->sse(fn ($emit) => $chat->send($user, $conversation, $data, $emit));
    }

    /** Refaire la dernière réponse. */
    public function regenerate(Request $request, ChatService $chat, Message $message)
    {
        $conv = $message->conversation;
        $this->own($request, $conv);
        abort_unless($message->role === 'assistant', 422);
        $userMsg = $conv->messages()->where('role', 'user')->where('id', '<', $message->id)->reorder('id', 'desc')->firstOrFail();
        $data = ['content' => $userMsg->content, 'agent_id' => $message->agent_id];
        $message->delete();
        $userMsg->delete();
        $user = $request->user();

        return $this->sse(fn ($emit) => $chat->send($user, $conv, $data, $emit));
    }

    public function feedback(Request $request, Message $message)
    {
        $this->own($request, $message->conversation);
        $message->update($request->validate(['feedback' => 'nullable|in:up,down']));

        return ['ok' => true];
    }

    public function audio(Request $request, Message $message)
    {
        $this->own($request, $message->conversation);
        abort_unless($message->audio_path && Storage::disk('local')->exists($message->audio_path), 404);

        return response()->file(Storage::disk('local')->path($message->audio_path));
    }

    private function own(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
    }
}
