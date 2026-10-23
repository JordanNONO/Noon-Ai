<?php

namespace App\Llm;

/** Réponse normalisée d'un modèle, quel que soit le fournisseur. */
class LlmResponse
{
    /**
     * @param  array<int, array{id: string, name: string, args: array}>  $toolCalls
     * @param  mixed  $raw  contenu natif de l'assistant, renvoyé tel quel au même fournisseur pendant la boucle d'outils
     */
    public function __construct(
        public string $text = '',
        public array $toolCalls = [],
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public ?string $stopReason = null,
        public mixed $raw = null,
    ) {}
}
