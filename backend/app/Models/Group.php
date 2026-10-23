<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Famille, village ou association : sert à regrouper les membres et suivre la consommation. */
class Group extends Model
{
    protected $fillable = ['name', 'slug', 'kind', 'description', 'monthly_token_budget'];

    public const KINDS = ['famille' => 'Famille', 'village' => 'Village', 'association' => 'Association', 'autre' => 'Autre'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'kind' => $this->kind,
            'kind_label' => self::KINDS[$this->kind] ?? $this->kind,
            'description' => $this->description,
            'monthly_token_budget' => $this->monthly_token_budget,
            'members' => $this->users()->count(),
        ];
    }
}
