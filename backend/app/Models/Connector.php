<?php

namespace App\Models;

use App\Connectors\ConnectorRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Connector extends Model
{
    protected $fillable = [
        'slug', 'name', 'type', 'is_custom', 'letter', 'hue', 'description', 'config', 'is_connected',
        'is_sensitive', 'perm_read', 'perm_write', 'perm_send', 'connected_by', 'connected_at',
    ];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_custom' => 'boolean',
            'is_connected' => 'boolean',
            'is_sensitive' => 'boolean',
            'connected_at' => 'datetime',
        ];
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class);
    }

    public function definition(): array
    {
        return ConnectorRegistry::definition($this->type);
    }

    /** Nom court utilisé dans les puces (« Drive », « Agenda »…). */
    public function shortName(): string
    {
        return $this->definition()['short'] ?? $this->name;
    }

    public function statusKey(): string
    {
        if ($this->is_custom) {
            return 'custom';
        }

        return $this->is_connected ? 'on' : 'off';
    }

    public function permissionFor(string $level): string
    {
        return match ($level) {
            'read' => $this->perm_read,
            'write' => $this->perm_write,
            default => $this->perm_send,
        };
    }

    public function usableBy(User $user): bool
    {
        return $this->is_connected && (! $this->is_sensitive || $user->isAdmin());
    }

    public function toPublic(): array
    {
        $def = $this->definition();
        $config = $this->config ?? [];
        $publicConfig = [];
        foreach ($def['fields'] ?? [] as $f) {
            $publicConfig[$f['key']] = ! empty($f['secret'])
                ? (! empty($config[$f['key']]) ? '__set__' : '')
                : ($config[$f['key']] ?? '');
        }

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'short' => $this->shortName(),
            'type' => $this->type,
            'type_label' => $def['label'] ?? $this->type,
            'auth' => $def['auth'] ?? 'credentials',
            'oauth_ready' => ConnectorRegistry::oauthReady($def['auth'] ?? ''),
            'fields' => $def['fields'] ?? [],
            'config' => $publicConfig,
            'tools' => collect($def['tools'] ?? [])->map(fn ($t, $k) => ['name' => $k, 'label' => $t['label'], 'level' => $t['level']])->values(),
            'is_custom' => $this->is_custom,
            'status' => $this->statusKey(),
            'is_connected' => $this->is_connected,
            'is_sensitive' => $this->is_sensitive,
            'letter' => $this->letter,
            'hue' => $this->hue,
            'description' => $this->description,
            'perms' => ['read' => $this->perm_read, 'write' => $this->perm_write, 'send' => $this->perm_send],
            'agents' => $this->agents->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values(),
            'connected_at' => $this->connected_at?->toIso8601String(),
        ];
    }
}
