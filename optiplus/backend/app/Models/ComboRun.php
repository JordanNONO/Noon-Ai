<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboRun extends Model
{
    protected $fillable = ['combo_id', 'user_id', 'conversation_id', 'status', 'current_step', 'outputs', 'input'];

    protected function casts(): array
    {
        return ['outputs' => 'array'];
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
