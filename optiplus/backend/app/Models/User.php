<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** Le premier administrateur garde toujours tous les droits. */
    public const PROTECTED_ADMIN_ID = 1;

    protected $fillable = ['name', 'email', 'password', 'service_id', 'role', 'job_title', 'is_active', 'voice_settings', 'last_seen_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'voice_settings' => 'array',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->id === self::PROTECTED_ADMIN_ID;
    }

    /** Admins et service Tech peuvent utiliser les connecteurs sensibles. */
    public function canUseSensitive(): bool
    {
        return $this->isAdmin() || $this->service?->slug === 'tech';
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name));
        $ini = mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1);

        return mb_strtoupper($ini ?: '—');
    }

    public function shortName(): string
    {
        $parts = preg_split('/\s+/', trim($this->name));

        return count($parts) > 1 ? $parts[0].' '.mb_substr($parts[1], 0, 1).'.' : $this->name;
    }

    public function firstName(): string
    {
        return preg_split('/\s+/', trim($this->name))[0] ?? $this->name;
    }

    public function voicePrefs(): array
    {
        return array_merge([
            'wake_word' => true,
            'voice' => 'Claire',
            'rate' => 2,
            'confirm_aloud' => true,
            'language' => 'fr-FR',
        ], $this->voice_settings ?? []);
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->firstName(),
            'short_name' => $this->shortName(),
            'initials' => $this->initials(),
            'email' => $this->email,
            'role' => $this->isAdmin() ? 'admin' : 'member',
            'job_title' => $this->job_title,
            'is_active' => $this->is_active,
            'is_protected' => $this->id === self::PROTECTED_ADMIN_ID,
            'can_use_sensitive' => $this->canUseSensitive(),
            'service' => $this->service ? ['id' => $this->service->id, 'name' => $this->service->name, 'slug' => $this->service->slug] : null,
            'voice' => $this->voicePrefs(),
        ];
    }
}
