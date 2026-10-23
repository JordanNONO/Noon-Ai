<?php

namespace App\Connectors\Drivers;

use App\Connectors\ConnectorDriver;
use App\Models\Connector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GithubDriver extends ConnectorDriver
{
    private function http(Connector $c): PendingRequest
    {
        return Http::withToken($this->cfg($c, 'token'))->baseUrl('https://api.github.com')
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])->timeout(30);
    }

    private function repo(Connector $c, string $repo): string
    {
        return str_contains($repo, '/') ? $repo : trim($this->cfg($c, 'owner', ''), '/').'/'.$repo;
    }

    public function test(Connector $connector): string
    {
        $res = $this->http($connector)->get('/user');
        if (! $res->successful()) {
            throw new \RuntimeException('GitHub refuse le jeton : '.($res->json('message') ?? $res->status()));
        }

        return 'Connecté en tant que '.$res->json('login');
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $http = $this->http($connector);
        switch ($tool) {
            case 'list_repos':
                return collect($http->get('/user/repos', ['per_page' => 100, 'sort' => 'updated'])->json() ?? [])
                    ->map(fn ($r) => ['nom' => $r['full_name'], 'prive' => $r['private'], 'description' => $r['description'], 'maj' => $r['updated_at']])->all();
            case 'read_file':
                $res = $http->get('/repos/'.$this->repo($connector, $args['repo']).'/contents/'.ltrim($args['path'] ?? '', '/'), array_filter(['ref' => $args['ref'] ?? null]))->json();
                if (isset($res[0])) {
                    return collect($res)->map(fn ($f) => ['nom' => $f['name'], 'type' => $f['type'], 'chemin' => $f['path']])->all();
                }
                if (isset($res['content'])) {
                    return ['chemin' => $res['path'], 'contenu' => $this->clip(base64_decode($res['content']), 40000)];
                }

                return $res;
            case 'search_code':
                $q = $args['query'].(! empty($args['repo']) ? ' repo:'.$this->repo($connector, $args['repo']) : '');

                return collect($http->get('/search/code', ['q' => $q, 'per_page' => 20])->json('items') ?? [])
                    ->map(fn ($i) => ['chemin' => $i['path'], 'depot' => $i['repository']['full_name']])->all();
            case 'list_issues':
                return collect($http->get('/repos/'.$this->repo($connector, $args['repo']).'/issues', ['state' => 'open', 'per_page' => 30])->json() ?? [])
                    ->map(fn ($i) => ['numero' => $i['number'], 'titre' => $i['title'], 'auteur' => $i['user']['login'] ?? '', 'cree' => $i['created_at']])->all();
            case 'create_issue':
                $i = $http->post('/repos/'.$this->repo($connector, $args['repo']).'/issues', ['title' => $args['title'], 'body' => $args['body'] ?? ''])->throw()->json();

                return ['statut' => 'Ticket créé', 'numero' => $i['number'], 'lien' => $i['html_url']];
        }
        $this->unknown($tool);
    }
}
