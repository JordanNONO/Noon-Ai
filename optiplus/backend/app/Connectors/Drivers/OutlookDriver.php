<?php

namespace App\Connectors\Drivers;

use App\Models\Connector;

class OutlookDriver extends GoogleBase
{
    protected const API = 'https://graph.microsoft.com/v1.0';

    public function test(Connector $connector): string
    {
        $me = $this->ok($this->http($connector)->get(self::API.'/me'));

        return 'Connecté à '.($me['mail'] ?? $me['userPrincipalName'] ?? 'Microsoft 365');
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $http = $this->http($connector);
        $recipients = fn (string $list) => collect(explode(',', $list))->map('trim')->filter()->map(fn ($e) => ['emailAddress' => ['address' => $e]])->values()->all();

        switch ($tool) {
            case 'search_emails':
                $res = $this->ok($http->withHeaders(['ConsistencyLevel' => 'eventual'])->get(self::API.'/me/messages', ['$search' => '"'.$args['query'].'"', '$top' => 15, '$select' => 'id,subject,from,receivedDateTime,bodyPreview,isRead']));

                return ['emails' => collect($res['value'] ?? [])->map(fn ($m) => ['id' => $m['id'], 'objet' => $m['subject'], 'de' => $m['from']['emailAddress']['address'] ?? '', 'date' => $m['receivedDateTime'], 'extrait' => $m['bodyPreview'], 'non_lu' => ! $m['isRead']])->all()];
            case 'send_email':
                $this->ok($http->post(self::API.'/me/sendMail', ['message' => ['subject' => $args['subject'], 'body' => ['contentType' => 'Text', 'content' => $args['body']], 'toRecipients' => $recipients($args['to'])]]));

                return ['statut' => 'E-mail envoyé'];
            case 'list_events':
                $res = $this->ok($http->get(self::API.'/me/calendarView', ['startDateTime' => $args['from'], 'endDateTime' => $args['to'], '$select' => 'subject,start,end,attendees']));

                return ['evenements' => collect($res['value'] ?? [])->map(fn ($e) => ['titre' => $e['subject'], 'debut' => $e['start']['dateTime'], 'fin' => $e['end']['dateTime']])->all()];
            case 'create_event':
                $this->ok($http->post(self::API.'/me/events', [
                    'subject' => $args['summary'],
                    'start' => ['dateTime' => $args['start'], 'timeZone' => 'Europe/Paris'],
                    'end' => ['dateTime' => $args['end'], 'timeZone' => 'Europe/Paris'],
                    'attendees' => collect($recipients((string) ($args['attendees'] ?? '')))->map(fn ($r) => $r + ['type' => 'required'])->all(),
                ]));

                return ['statut' => 'Rendez-vous créé'];
        }
        $this->unknown($tool);
    }
}
