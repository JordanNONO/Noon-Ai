<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboStep extends Model
{
    protected $fillable = ['combo_id', 'position', 'agent_id', 'task', 'prompt', 'model_tier', 'handoff', 'requires_validation', 'connector_ids'];

    protected function casts(): array
    {
        return ['requires_validation' => 'boolean', 'connector_ids' => 'array'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function toPublic(): array
    {
        $ids = $this->connector_ids ?? [];
        $connectors = $ids ? Connector::whereIn('id', $ids)->get() : collect();

        return [
            'id' => $this->id,
            'position' => $this->position,
            'agent' => $this->agent ? ['id' => $this->agent->id, 'name' => $this->agent->name, 'initial' => $this->agent->initial, 'hue' => $this->agent->hue] : null,
            'agent_id' => $this->agent_id,
            'task' => $this->task,
            'prompt' => $this->prompt,
            'model_tier' => $this->model_tier,
            'handoff' => $this->handoff,
            'requires_validation' => $this->requires_validation,
            'connector_ids' => $ids,
            'connectors' => $connectors->map(fn ($c) => $c->shortName())->values(),
        ];
    }
}
