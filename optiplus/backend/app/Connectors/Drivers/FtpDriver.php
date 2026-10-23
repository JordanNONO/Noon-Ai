<?php

namespace App\Connectors\Drivers;

use App\Connectors\ConnectorDriver;
use App\Models\Connector;

/** Accès FTP en lecture seule, limité au dossier racine configuré. */
class FtpDriver extends ConnectorDriver
{
    /** @return \FTP\Connection */
    private function connect(Connector $c)
    {
        $ftp = @ftp_connect($this->cfg($c, 'host'), (int) $this->cfg($c, 'port', 21), 15);
        if (! $ftp || ! @ftp_login($ftp, $this->cfg($c, 'username'), $this->cfg($c, 'password', ''))) {
            throw new \RuntimeException('Connexion FTP impossible : vérifiez l\'hôte et les identifiants.');
        }
        ftp_pasv($ftp, true);

        return $ftp;
    }

    private function path(Connector $c, ?string $p): string
    {
        $root = rtrim($this->cfg($c, 'root', '/'), '/');
        $p = '/'.ltrim((string) $p, '/');
        if (str_contains($p, '..')) {
            throw new \InvalidArgumentException('Chemin non autorisé.');
        }

        return ($root ?: '').$p;
    }

    public function test(Connector $connector): string
    {
        $ftp = $this->connect($connector);
        $n = count(ftp_nlist($ftp, $this->path($connector, '/')) ?: []);
        ftp_close($ftp);

        return "Serveur FTP joignable ({$n} éléments à la racine)";
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $ftp = $this->connect($connector);
        try {
            if ($tool === 'list_dir') {
                $list = ftp_mlsd($ftp, $this->path($connector, $args['path'] ?? '/')) ?: [];

                return collect($list)->reject(fn ($f) => in_array($f['name'], ['.', '..']))
                    ->map(fn ($f) => ['nom' => $f['name'], 'type' => $f['type'] ?? '', 'taille' => (int) ($f['size'] ?? 0)])->values()->all();
            }
            if ($tool === 'read_file') {
                $tmp = fopen('php://temp', 'r+');
                if (! ftp_fget($ftp, $tmp, $this->path($connector, $args['path']), FTP_BINARY)) {
                    throw new \RuntimeException('Fichier introuvable.');
                }
                rewind($tmp);

                return ['chemin' => $args['path'], 'contenu' => $this->clip((string) stream_get_contents($tmp, 200_000), 40000)];
            }
            $this->unknown($tool);
        } finally {
            ftp_close($ftp);
        }
    }
}
