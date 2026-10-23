<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = ['name', 'short_label', 'slug', 'monthly_token_budget', 'sort'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class);
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_label' => $this->short_label,
            'slug' => $this->slug,
            'monthly_token_budget' => $this->monthly_token_budget,
        ];
    }
}
