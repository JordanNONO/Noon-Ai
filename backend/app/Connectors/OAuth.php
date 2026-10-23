<?php

namespace App\Connectors;

use App\Models\Connector;
use Illuminate\Support\Facades\Http;

/**
 * Flux OAuth 2 Google (Gmail, Drive, Agenda).
 * Les jetons sont stockés chiffrés dans la configuration du connecteur.
 */
class OAuth
{
    public static function redirectUri(): string
    {
        return rtrim(config('app.url'), '/').'/oauth/callback';
    }

    public static function authorizeUrl(Connector $c, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('noon.google.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', array_merge(['openid', 'email'], $c->definition()['scopes'] ?? [])),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public static function exchange(Connector $c, string $code): void
    {
        self::store($c, self::tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri()]));
    }

    /** Jeton d'accès valide, rafraîchi si nécessaire. */
    public static function accessToken(Connector $c): string
    {
        $cfg = $c->config ?? [];
        if (empty($cfg['access_token'])) {
            throw new \RuntimeException("Le connecteur {$c->name} n'est pas encore autorisé.");
        }
        if (($cfg['expires_at'] ?? 0) > time() + 60) {
            return $cfg['access_token'];
        }
        if (empty($cfg['refresh_token'])) {
            throw new \RuntimeException("La connexion à {$c->name} a expiré : reconnectez-le.");
        }
        self::store($c, self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $cfg['refresh_token']]));

        return $c->config['access_token'];
    }

    private static function tokenRequest(array $params): array
    {
        $params += ['client_id' => config('noon.google.client_id'), 'client_secret' => config('noon.google.client_secret')];
        $res = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', $params);
        if (! $res->successful()) {
            throw new \RuntimeException('Autorisation refusée : '.($res->json('error_description') ?? $res->body()));
        }

        return $res->json();
    }

    private static function store(Connector $c, array $tokens): void
    {
        $cfg = $c->config ?? [];
        $cfg['access_token'] = $tokens['access_token'];
        if (! empty($tokens['refresh_token'])) {
            $cfg['refresh_token'] = $tokens['refresh_token'];
        }
        $cfg['expires_at'] = time() + (int) ($tokens['expires_in'] ?? 3600);
        $c->config = $cfg;
        $c->save();
    }
}
