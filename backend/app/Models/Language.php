<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'native_name', 'family', 'speech_to_text', 'text_to_speech', 'quality', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['speech_to_text' => 'boolean', 'text_to_speech' => 'boolean', 'is_active' => 'boolean'];
    }

    public const QUALITY_LABELS = [
        'good' => 'Bonne maîtrise',
        'beta' => 'En apprentissage',
        'experimental' => 'Expérimental',
    ];

    public function isBamileke(): bool
    {
        return $this->family === 'bamileke';
    }

    public static function nameOf(?string $code): string
    {
        return $code ? (static::find($code)?->name ?? $code) : 'français';
    }

    public function toPublic(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'native_name' => $this->native_name,
            'family' => $this->family,
            'speech_to_text' => $this->speech_to_text,
            'text_to_speech' => $this->text_to_speech,
            'quality' => $this->quality,
            'quality_label' => self::QUALITY_LABELS[$this->quality] ?? $this->quality,
            'approved_entries' => KnowledgeEntry::approved()->where('language', $this->code)->count(),
        ];
    }
}
