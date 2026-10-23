<?php

namespace App\Llm;

/** Erreur côté IA : un message simple pour tout le monde, le détail technique pour les administrateurs. */
class LlmException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, public ?string $detail = null)
    {
        parent::__construct($message, $code);
    }

    public function messageFor(?\App\Models\User $user): string
    {
        return $this->getMessage().($this->detail && $user?->isAdmin() ? "\n\nDétail technique (visible par les administrateurs) : ".$this->detail : '');
    }
}
