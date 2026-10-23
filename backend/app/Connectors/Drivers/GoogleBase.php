<?php

namespace App\Connectors\Drivers;

use App\Connectors\ConnectorDriver;
use App\Connectors\OAuth;
use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Base commune aux connecteurs Google et Microsoft (jeton OAuth). */
abstract class GoogleBase extends ConnectorDriver
{
    protected function http(Connector $c): PendingRequest
    {
        return Http::withToken(OAuth::accessToken($c))->timeout(30)->acceptJson();
    }

    protected function ok(Response $res): array
    {
        if (! $res->successful()) {
            $msg = $res->json('error.message') ?? $res->json('error_description') ?? $res->body();
            throw new \RuntimeException('Erreur du service : '.mb_strimwidth((string) $msg, 0, 300, '…'));
        }

        return $res->json() ?? [];
    }
}
