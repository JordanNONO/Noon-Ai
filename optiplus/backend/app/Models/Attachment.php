<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $fillable = ['user_id', 'name', 'mime', 'size', 'path', 'extracted_text'];

    protected $hidden = ['extracted_text', 'path'];

    public function toPublic(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'mime' => $this->mime, 'size' => $this->size, 'has_text' => (bool) $this->extracted_text];
    }
}
