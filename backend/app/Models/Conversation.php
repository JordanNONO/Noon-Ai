<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['user_id', 'title', 'agent_id', 'combo_id', 'language', 'is_pinned', 'connector_ids', 'ai_model_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'connector_ids' => 'array', 'last_message_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class);
    }

    public function toSummary(): array
    {
        $last = $this->messages()->where('role', 'assistant')->reorder('id', 'desc')->with('agent')->first();
        $snippet = '';
        if ($last) {
            $snippet = mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags((string) $last->content))), 0, 90, '…');
            if ($last->agent && ! $last->agent->is_default) {
                $snippet = $last->agent->name.' : '.$snippet;
            }
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'is_pinned' => $this->is_pinned,
            'language' => $this->language,
            'agent' => $this->agent ? ['id' => $this->agent->id, 'name' => $this->agent->name, 'initial' => $this->agent->initial, 'hue' => $this->agent->hue] : null,
            'snippet' => $snippet,
            'last_message_at' => ($this->last_message_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
