<?php

namespace App\Connectors\Drivers;

use App\Connectors\ConnectorDriver;
use App\Models\Connector;
use PDO;

/** Accès en lecture seule : seules les requêtes SELECT / SHOW / DESCRIBE passent. */
class MysqlDriver extends ConnectorDriver
{
    private function pdo(Connector $c): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->cfg($c, 'host'), (int) $this->cfg($c, 'port', 3306), $this->cfg($c, 'database'));
        $pdo = new PDO($dsn, $this->cfg($c, 'username'), $this->cfg($c, 'password', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        // Ceinture et bretelles : la session elle-même est en lecture seule
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');

        return $pdo;
    }

    public function test(Connector $connector): string
    {
        $n = count($this->pdo($connector)->query('SHOW TABLES')->fetchAll());

        return "Base {$this->cfg($connector, 'database')} : {$n} tables";
    }

    public static function isReadOnly(string $sql): bool
    {
        $s = trim(preg_replace('/\/\*.*?\*\/|--[^\n]*|#[^\n]*/s', ' ', $sql));
        if (str_contains(rtrim($s, "; \n\t"), ';')) {
            return false; // pas de requêtes multiples
        }
        if (! preg_match('/^(select|show|describe|desc|explain|with)\b/i', $s)) {
            return false;
        }

        return ! preg_match('/\b(insert|update|delete|drop|alter|create|truncate|replace|grant|revoke|rename|lock|call|load_file|into\s+outfile|into\s+dumpfile|sleep|benchmark)\b/i', $s);
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $pdo = $this->pdo($connector);
        switch ($tool) {
            case 'list_tables':
                return array_map(fn ($r) => array_values($r)[0], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_ASSOC));
            case 'describe_table':
                $t = preg_replace('/[^A-Za-z0-9_]/', '', $args['table']);

                return $pdo->query("DESCRIBE `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
            case 'run_select':
                $sql = trim($args['sql'], "; \n\t");
                if (! self::isReadOnly($sql)) {
                    throw new \InvalidArgumentException('Seules les requêtes en lecture (SELECT) sont autorisées.');
                }
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

                return ['lignes' => array_slice($rows, 0, 200), 'total' => count($rows), 'tronque' => count($rows) > 200];
        }
        $this->unknown($tool);
    }
}
