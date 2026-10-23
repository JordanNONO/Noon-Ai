<?php

namespace App\Agents;

use App\Connectors\ConnectorRegistry;
use App\Models\Agent;
use App\Models\Connector;
use App\Models\Conversation;
use App\Models\KnowledgeEntry;
use App\Models\Language;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Outils mis à disposition d'un assistant pour une demande :
 *  - outils intégrés (bibliothèque des savoirs, document) ;
 *  - outils des connecteurs : ceux de l'assistant (tous pour Noon) ∩ ceux choisis dans la conversation,
 *    connectés et autorisés pour la personne. Les politiques « Toujours autoriser /
 *    Demander à chaque fois / Bloquer » sont appliquées ici.
 */
class ToolBelt
{
    /** @var array<string, array{connector: ?Connector, tool: string, def: array}> */
    private array $map = [];

    /** @var Collection<int, Connector> */
    public Collection $connectors;

    public ?array $document = null;

    /** @var array<int, int> identifiants des savoirs consultés */
    public array $sourceIds = [];

    public function __construct(
        public Agent $agent,
        public User $user,
        public Conversation $conversation,
        ?array $selectedConnectorIds,
    ) {
        $base = $agent->is_default ? Connector::query() : $agent->connectors();
        $list = $base->where('is_connected', true)->get();
        if ($selectedConnectorIds !== null) {
            $list = $list->whereIn('id', $selectedConnectorIds);
        }
        $this->connectors = $list->filter(fn (Connector $c) => $c->usableBy($user))->values();
    }

    public function definitions(): array
    {
        $types = implode(', ', array_keys(KnowledgeEntry::TYPES));
        $langs = Language::where('is_active', true)->pluck('name', 'code')->map(fn ($n, $c) => "{$c} = {$n}")->join(', ');

        $builtin = [
            'search_knowledge' => [
                'def' => ['level' => 'read', 'label' => 'Bibliothèque des savoirs', 'required' => ['query']],
                'description' => "Cherche dans la Bibliothèque des savoirs de Noon (contenus validés par la communauté : mots, proverbes, contes, recettes, coutumes, histoire). À utiliser avant de traduire, d'écrire en langue bamiléké ou d'affirmer quoi que ce soit sur la culture. Langues : {$langs}.",
                'params' => [
                    'query' => ['type' => 'string', 'description' => 'Mots-clés, en français ou dans la langue'],
                    'language' => ['type' => 'string', 'description' => 'Code de langue (optionnel)'],
                    'type' => ['type' => 'string', 'description' => "Type (optionnel) : {$types}"],
                ],
            ],
            'propose_knowledge' => [
                'def' => ['level' => 'read', 'label' => 'Proposer un savoir', 'required' => ['type', 'language', 'title', 'content']],
                'description' => "Enregistre un savoir que la personne vient d'enseigner (mot, proverbe, recette…). Il sera vérifié par un modérateur avant d'être utilisé. N'utilisez cet outil que si la personne transmet volontairement une connaissance.",
                'params' => [
                    'type' => ['type' => 'string', 'description' => "Type : {$types}"],
                    'language' => ['type' => 'string', 'description' => 'Code de la langue du texte original'],
                    'title' => ['type' => 'string', 'description' => 'Titre court'],
                    'content' => ['type' => 'string', 'description' => 'Texte original'],
                    'translation' => ['type' => 'string', 'description' => 'Traduction française'],
                    'meaning' => ['type' => 'string', 'description' => 'Sens, explication ou contexte'],
                    'village' => ['type' => 'string', 'description' => 'Village ou région d\'origine (optionnel)'],
                ],
            ],
            'create_document' => [
                'def' => ['level' => 'read', 'label' => 'Document', 'required' => ['title', 'body']],
                'description' => 'Ouvre un document dans le panneau à droite de la conversation (lettre, discours, programme de cérémonie, recette détaillée…). Utilisez-le pour tout texte de plus de quelques paragraphes.',
                'params' => [
                    'title' => ['type' => 'string', 'description' => 'Titre du document'],
                    'body' => ['type' => 'string', 'description' => 'Contenu en Markdown'],
                ],
            ],
        ];

        $defs = [];
        foreach ($builtin as $name => $b) {
            $this->map[$name] = ['connector' => null, 'tool' => $name, 'def' => $b['def']];
            $defs[] = ['name' => $name, 'description' => $b['description'], 'parameters' => ['type' => 'object', 'properties' => $b['params'], 'required' => $b['def']['required']]];
        }

        foreach ($this->connectors as $c) {
            foreach ($c->definition()['tools'] ?? [] as $tool => $def) {
                if ($c->permissionFor($def['level']) === 'block') {
                    continue;
                }
                $name = Str::limit(preg_replace('/[^a-zA-Z0-9_]/', '_', $c->slug).'__'.$tool, 64, '');
                $this->map[$name] = ['connector' => $c, 'tool' => $tool, 'def' => $def];
                $extra = $c->type === 'http' && ($c->config['instructions'] ?? null) ? ' Mode d\'emploi : '.$c->config['instructions'] : '';
                $defs[] = [
                    'name' => $name,
                    'description' => "[{$c->name}] {$def['description']}{$extra}",
                    'parameters' => ['type' => 'object', 'properties' => (object) $def['params'], 'required' => $def['required'] ?? []],
                ];
            }
        }

        return $defs;
    }

