<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingRule extends Model
{
    protected $fillable = ['label', 'category', 'level', 'local_only', 'ai_model_id', 'note', 'sort'];

    protected function casts(): array
    {
        return ['local_only' => 'boolean'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'category' => $this->category,
            'level' => $this->level,
            'local_only' => $this->local_only,
            'ai_model_id' => $this->ai_model_id,
            'model' => $this->model?->name,
            'note' => $this->note,
        ];
    }
}
