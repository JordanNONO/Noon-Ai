<?php

namespace App\Agents;

use App\Models\AiModel;
use App\Models\PendingAction;

class RunResult
{
    public string $text = '';

    /** Traduction française d'une réponse donnée en langue bamiléké. */
    public ?string $translation = null;

    public string $language = 'fr';

    /** @var array<int, array> cartes d'étapes affichées dans la conversation */
    public array $steps = [];

    public ?array $document = null;

    /** @var array<int, array> savoirs de la bibliothèque utilisés */
    public array $sources = [];

    public int $tokensIn = 0;

    public int $tokensOut = 0;

    public ?AiModel $model = null;

    /** @var array<int, PendingAction> */
    public array $pending = [];

    /** @var array<int, string> */
    public array $connectorsUsed = [];
}
