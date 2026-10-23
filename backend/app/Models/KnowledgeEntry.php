<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un savoir de la bibliothèque : mot, proverbe, conte, recette, coutume… */
class KnowledgeEntry extends Model
{
    protected $fillable = [
        'type', 'language', 'title', 'content', 'translation', 'meaning', 'pronunciation', 'village',
        'audio_path', 'tags', 'author_id', 'status', 'reviewed_by', 'reviewed_at', 'review_note', 'uses',
    ];

    protected function casts(): array
    {
        return ['tags' => 'array', 'reviewed_at' => 'datetime'];
    }

    public const TYPES = [
        'mot' => 'Mot',
        'expression' => 'Expression',
        'proverbe' => 'Proverbe',
        'conte' => 'Conte',
        'recette' => 'Recette',
        'histoire' => 'Histoire',
        'coutume' => 'Coutume',
        'chant' => 'Chant',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', 'approved');
    }

    /** Recherche simple par mots-clés sur le titre, le texte, la traduction et le sens. */
    public function scopeSearch(Builder $q, string $terms): Builder
    {
        $words = collect(preg_split('/[\s,;.!?«»"\']+/u', mb_strtolower($terms)))
            ->filter(fn ($w) => mb_strlen($w) >= 3)->unique()->take(8);
        if ($words->isEmpty()) {
            return $q;
        }

        return $q->where(function ($w) use ($words) {
            foreach ($words as $word) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
                $w->orWhere('title', 'like', $like)->orWhere('content', 'like', $like)
                    ->orWhere('translation', 'like', $like)->orWhere('meaning', 'like', $like);
            }
        });
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => self::TYPES[$this->type] ?? $this->type,
            'language' => $this->language,
            'language_name' => Language::nameOf($this->language),
            'title' => $this->title,
            'content' => $this->content,
            'translation' => $this->translation,
            'meaning' => $this->meaning,
            'pronunciation' => $this->pronunciation,
            'village' => $this->village,
            'audio_url' => $this->audio_path ? url('/api/knowledge/'.$this->id.'/audio') : null,
            'tags' => $this->tags ?? [],
            'status' => $this->status,
            'author' => $this->author?->shortName(),
            'author_id' => $this->author_id,
            'reviewed_by' => $this->reviewer?->shortName(),
            'review_note' => $this->review_note,
            'uses' => $this->uses,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Version compacte transmise au modèle quand il consulte la bibliothèque. */
    public function forModel(): array
    {
        return array_filter([
            'id' => $this->id,
            'type' => $this->type,
            'langue' => Language::nameOf($this->language),
            'titre' => $this->title,
            'texte' => $this->content,
            'traduction' => $this->translation,
            'sens' => $this->meaning,
            'prononciation' => $this->pronunciation,
            'origine' => $this->village,
        ]);
    }
}
