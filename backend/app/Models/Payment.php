<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id', 'subscription_id', 'plan_id', 'provider', 'reference', 'provider_ref', 'months', 'amount', 'currency',
        'phone', 'operator', 'status', 'validated_by', 'paid_at', 'raw',
    ];

    protected function casts(): array
    {
        return ['raw' => 'array', 'paid_at' => 'datetime', 'amount' => 'float'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'plan' => $this->plan?->name,
            'provider' => $this->provider,
            'months' => $this->months,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'phone' => $this->phone,
            'operator' => $this->operator,
            'status' => $this->status,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'phone' => $this->user->phone, 'email' => $this->user->email] : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
