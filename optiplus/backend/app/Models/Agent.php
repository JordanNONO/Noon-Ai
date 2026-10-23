<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Agent extends Model
{
    protected $fillable = [
        'slug', 'name', 'initial', 'hue', 'service_id', 'description', 'system_prompt', 'category',
        'ai_model_id', 'is_orchestrator', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_orchestrator' => 'boolean', 'is_active' => 'boolean'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    /** Services autorisés à utiliser l'agent (matrice d'administration). */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    public function connectors(): BelongsToMany
    {
        return $this->belongsToMany(Connector::class);
    }

    /** Agents qu'un membre peut utiliser : ceux ouverts à son service (tous pour un admin). */
    public function scopeAccessibleBy(Builder $q, User $user): Builder
    {
        $q->where('is_active', true);
        if ($user->isAdmin()) {
            return $q;
        }

        return $q->whereHas('services', fn ($s) => $s->where('services.id', $user->service_id));
    }

    public function toPublic(bool $withPrompt = false): array
    {
        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'initial' => $this->initial,
            'hue' => $this->hue,
            'description' => $this->description,
            'category' => $this->category,
            'is_orchestrator' => $this->is_orchestrator,
            'service' => $this->service ? ['id' => $this->service->id, 'name' => $this->service->name] : null,
            'connectors' => $this->connectors->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'short' => $c->shortName()])->values(),
            'service_ids' => $this->services->pluck('id')->values(),
            'ai_model_id' => $this->ai_model_id,
            'created_by' => $this->created_by,
        ];
        if ($withPrompt) {
            $data['system_prompt'] = $this->system_prompt;
        }

        return $data;
    }
}
