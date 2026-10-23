<?php

namespace App\Llm;

use App\Models\AiModel;

/**
 * Format neutre des messages passés aux pilotes :
 *  - ['role' => 'user', 'content' => string, 'images' => [['mime' => ..., 'data' => base64]]]
 *  - ['role' => 'assistant', 'content' => string, 'tool_calls' => [...], 'raw' => mixed, 'driver' => string]
 *  - ['role' => 'tool', 'results' => [['id' => ..., 'name' => ..., 'content' => string, 'is_error' => bool]]]
 *
 * Outils : ['name' => ..., 'description' => ..., 'parameters' => JSON Schema objet]
 */
interface LlmDriver
{
    /** @param  callable(string): void|null  $onText  reçoit le texte au fil de l'eau */
    public function chat(AiModel $model, string $system, array $messages, array $tools, bool $advanced, ?callable $onText): LlmResponse;
}
