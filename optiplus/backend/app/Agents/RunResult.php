<?php

namespace App\Agents;

use App\Models\AiModel;
use App\Models\PendingAction;

class RunResult
{
    public string $text = '';

    /** @var array<int, array> cartes d'étapes affichées dans la conversation */
    public array $steps = [];

    public ?array $document = null;

    public int $tokensIn = 0;

    public int $tokensOut = 0;

    public ?AiModel $model = null;

    /** @var array<int, PendingAction> */
    public array $pending = [];

    /** @var array<int, string> */
    public array $connectorsUsed = [];
}
