<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $fillable = ['conversation_id', 'role', 'agent_id', 'content', 'steps', 'document', 'attachments', 'ai_model_id', 'tokens_in', 'tokens_out', 'status', 'feedback'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'document' => 'array', 'attachments' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class);
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'agent' => $this->agent ? ['id' => $this->agent->id, 'name' => $this->agent->name, 'initial' => $this->agent->initial, 'hue' => $this->agent->hue] : null,
            'content' => $this->content,
            'steps' => $this->steps ?? [],
            'document' => $this->document,
            'attachments' => $this->attachments ?? [],
            'model' => $this->model?->name,
            'tokens' => $this->tokens_in + $this->tokens_out,
            'status' => $this->status,
            'feedback' => $this->feedback,
            'pending_actions' => $this->pendingActions->map(fn ($p) => $p->toPublic())->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
