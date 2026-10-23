<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'key', 'name', 'tagline', 'price_xaf', 'price_eur', 'daily_messages', 'daily_voice', 'premium_models',
        'seats', 'features', 'is_public', 'is_highlighted', 'contact_sales', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'premium_models' => 'boolean',
            'is_public' => 'boolean',
            'is_highlighted' => 'boolean',
            'contact_sales' => 'boolean',
            'price_eur' => 'float',
        ];
    }

    public static function free(): self
    {
        return static::where('key', 'gratuit')->firstOrFail();
    }

    public function isFree(): bool
    {
        return $this->price_xaf === 0 && (float) $this->price_eur === 0.0 && ! $this->contact_sales;
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'price_xaf' => $this->price_xaf,
            'price_eur' => $this->price_eur,
            'daily_messages' => $this->daily_messages,
            'daily_voice' => $this->daily_voice,
            'premium_models' => $this->premium_models,
            'seats' => $this->seats,
            'features' => $this->features ?? [],
            'is_highlighted' => $this->is_highlighted,
            'contact_sales' => $this->contact_sales,
            'is_free' => $this->isFree(),
        ];
    }
}
