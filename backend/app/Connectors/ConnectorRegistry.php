<?php

namespace App\Connectors;

use App\Connectors\Drivers\GmailDriver;
use App\Connectors\Drivers\GoogleCalendarDriver;
use App\Connectors\Drivers\GoogleDriveDriver;
use App\Connectors\Drivers\HttpDriver;
use App\Connectors\Drivers\WhatsappDriver;
use App\Connectors\Drivers\WordpressDriver;

/**
 * Catalogue des types de connecteurs : champs de configuration,
 * mode d'authentification et outils exposés aux assistants.
 *
 * Niveau d'un outil : read (lire), write (créer / modifier), send (envoyer / publier).
 * Chaque niveau suit la politique du connecteur : always | ask | block.
 */
class ConnectorRegistry
{
    public static function all(): array
    {
        $s = fn (string $desc) => ['type' => 'string', 'description' => $desc];
        $i = fn (string $desc) => ['type' => 'integer', 'description' => $desc];

        return [
            'gmail' => [
                'label' => 'Gmail', 'short' => 'Gmail', 'auth' => 'oauth_google', 'driver' => GmailDriver::class,
                'scopes' => ['https://www.googleapis.com/auth/gmail.modify', 'https://www.googleapis.com/auth/gmail.send'],
                'fields' => [],
                'tools' => [
                    'search_emails' => ['label' => 'Rechercher des e-mails', 'level' => 'read', 'description' => 'Recherche des e-mails (syntaxe Gmail : is:unread, from:, newer_than:1d…).', 'params' => ['query' => $s('Requête Gmail'), 'max' => $i('Nombre maximum (défaut 10)')], 'required' => ['query']],
                    'read_email' => ['label' => 'Lire un e-mail', 'level' => 'read', 'description' => 'Lit le contenu complet d\'un e-mail.', 'params' => ['id' => $s('Identifiant du message')], 'required' => ['id']],
                    'create_draft' => ['label' => 'Rédiger un brouillon', 'level' => 'write', 'description' => 'Crée un brouillon d\'e-mail sans l\'envoyer.', 'params' => ['to' => $s('Destinataire(s), séparés par des virgules'), 'subject' => $s('Objet'), 'body' => $s('Corps du message (texte)')], 'required' => ['to', 'subject', 'body']],
                    'send_email' => ['label' => 'Envoyer un e-mail', 'level' => 'send', 'description' => 'Envoie un e-mail.', 'params' => ['to' => $s('Destinataire(s)'), 'subject' => $s('Objet'), 'body' => $s('Corps du message (texte)')], 'required' => ['to', 'subject', 'body']],
                ],
            ],
            'google_drive' => [
                'label' => 'Google Drive', 'short' => 'Drive', 'auth' => 'oauth_google', 'driver' => GoogleDriveDriver::class,
                'scopes' => ['https://www.googleapis.com/auth/drive.readonly'],
                'fields' => [],
                'tools' => [
                    'search_files' => ['label' => 'Rechercher des documents', 'level' => 'read', 'description' => 'Recherche des fichiers par nom ou contenu.', 'params' => ['query' => $s('Mots-clés')], 'required' => ['query']],
                    'read_file' => ['label' => 'Lire un document', 'level' => 'read', 'description' => 'Lit le texte d\'un fichier (Docs, Sheets, PDF, texte).', 'params' => ['id' => $s('Identifiant du fichier')], 'required' => ['id']],
                ],
            ],
            'google_calendar' => [
                'label' => 'Google Agenda', 'short' => 'Agenda', 'auth' => 'oauth_google', 'driver' => GoogleCalendarDriver::class,
                'scopes' => ['https://www.googleapis.com/auth/calendar'],
                'fields' => [],
                'tools' => [
                    'list_events' => ['label' => 'Consulter l\'agenda', 'level' => 'read', 'description' => 'Liste les événements entre deux dates (ISO 8601).', 'params' => ['from' => $s('Début, ex. 2026-10-23T08:00:00+01:00'), 'to' => $s('Fin')], 'required' => ['from', 'to']],
                    'free_busy' => ['label' => 'Vérifier les disponibilités', 'level' => 'read', 'description' => 'Renvoie les créneaux occupés de plusieurs personnes.', 'params' => ['emails' => $s('Adresses séparées par des virgules'), 'from' => $s('Début ISO'), 'to' => $s('Fin ISO')], 'required' => ['emails', 'from', 'to']],
                    'create_event' => ['label' => 'Créer un rendez-vous', 'level' => 'send', 'description' => 'Crée un événement et envoie les invitations.', 'params' => ['summary' => $s('Titre'), 'start' => $s('Début ISO'), 'end' => $s('Fin ISO'), 'attendees' => $s('Invités, séparés par des virgules'), 'description' => $s('Description')], 'required' => ['summary', 'start', 'end']],
                ],
            ],
            'whatsapp' => [
                'label' => 'WhatsApp Business', 'short' => 'WhatsApp', 'auth' => 'credentials', 'driver' => WhatsappDriver::class,
                'fields' => [
                    ['key' => 'access_token', 'label' => 'Jeton d\'accès (Meta Cloud API)', 'secret' => true, 'required' => true],
                    ['key' => 'phone_number_id', 'label' => 'Identifiant du numéro', 'required' => true],
                ],
                'tools' => [
                    'send_message' => ['label' => 'Envoyer un message', 'level' => 'send', 'description' => 'Envoie un message WhatsApp à un numéro (format international, ex. 237690000000).', 'params' => ['to' => $s('Numéro du destinataire'), 'text' => $s('Message')], 'required' => ['to', 'text']],
                ],
            ],
            'wordpress' => [
                'label' => 'WordPress', 'short' => 'WordPress', 'auth' => 'credentials', 'driver' => WordpressDriver::class,
                'fields' => [
                    ['key' => 'site_url', 'label' => 'Adresse du site', 'placeholder' => 'https://blog.exemple.cm', 'required' => true],
                    ['key' => 'username', 'label' => 'Identifiant', 'required' => true],
                    ['key' => 'app_password', 'label' => 'Mot de passe d\'application', 'secret' => true, 'required' => true],
                ],
                'tools' => [
                    'list_posts' => ['label' => 'Lister les articles', 'level' => 'read', 'description' => 'Articles publiés, filtrables par recherche et par date.', 'params' => ['search' => $s('Mots-clés (optionnel)'), 'after' => $s('Date ISO minimale (optionnel)'), 'max' => $i('Nombre maximum')], 'required' => []],
                    'get_post' => ['label' => 'Lire un article', 'level' => 'read', 'description' => 'Contenu complet d\'un article.', 'params' => ['id' => $i('Identifiant')], 'required' => ['id']],
                    'create_draft' => ['label' => 'Créer un brouillon', 'level' => 'write', 'description' => 'Crée un article en brouillon.', 'params' => ['title' => $s('Titre'), 'content' => $s('Contenu HTML')], 'required' => ['title', 'content']],
                    'publish_post' => ['label' => 'Publier un article', 'level' => 'send', 'description' => 'Publie un brouillon existant.', 'params' => ['id' => $i('Identifiant')], 'required' => ['id']],
                ],
            ],
            'http' => [
                'label' => 'API personnalisée', 'short' => 'API', 'auth' => 'credentials', 'driver' => HttpDriver::class,
                'fields' => [
                    ['key' => 'base_url', 'label' => 'Adresse de base de l\'API', 'placeholder' => 'https://api.exemple.cm/v1', 'required' => true],
                    ['key' => 'auth_header', 'label' => 'En-tête d\'authentification', 'placeholder' => 'Authorization'],
                    ['key' => 'auth_value', 'label' => 'Valeur de l\'en-tête', 'secret' => true, 'placeholder' => 'Bearer …'],
                    ['key' => 'instructions', 'label' => 'Mode d\'emploi pour les assistants', 'multiline' => true, 'placeholder' => 'Points d\'accès disponibles, paramètres…'],
                ],
                'tools' => [
                    'get' => ['label' => 'Lire (GET)', 'level' => 'read', 'description' => 'Requête GET sur l\'API.', 'params' => ['path' => $s('Chemin, ex. /articles?langue=bbj')], 'required' => ['path']],
                    'post' => ['label' => 'Écrire (POST/PUT/PATCH)', 'level' => 'write', 'description' => 'Requête d\'écriture.', 'params' => ['method' => $s('POST, PUT ou PATCH'), 'path' => $s('Chemin'), 'body' => $s('Corps JSON')], 'required' => ['path']],
                ],
            ],
        ];
    }

    public static function definition(string $type): array
    {
        return self::all()[$type] ?? ['label' => $type, 'short' => $type, 'auth' => 'credentials', 'fields' => [], 'tools' => []];
    }

    public static function types(): array
    {
        return collect(self::all())->map(fn ($d, $k) => [
            'type' => $k, 'label' => $d['label'], 'auth' => $d['auth'], 'fields' => $d['fields'], 'oauth_ready' => self::oauthReady($d['auth']),
        ])->values()->all();
    }

    public static function oauthReady(string $auth): bool
    {
        return $auth !== 'oauth_google' || (bool) config('noon.google.client_id');
    }

    public static function driver(string $type): ConnectorDriver
    {
        $class = self::definition($type)['driver'] ?? null;
        if (! $class) {
            throw new \RuntimeException("Type de connecteur inconnu : {$type}");
        }

        return app($class);
    }
}
