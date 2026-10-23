<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    protected $fillable = [
        'name', 'provider_label', 'family', 'driver', 'base_url', 'api_key', 'api_key_env', 'model_name',
        'letter', 'hue', 'description', 'tags', 'warning', 'is_enabled', 'supports_tools', 'supports_vision',
        'is_advanced', 'cost_in_per_mtok', 'cost_out_per_mtok', 'priority',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'tags' => 'array',
            'is_enabled' => 'boolean',
            'supports_tools' => 'boolean',
            'supports_vision' => 'boolean',
            'is_advanced' => 'boolean',
            'cost_in_per_mtok' => 'float',
            'cost_out_per_mtok' => 'float',
        ];
    }

    public const FAMILIES = [
        'local' => ['title' => 'Sur nos serveurs', 'label' => 'Nos serveurs · gratuit', 'desc' => 'Modèles ouverts installés chez HIS. Gratuits, sans limite, les données ne sortent pas.'],
        'free' => ['title' => 'API gratuites', 'label' => 'API gratuite · limitée', 'desc' => 'Offres gratuites des fournisseurs. Pratiques, mais avec des limites par jour.'],
        'paid' => ['title' => 'API payantes (option)', 'label' => 'API payante', 'desc' => 'Pour les tâches lourdes uniquement. Désactivées par défaut.'],
    ];

    public function resolvedApiKey(): ?string
    {
        return $this->api_key ?: ($this->api_key_env ? env($this->api_key_env) : null);
    }

    public function resolvedBaseUrl(): string
    {
        if ($this->base_url) {
            return rtrim($this->base_url, '/');
        }

        return match ($this->driver) {
            'ollama' => rtrim((string) config('optiplus.ollama_url'), '/'),
            'anthropic' => 'https://api.anthropic.com',
            default => rtrim((string) (config('optiplus.openai_compat_url') ?: 'https://api.openai.com/v1'), '/'),
        };
    }

    /** Un modèle local ne fait jamais sortir les données de HIS. */
    public function isLocal(): bool
    {
        return $this->family === 'local';
    }

    public function isConfigured(): bool
    {
        return $this->driver === 'ollama' || (bool) $this->resolvedApiKey();
    }

    public function cost(int $in, int $out): float
    {
        return ($in * $this->cost_in_per_mtok + $out * $this->cost_out_per_mtok) / 1_000_000;
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider_label,
            'family' => $this->family,
            'family_label' => self::FAMILIES[$this->family]['label'].($this->family === 'paid' && ! $this->is_enabled ? ' · désactivée' : ''),
            'driver' => $this->driver,
            'base_url' => $this->base_url,
            'model_name' => $this->model_name,
            'letter' => $this->letter,
            'hue' => $this->hue,
            'description' => $this->description,
            'tags' => $this->tags ?? [],
            'warning' => $this->warning,
            'is_enabled' => $this->is_enabled,
            'is_configured' => $this->isConfigured(),
            'has_api_key' => (bool) $this->api_key,
            'api_key_env' => $this->api_key_env,
            'supports_tools' => $this->supports_tools,
            'supports_vision' => $this->supports_vision,
            'is_advanced' => $this->is_advanced,
            'cost_in_per_mtok' => $this->cost_in_per_mtok,
            'cost_out_per_mtok' => $this->cost_out_per_mtok,
            'priority' => $this->priority,
        ];
    }
}
