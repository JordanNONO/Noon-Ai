<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Combo extends Model
{
    protected $fillable = ['name', 'trigger_type', 'schedule_cron', 'schedule_label', 'is_favorite', 'created_by', 'last_run_at'];

    protected function casts(): array
    {
        return ['is_favorite' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ComboStep::class)->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function triggerLabel(): string
    {
        return $this->trigger_type === 'schedule' && $this->schedule_label ? $this->schedule_label : 'À la demande';
    }

    public function toPublic(): array
    {
        $steps = $this->steps()->with('agent')->get();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger_type' => $this->trigger_type,
            'schedule_cron' => $this->schedule_cron,
            'schedule_label' => $this->schedule_label,
            'trigger_label' => $this->triggerLabel(),
            'is_favorite' => $this->is_favorite,
            'meta' => $this->triggerLabel().' · '.$steps->pluck('agent.name')->filter()->join(', '),
            'created_by' => $this->created_by,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'steps' => $steps->map(fn (ComboStep $s) => $s->toPublic())->values(),
        ];
    }
}
