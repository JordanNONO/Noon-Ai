<?php

namespace App\Connectors\Drivers;

use App\Connectors\ConnectorDriver;
use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WordpressDriver extends ConnectorDriver
{
    private function http(Connector $c): PendingRequest
    {
        return Http::withBasicAuth($this->cfg($c, 'username'), $this->cfg($c, 'app_password'))
            ->baseUrl(rtrim($this->cfg($c, 'site_url'), '/').'/wp-json/wp/v2')
            ->timeout(30)->acceptJson();
    }

    public function test(Connector $connector): string
    {
        $res = $this->http($connector)->get('/users/me');
        if (! $res->successful()) {
            throw new \RuntimeException('WordPress refuse la connexion : '.($res->json('message') ?? $res->status()));
        }

        return 'Connecté en tant que '.$res->json('name');
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $http = $this->http($connector);
        $clean = fn ($p) => [
            'id' => $p['id'], 'titre' => html_entity_decode(strip_tags($p['title']['rendered'] ?? '')), 'date' => $p['date'] ?? '',
            'lien' => $p['link'] ?? '', 'statut' => $p['status'] ?? '', 'extrait' => trim(html_entity_decode(strip_tags($p['excerpt']['rendered'] ?? ''))),
        ];

        switch ($tool) {
            case 'list_posts':
                $q = array_filter(['search' => $args['search'] ?? null, 'after' => $args['after'] ?? null, 'per_page' => min((int) ($args['max'] ?? 10), 50), 'orderby' => 'date']);
                $res = $http->get('/posts', $q);

                return collect($res->json() ?? [])->map($clean)->all();
            case 'get_post':
                $p = $http->get('/posts/'.(int) $args['id'])->json();

                return $clean($p) + ['contenu' => $this->clip(trim(html_entity_decode(strip_tags($p['content']['rendered'] ?? ''))))];
            case 'create_draft':
                $p = $http->post('/posts', ['title' => $args['title'], 'content' => $args['content'], 'status' => 'draft'])->throw()->json();

                return ['statut' => 'Brouillon créé', 'id' => $p['id'], 'lien' => $p['link'] ?? null];
            case 'publish_post':
                $p = $http->post('/posts/'.(int) $args['id'], ['status' => 'publish'])->throw()->json();

                return ['statut' => 'Article publié', 'lien' => $p['link'] ?? null];
        }
        $this->unknown($tool);
    }
}
