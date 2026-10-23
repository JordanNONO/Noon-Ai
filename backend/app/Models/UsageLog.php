<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'group_id', 'agent_id', 'ai_model_id', 'conversation_id', 'tokens_in', 'tokens_out', 'cost', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'cost' => 'float'];
    }
}
