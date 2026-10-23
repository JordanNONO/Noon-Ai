<?php

namespace App\Connectors\Drivers;

use App\Models\Connector;

class TeamsDriver extends OutlookDriver
{
    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        $http = $this->http($connector);
        switch ($tool) {
            case 'list_channels':
                $teams = $this->ok($http->get(self::API.'/me/joinedTeams'))['value'] ?? [];
                $out = [];
                foreach ($teams as $t) {
                    $chans = $this->ok($http->get(self::API.'/teams/'.$t['id'].'/channels'))['value'] ?? [];
                    $out[] = ['equipe' => $t['displayName'], 'team_id' => $t['id'], 'canaux' => collect($chans)->map(fn ($c) => ['nom' => $c['displayName'], 'channel_id' => $c['id']])->all()];
                }

                return $out;
            case 'read_channel':
                $msgs = $this->ok($http->get(self::API.'/teams/'.$args['team_id'].'/channels/'.$args['channel_id'].'/messages', ['$top' => 20]))['value'] ?? [];

                return collect($msgs)->map(fn ($m) => ['de' => data_get($m, 'from.user.displayName'), 'date' => $m['createdDateTime'], 'texte' => trim(strip_tags((string) data_get($m, 'body.content')))])->all();
            case 'post_message':
                $this->ok($http->post(self::API.'/teams/'.$args['team_id'].'/channels/'.$args['channel_id'].'/messages', ['body' => ['content' => $args['text']]]));

                return ['statut' => 'Message publié'];
        }
        $this->unknown($tool);
    }
}
