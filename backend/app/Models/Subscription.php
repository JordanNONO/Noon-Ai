<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['user_id', 'group_id', 'plan_id', 'status', 'provider', 'current_period_end'];

    protected function casts(): array
    {
        return ['current_period_end' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('status', 'active')->where('current_period_end', '>', now());
    }
}
