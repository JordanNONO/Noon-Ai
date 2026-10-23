<?php

namespace App\Agents;

use App\Connectors\ConnectorRegistry;
use App\Models\Agent;
use App\Models\Connector;
use App\Models\Conversation;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Outils mis à disposition d'un agent pour une demande donnée :
 * connecteurs de l'agent ∩ connecteurs choisis dans la conversation,
 * connectés et autorisés pour l'utilisateur. Les politiques
 * « Toujours autoriser / Demander à chaque fois / Bloquer » sont appliquées ici.
 */
class ToolBelt
{
    /** @var array<string, array{connector: ?Connector, tool: string, def: array}> */
    private array $map = [];

    /** @var Collection<int, Connector> */
    public Collection $connectors;

    public ?array $document = null;

    public function __construct(
        public Agent $agent,
        public User $user,
        public Conversation $conversation,
        ?array $selectedConnectorIds,
        bool $allConnectors = false,
    ) {
        $base = $allConnectors ? Connector::query() : $agent->connectors();
        $q = $base->where('is_connected', true)->get();
        if ($selectedConnectorIds !== null) {
            $q = $q->whereIn('id', $selectedConnectorIds);
        }
        $this->connectors = $q->filter(fn (Connector $c) => $c->usableBy($user))->values();
    }

    public function definitions(): array
    {
        $defs = [[
            'name' => 'create_document',
            'description' => 'Ouvre un document dans le panneau à droite de la conversation (brouillon, rapport, newsletter…). Utilisez-le pour tout livrable de plus de quelques paragraphes.',
            'parameters' => ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string', 'description' => 'Titre du document'],
                'body' => ['type' => 'string', 'description' => 'Contenu en Markdown'],
            ], 'required' => ['title', 'body']],
        ]];
        $this->map['create_document'] = ['connector' => null, 'tool' => 'create_document', 'def' => ['level' => 'read', 'label' => 'Document', 'required' => ['title', 'body']]];

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
            return 'Document — '.($args['title'] ?? 'brouillon');
        }
        $hint = $args['query'] ?? $args['search'] ?? $args['path'] ?? $args['to'] ?? $args['title'] ?? $args['summary'] ?? $args['table'] ?? null;

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
            $this->document = ['title' => (string) $args['title'], 'body' => (string) $args['body']];

            return ['status' => 'done', 'content' => 'Document ouvert dans le panneau de droite.'];
        }

        $c = $m['connector'];
        $policy = $c->permissionFor($m['def']['level']);
        // L'exécution de commandes distantes est toujours soumise à accord
        if ($c->type === 'ssh') {
            $policy = 'ask';
        }
        if ($policy === 'ask') {
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
                'content' => "Action préparée mais NON exécutée : elle attend l'accord de l'utilisateur (demande n° {$pending->id}). Indiquez-lui ce qui sera fait et qu'il peut confirmer avec le bouton affiché.",
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

    /** Exécute une action approuvée par l'utilisateur. */
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
