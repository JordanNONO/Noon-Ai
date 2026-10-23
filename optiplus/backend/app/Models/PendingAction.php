<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingAction extends Model
{
    protected $fillable = ['conversation_id', 'message_id', 'user_id', 'agent_id', 'connector_id', 'combo_run_id', 'kind', 'tool', 'payload', 'summary', 'status', 'result'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array'];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function comboRun(): BelongsTo
    {
        return $this->belongsTo(ComboRun::class);
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'tool' => $this->tool,
            'summary' => $this->summary,
            'payload' => $this->payload,
            'status' => $this->status,
            'connector' => $this->connector?->name,
            'agent' => $this->agent?->name,
            'result' => $this->result,
        ];
    }
}
