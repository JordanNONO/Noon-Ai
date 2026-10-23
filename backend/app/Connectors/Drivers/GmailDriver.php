<?php

namespace App\Connectors\Drivers;

use App\Models\Connector;

class GmailDriver extends GoogleBase
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function test(Connector $connector): string
    {
        $p = $this->ok($this->http($connector)->get(self::API.'/profile'));

        return 'Connecté à '.($p['emailAddress'] ?? 'Gmail');
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $http = $this->http($connector);

        return match ($tool) {
            'search_emails' => $this->search($connector, $args['query'] ?? '', (int) ($args['max'] ?? 10)),
            'read_email' => $this->read($connector, $args['id']),
            'create_draft' => ['draft_id' => $this->ok($http->post(self::API.'/drafts', ['message' => ['raw' => $this->raw($args)]]))['id'] ?? null, 'statut' => 'Brouillon créé'],
            'send_email' => ['message_id' => $this->ok($http->post(self::API.'/messages/send', ['raw' => $this->raw($args)]))['id'] ?? null, 'statut' => 'E-mail envoyé'],
            default => $this->unknown($tool),
        };
    }

    private function search(Connector $c, string $q, int $max): array
    {
        $list = $this->ok($this->http($c)->get(self::API.'/messages', ['q' => $q, 'maxResults' => min(max($max, 1), 25)]));
        $out = [];
        foreach ($list['messages'] ?? [] as $m) {
            $msg = $this->ok($this->http($c)->get(self::API.'/messages/'.$m['id'], ['format' => 'metadata', 'metadataHeaders' => ['From', 'Subject', 'Date']]));
            $h = collect($msg['payload']['headers'] ?? [])->pluck('value', 'name');
            $out[] = ['id' => $m['id'], 'de' => $h['From'] ?? '', 'objet' => $h['Subject'] ?? '', 'date' => $h['Date'] ?? '', 'extrait' => html_entity_decode($msg['snippet'] ?? ''), 'non_lu' => in_array('UNREAD', $msg['labelIds'] ?? [])];
        }

        return ['total' => count($out), 'emails' => $out];
    }

    private function read(Connector $c, string $id): array
    {
        $msg = $this->ok($this->http($c)->get(self::API.'/messages/'.$id, ['format' => 'full']));
        $h = collect($msg['payload']['headers'] ?? [])->pluck('value', 'name');

        return ['de' => $h['From'] ?? '', 'a' => $h['To'] ?? '', 'objet' => $h['Subject'] ?? '', 'date' => $h['Date'] ?? '', 'corps' => $this->clip($this->body($msg['payload'] ?? []), 15000)];
    }

    private function body(array $part): string
    {
        if (($part['mimeType'] ?? '') === 'text/plain' && ! empty($part['body']['data'])) {
            return base64_decode(strtr($part['body']['data'], '-_', '+/'));
        }
        foreach ($part['parts'] ?? [] as $p) {
            if ($t = $this->body($p)) {
                return $t;
            }
        }
        if (($part['mimeType'] ?? '') === 'text/html' && ! empty($part['body']['data'])) {
            return trim(strip_tags(base64_decode(strtr($part['body']['data'], '-_', '+/'))));
        }

        return '';
    }

    private function raw(array $a): string
    {
        $mime = 'To: '.$a['to']."\r\n".'Subject: =?UTF-8?B?'.base64_encode($a['subject'])."?=\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($a['body']));

        return rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    }
}
