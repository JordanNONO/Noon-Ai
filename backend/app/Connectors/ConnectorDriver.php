<?php

namespace App\Connectors;

use App\Models\Connector;

abstract class ConnectorDriver
{
    /** Exécute un outil et renvoie un résultat sérialisable pour le modèle. */
    abstract public function execute(Connector $connector, string $tool, array $args): mixed;

    /** Vérifie que la configuration fonctionne ; lève une exception sinon. */
    abstract public function test(Connector $connector): string;

    protected function cfg(Connector $c, string $key, mixed $default = null): mixed
    {
        $v = ($c->config ?? [])[$key] ?? null;

        return ($v === null || $v === '') ? $default : $v;
    }

    /** Tronque un texte pour ne pas saturer le contexte du modèle. */
    protected function clip(string $text, int $max = 20000): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max)."\n[… tronqué]" : $text;
    }

    protected function unknown(string $tool): never
    {
        throw new \InvalidArgumentException("Outil inconnu : {$tool}");
    }
}
