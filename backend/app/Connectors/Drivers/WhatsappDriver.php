<?php

namespace App\Connectors\Drivers;

use App\Connectors\ConnectorDriver;
use App\Models\Connector;
use Illuminate\Support\Facades\Http;

class WhatsappDriver extends ConnectorDriver
{
    private function base(Connector $c): string
    {
        return 'https://graph.facebook.com/v21.0/'.$this->cfg($c, 'phone_number_id');
    }

    public function test(Connector $connector): string
    {
        $res = Http::withToken($this->cfg($connector, 'access_token'))->timeout(20)->get($this->base($connector));
        if (! $res->successful()) {
            throw new \RuntimeException('WhatsApp refuse la connexion : '.($res->json('error.message') ?? $res->status()));
        }

        return 'Numéro '.($res->json('display_phone_number') ?? 'WhatsApp Business').' relié';
    }

    public function execute(Connector $connector, string $tool, array $args): mixed
    {
        if ($tool !== 'send_message') {
            $this->unknown($tool);
        }
        $res = Http::withToken($this->cfg($connector, 'access_token'))->timeout(20)->post($this->base($connector).'/messages', [
            'messaging_product' => 'whatsapp',
            'to' => preg_replace('/\D/', '', $args['to']),
            'type' => 'text',
            'text' => ['body' => $args['text']],
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('Envoi WhatsApp impossible : '.($res->json('error.message') ?? $res->status()));
        }

        return ['statut' => 'Message envoyé'];
    }
}
