<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Un assistant : Noon (par défaut, généraliste) ou un assistant spécialisé. */
class Agent extends Model
{
    protected $fillable = [
        'slug', 'name', 'initial', 'hue', 'theme', 'description', 'system_prompt', 'category', 'starters',
        'ai_model_id', 'is_default', 'is_public', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['starters' => 'array', 'is_default' => 'boolean', 'is_public' => 'boolean', 'is_active' => 'boolean'];
    }

    public const THEMES = [
        'general' => 'Tout usage',
        'langue' => 'Langues',
        'culture' => 'Culture & histoire',
        'cuisine' => 'Cuisine',
        'famille' => 'Famille & cérémonies',
        'apprendre' => 'Apprendre',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function connectors(): BelongsToMany
    {
        return $this->belongsToMany(Connector::class);
    }

    public static function default(): self
    {
        return static::where('is_default', true)->firstOrFail();
    }

    /** Assistants visibles : les publics, plus ceux que la personne a créés. */
    public function scopeAccessibleBy(Builder $q, User $user): Builder
    {
        $q->where('is_active', true);
        if ($user->isAdmin()) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('is_public', true)->orWhere('created_by', $user->id));
    }

    public function canEdit(User $user): bool
    {
        return $user->isAdmin() || ($this->created_by === $user->id && ! $this->is_default);
    }

    public function toPublic(?User $viewer = null, bool $withPrompt = false): array
    {
        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'initial' => $this->initial,
            'hue' => $this->hue,
            'theme' => $this->theme,
            'theme_label' => self::THEMES[$this->theme] ?? $this->theme,
            'description' => $this->description,
            'category' => $this->category,
            'starters' => $this->starters ?? [],
            'is_default' => $this->is_default,
            'is_public' => $this->is_public,
            'connectors' => $this->connectors->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'short' => $c->shortName()])->values(),
            'ai_model_id' => $this->ai_model_id,
            'created_by' => $this->created_by,
            'author' => $this->creator?->shortName(),
            'can_edit' => $viewer ? $this->canEdit($viewer) : false,
        ];
        if ($withPrompt) {
            $data['system_prompt'] = $this->system_prompt;
        }

        return $data;
    }
}
