<?php

namespace App\Connectors\Drivers;

use App\Models\Connector;

class GoogleCalendarDriver extends GoogleBase
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    public function test(Connector $connector): string
    {
        $cal = $this->ok($this->http($connector)->get(self::API.'/calendars/primary'));

        return 'Agenda de '.($cal['summary'] ?? 'l\'entreprise');
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $http = $this->http($connector);

        return match ($tool) {
            'list_events' => ['evenements' => collect($this->ok($http->get(self::API.'/calendars/primary/events', [
                'timeMin' => $args['from'], 'timeMax' => $args['to'], 'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 50,
            ]))['items'] ?? [])->map(fn ($e) => [
                'titre' => $e['summary'] ?? '', 'debut' => $e['start']['dateTime'] ?? $e['start']['date'] ?? '', 'fin' => $e['end']['dateTime'] ?? $e['end']['date'] ?? '',
                'invites' => collect($e['attendees'] ?? [])->pluck('email')->all(),
            ])->all()],
            'free_busy' => $this->ok($http->post(self::API.'/freeBusy', [
                'timeMin' => $args['from'], 'timeMax' => $args['to'],
                'items' => collect(explode(',', $args['emails']))->map(fn ($e) => ['id' => trim($e)])->all(),
            ]))['calendars'] ?? [],
            'create_event' => $this->create($http, $args),
            default => $this->unknown($tool),
        };
    }

    private function create($http, array $a): array
    {
        $attendees = collect(explode(',', (string) ($a['attendees'] ?? '')))->map('trim')->filter()->map(fn ($e) => ['email' => $e])->values()->all();
        $ev = $this->ok($http->post(self::API.'/calendars/primary/events?sendUpdates=all', [
            'summary' => $a['summary'], 'description' => $a['description'] ?? '',
            'start' => ['dateTime' => $a['start']], 'end' => ['dateTime' => $a['end']], 'attendees' => $attendees,
        ]));

        return ['statut' => 'Rendez-vous créé, invitations envoyées', 'lien' => $ev['htmlLink'] ?? null];
    }
}