    public function label(string $name, array $args): string
    {
        $m = $this->map[$name] ?? null;
        if (! $m) {
            return $name;
        }
        if (! $m['connector']) {
            return match ($name) {
                'search_knowledge' => 'Bibliothèque des savoirs — « '.mb_strimwidth((string) ($args['query'] ?? ''), 0, 50, '…').' »',
                'propose_knowledge' => 'Bibliothèque des savoirs — nouveau savoir proposé : « '.mb_strimwidth((string) ($args['title'] ?? ''), 0, 50, '…').' »',
                default => 'Document — '.($args['title'] ?? 'brouillon'),
            };
        }
        $hint = $args['query'] ?? $args['search'] ?? $args['path'] ?? $args['to'] ?? $args['title'] ?? $args['summary'] ?? null;

        return $m['connector']->name.' — '.mb_strtolower($m['def']['label']).($hint && is_string($hint) ? ' « '.mb_strimwidth($hint, 0, 60, '…').' »' : '');
    }

    public function connectorFor(string $name): ?Connector
    {
        return $this->map[$name]['connector'] ?? null;
    }

    /**
     * Exécute l'appel ou le met en attente d'accord.
     *
     * @return array{status: string, content: string, pending?: PendingAction}
     */
    public function call(string $name, array $args, ?int $messageId): array
    {
        $m = $this->map[$name] ?? null;
        if (! $m) {
            return ['status' => 'error', 'content' => "Outil inconnu : {$name}"];
        }
        if (isset($args['__invalid_json'])) {
            return ['status' => 'error', 'content' => 'Arguments JSON invalides, recommencez l\'appel.'];
        }
        foreach ($m['def']['required'] ?? [] as $req) {
            if (! isset($args[$req]) || $args[$req] === '') {
                return ['status' => 'error', 'content' => "Paramètre manquant : {$req}"];
            }
        }

        if (! $m['connector']) {
            return $this->builtin($name, $args);
        }

        $c = $m['connector'];
        if ($c->permissionFor($m['def']['level']) === 'ask') {
            $pending = PendingAction::create([
                'conversation_id' => $this->conversation->id,
                'message_id' => $messageId,
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'connector_id' => $c->id,
                'kind' => 'tool',
                'tool' => $m['tool'],
                'payload' => $args,
                'summary' => $this->label($name, $args),
                'status' => 'pending',
            ]);

            return [
                'status' => 'pending',
                'content' => "Action préparée mais NON exécutée : elle attend l'accord de la personne (demande n° {$pending->id}). Dites-lui simplement ce qui sera fait et qu'elle peut confirmer avec le bouton « Confirmer ».",
                'pending' => $pending,
            ];
        }

        try {
            $result = ConnectorRegistry::driver($c->type)->execute($c, $m['tool'], $args);

            return ['status' => 'done', 'content' => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'content' => 'Erreur : '.$e->getMessage()];
        }
    }

    private function builtin(string $name, array $args): array
    {
        if ($name === 'create_document') {
            $this->document = ['title' => (string) $args['title'], 'body' => (string) $args['body']];

            return ['status' => 'done', 'content' => 'Document ouvert dans le panneau de droite.'];
        }

        if ($name === 'search_knowledge') {
            $entries = self::searchKnowledge((string) $args['query'], $args['language'] ?? null, $args['type'] ?? null, 8);
            $this->sourceIds = array_values(array_unique(array_merge($this->sourceIds, $entries->pluck('id')->all())));

            return ['status' => 'done', 'content' => $entries->isEmpty()
                ? 'Aucun savoir validé trouvé. Dites-le honnêtement et proposez à la personne de l\'ajouter à la bibliothèque si elle le connaît.'
                : json_encode($entries->map->forModel()->values(), JSON_UNESCAPED_UNICODE)];
        }

        // propose_knowledge
        $type = array_key_exists($args['type'], KnowledgeEntry::TYPES) ? $args['type'] : 'expression';
        $lang = Language::find($args['language']) ? $args['language'] : ($this->user->language ?: 'fr');
        $entry = KnowledgeEntry::create([
            'type' => $type, 'language' => $lang, 'title' => mb_substr($args['title'], 0, 190),
            'content' => $args['content'], 'translation' => $args['translation'] ?? null, 'meaning' => $args['meaning'] ?? null,
            'village' => $args['village'] ?? null, 'author_id' => $this->user->id, 'status' => 'pending',
        ]);

        return ['status' => 'done', 'content' => "Savoir n° {$entry->id} enregistré. Il sera vérifié par un modérateur avant d'être utilisé. Remerciez chaleureusement la personne."];
    }

    /** Recherche partagée par l'outil et par le pré-chargement de contexte. */
    public static function searchKnowledge(string $query, ?string $language = null, ?string $type = null, int $limit = 6): Collection
    {
        return KnowledgeEntry::approved()
            ->when($language && $language !== 'fr', fn ($q) => $q->where('language', $language))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->search($query)
            ->orderByDesc('uses')
            ->limit($limit)
            ->get();
    }

    /** Exécute une action approuvée par la personne. */
    public static function executeApproved(PendingAction $p): array
    {
        $c = $p->connector;
        if (! $c || ! $c->is_connected) {
            throw new \RuntimeException('Le connecteur n\'est plus disponible.');
        }
        $result = ConnectorRegistry::driver($c->type)->execute($c, $p->tool, $p->payload ?? []);

        return is_array($result) ? $result : ['resultat' => $result];
    }
}
