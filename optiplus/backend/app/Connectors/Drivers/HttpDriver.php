<?php

namespace App\Connectors\Drivers;

use App\Connectors\ConnectorDriver;
use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class HttpDriver extends ConnectorDriver
{
    private function http(Connector $c): PendingRequest
    {
        $h = Http::baseUrl(rtrim($this->cfg($c, 'base_url'), '/'))->timeout(30)->acceptJson();
        if ($name = $this->cfg($c, 'auth_header')) {
            $h = $h->withHeaders([$name => (string) $this->cfg($c, 'auth_value', '')]);
        }

        return $h;
    }

    private function path(string $p): string
    {
        if (preg_match('#^[a-z]+://#i', $p)) {
            throw new \InvalidArgumentException('Utilisez un chemin relatif à l\'API.');
        }

        return '/'.ltrim($p, '/');
    }

    public function test(Connector $connector): string
    {
        $res = $this->http($connector)->get('/');

        return 'API joignable (statut '.$res->status().')';
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $http = $this->http($connector);
        if ($tool === 'get') {
            $res = $http->get($this->path($args['path']));
        } elseif ($tool === 'post') {
            $method = strtolower($args['method'] ?? 'post');
            if (! in_array($method, ['post', 'put', 'patch'])) {
                throw new \InvalidArgumentException('Méthode non autorisée.');
            }
            $body = is_string($args['body'] ?? null) ? (json_decode($args['body'], true) ?? []) : ($args['body'] ?? []);
            $res = $http->{$method}($this->path($args['path']), $body);
        } else {
            $this->unknown($tool);
        }

        return ['statut' => $res->status(), 'reponse' => $res->json() ?? $this->clip($res->body())];
    }
}
