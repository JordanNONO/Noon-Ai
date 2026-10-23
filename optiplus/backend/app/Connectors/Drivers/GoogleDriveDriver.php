<?php

namespace App\Connectors\Drivers;

use App\Models\Connector;
use App\Support\TextExtractor;

class GoogleDriveDriver extends GoogleBase
{
    private const API = 'https://www.googleapis.com/drive/v3';

    public function test(Connector $connector): string
    {
        $about = $this->ok($this->http($connector)->get(self::API.'/about', ['fields' => 'user']));

        return 'Connecté au Drive de '.($about['user']['emailAddress'] ?? 'l\'entreprise');
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        return match ($tool) {
            'search_files' => $this->search($connector, $args['query'] ?? ''),
            'read_file' => $this->read($connector, $args['id']),
            default => $this->unknown($tool),
        };
    }

    private function search(Connector $c, string $q): array
    {
        $q = str_replace("'", "\\'", $q);
        $res = $this->ok($this->http($c)->get(self::API.'/files', [
            'q' => "(name contains '{$q}' or fullText contains '{$q}') and trashed = false",
            'pageSize' => 15,
            'fields' => 'files(id,name,mimeType,modifiedTime,webViewLink)',
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ]));

        return ['fichiers' => $res['files'] ?? []];
    }

    private function read(Connector $c, string $id): array
    {
        $meta = $this->ok($this->http($c)->get(self::API.'/files/'.$id, ['fields' => 'id,name,mimeType', 'supportsAllDrives' => 'true']));
        $mime = $meta['mimeType'] ?? '';
        $exports = [
            'application/vnd.google-apps.document' => 'text/plain',
            'application/vnd.google-apps.spreadsheet' => 'text/csv',
            'application/vnd.google-apps.presentation' => 'text/plain',
        ];
        if (isset($exports[$mime])) {
            $body = $this->http($c)->get(self::API.'/files/'.$id.'/export', ['mimeType' => $exports[$mime]])->body();
        } else {
            $raw = $this->http($c)->get(self::API.'/files/'.$id, ['alt' => 'media', 'supportsAllDrives' => 'true'])->body();
            $body = TextExtractor::fromBytes($raw, $mime, $meta['name'] ?? '');
        }

        return ['nom' => $meta['name'] ?? '', 'contenu' => $this->clip($body)];
    }
}
