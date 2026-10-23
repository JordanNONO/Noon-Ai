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

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'group_id', 'role', 'language', 'bilingual_replies',
        'large_text', 'is_active', 'voice_settings', 'last_seen_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'bilingual_replies' => 'boolean',
            'large_text' => 'boolean',
            'voice_settings' => 'array',
        ];
    }

    public const ROLES = ['admin' => 'Administrateur', 'moderator' => 'Modérateur (ancien)', 'member' => 'Membre'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->id === self::PROTECTED_ADMIN_ID;
    }

    /** Les modérateurs valident les savoirs proposés par la communauté. */
    public function canModerate(): bool
    {
        return $this->isAdmin() || $this->role === 'moderator';
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name));
        $ini = mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1);

        return mb_strtoupper($ini ?: '—');
    }

    public function firstName(): string
    {
        return preg_split('/\s+/', trim($this->name))[0] ?? $this->name;
    }

    public function shortName(): string
    {
        $parts = preg_split('/\s+/', trim($this->name));

        return count($parts) > 1 ? $parts[0].' '.mb_substr($parts[1], 0, 1).'.' : $this->name;
    }

    public function voicePrefs(): array
    {
        return array_merge([
            'wake_word' => true,
            'voice' => 'Douce',
            'rate' => 2,
            'confirm_aloud' => true,
            'auto_read' => true, // lire les réponses à voix haute en mode vocal
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
            'phone' => $this->phone,
            'role' => $this->isAdmin() ? 'admin' : $this->role,
            'role_label' => self::ROLES[$this->isAdmin() ? 'admin' : $this->role] ?? $this->role,
            'can_moderate' => $this->canModerate(),
            'is_active' => $this->is_active,
            'is_protected' => $this->id === self::PROTECTED_ADMIN_ID,
            'language' => $this->language,
            'language_name' => Language::nameOf($this->language),
            'bilingual_replies' => $this->bilingual_replies,
            'large_text' => $this->large_text,
            'group' => $this->group ? ['id' => $this->group->id, 'name' => $this->group->name] : null,
            'voice' => $this->voicePrefs(),
        ];
    }
}
