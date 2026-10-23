<?php

namespace Database\Seeders;

use App\Models\Connector;
use Illuminate\Database\Seeder;

/** Connecteurs proposés. Aucun n'est connecté au départ : un administrateur les relie. */
class ConnectorSeeder extends Seeder
{
    public function run(): void
    {
        $list = [
            ['gmail', 'Gmail', 'gmail', 'G', 'indigo', 'Lire, rechercher et rédiger des e-mails.'],
            ['google-drive', 'Google Drive', 'google_drive', 'D', 'ochre', 'Retrouver et lire des documents partagés (actes, programmes, photos de famille).'],
            ['google-agenda', 'Google Agenda', 'google_calendar', 'A', 'clay', 'Consulter et créer des rendez-vous (réunions de famille, cérémonies).'],
            ['whatsapp', 'WhatsApp Business', 'whatsapp', 'W', 'indigo', 'Envoyer des messages à la famille ou à l\'association.'],
            ['wordpress', 'WordPress', 'wordpress', 'W', 'stone', 'Publier sur le site de l\'association ou du village.'],
        ];
        foreach ($list as [$slug, $name, $type, $letter, $hue, $desc]) {
            Connector::updateOrCreate(['slug' => $slug], ['name' => $name, 'type' => $type, 'letter' => $letter, 'hue' => $hue, 'description' => $desc]);
        }
    }
}
