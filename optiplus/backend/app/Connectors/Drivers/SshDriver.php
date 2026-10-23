<?php

namespace App\Connectors\Drivers;

use App\Connectors\ConnectorDriver;
use App\Models\Connector;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/** Exécution de commandes : niveau « send », donc toujours soumis à l'accord de l'utilisateur. */
class SshDriver extends ConnectorDriver
{
    private function connect(Connector $c): SSH2
    {
        $ssh = new SSH2($this->cfg($c, 'host'), (int) $this->cfg($c, 'port', 22), 15);
        $key = $this->cfg($c, 'private_key');
        $cred = $key ? PublicKeyLoader::load($key, $this->cfg($c, 'password') ?: false) : $this->cfg($c, 'password', '');
        if (! $ssh->login($this->cfg($c, 'username'), $cred)) {
            throw new \RuntimeException('Connexion SSH refusée : vérifiez les identifiants.');
        }
        $ssh->setTimeout(60);

        return $ssh;
    }

    public function test(Connector $connector): string
    {
        return 'Machine joignable : '.trim($this->connect($connector)->exec('hostname'));
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        if ($tool !== 'run_command') {
            $this->unknown($tool);
        }
        $ssh = $this->connect($connector);
        $out = $ssh->exec($args['command']);

        return ['code_sortie' => $ssh->getExitStatus(), 'sortie' => $this->clip((string) $out, 20000)];
    }
}
