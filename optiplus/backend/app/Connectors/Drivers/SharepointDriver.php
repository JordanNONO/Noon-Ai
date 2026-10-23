<?php

namespace App\Connectors\Drivers;

use App\Models\Connector;
use App\Support\TextExtractor;

class SharepointDriver extends OutlookDriver
{
    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $http = $this->http($connector);
        if ($tool === 'search_files') {
            $res = $this->ok($http->post(self::API.'/search/query', ['requests' => [['entityTypes' => ['driveItem'], 'query' => ['queryString' => $args['query']], 'size' => 15]]]));
            $hits = collect(data_get($res, 'value.0.hitsContainers.0.hits', []))->map(fn ($h) => [
                'nom' => data_get($h, 'resource.name'), 'drive_id' => data_get($h, 'resource.parentReference.driveId'), 'item_id' => data_get($h, 'resource.id'),
                'lien' => data_get($h, 'resource.webUrl'), 'extrait' => strip_tags((string) ($h['summary'] ?? '')),
            ]);

            return ['fichiers' => $hits->all()];
        }
        if ($tool === 'read_file') {
            $base = self::API.'/drives/'.$args['drive_id'].'/items/'.$args['item_id'];
            $meta = $this->ok($http->get($base));
            $raw = $http->get($base.'/content')->body();

            return ['nom' => $meta['name'] ?? '', 'contenu' => $this->clip(TextExtractor::fromBytes($raw, $meta['file']['mimeType'] ?? '', $meta['name'] ?? ''))];
        }
        $this->unknown($tool);
    }
}
