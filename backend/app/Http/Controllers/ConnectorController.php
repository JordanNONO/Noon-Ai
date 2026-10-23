<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Connectors\OAuth;
use App\Models\Connector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConnectorController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $list = Connector::with('agents')->orderByDesc('is_connected')->orderBy('name')->get()
            ->filter(fn ($c) => ! $c->is_sensitive || $user->isAdmin());

        return [
            'connectors' => $list->map->toPublic()->values(),
            'types' => ConnectorRegistry::types(),
            'can_manage' => $user->isAdmin(),
        ];
    }

    /** Connecteur personnalisé (administrateurs). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'type' => ['required', Rule::in(array_keys(ConnectorRegistry::all()))],
            'description' => 'required|string|max:200',
            'is_sensitive' => 'sometimes|boolean',
        ]);
        $c = Connector::create($data + [
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'is_custom' => true,
            'letter' => mb_strtoupper(mb_substr($data['name'], 0, 1)),
            'hue' => 'stone',
        ]);

        return ['connector' => $c->load('agents')->toPublic()];
    }

    public function update(Request $request, Connector $connector)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:80',
            'description' => 'sometimes|string|max:200',
            'perms' => 'sometimes|array',
            'perms.read' => 'sometimes|in:always,ask,block',
            'perms.write' => 'sometimes|in:always,ask,block',
            'perms.send' => 'sometimes|in:always,ask,block',
            'is_sensitive' => 'sometimes|boolean',
            'agent_ids' => 'sometimes|array',
            'agent_ids.*' => 'integer|exists:agents,id',
            'config' => 'sometimes|array',
        ]);
        foreach (['read', 'write', 'send'] as $lvl) {
            if (isset($data['perms'][$lvl])) {
                $connector->{'perm_'.$lvl} = $data['perms'][$lvl];
            }
        }
        $connector->fill(collect($data)->only(['name', 'description', 'is_sensitive'])->all());
        if (isset($data['config'])) {
            $connector->config = $this->mergeConfig($connector, $data['config']);
        }
        $connector->save();
        if (isset($data['agent_ids'])) {
            $connector->agents()->sync($data['agent_ids']);
        }

        return ['connector' => $connector->load('agents')->toPublic()];
    }

    /** Identifiants → test réel → connecté. Pour Google : renvoie l'adresse d'autorisation. */
    public function connect(Request $request, Connector $connector)
    {
        $def = $connector->definition();
        if ($def['auth'] === 'oauth_google') {
            abort_unless(ConnectorRegistry::oauthReady($def['auth']), 422, 'La connexion Google n\'est pas configurée sur le serveur (GOOGLE_CLIENT_ID).');
            $state = Str::random(40);
            Cache::put('oauth:'.$state, ['connector_id' => $connector->id, 'user_id' => $request->user()->id], 600);

            return ['redirect' => OAuth::authorizeUrl($connector, $state)];
        }

        $connector->config = $this->mergeConfig($connector, $request->input('config', []));
        foreach ($def['fields'] as $f) {
            if (! empty($f['required']) && empty($connector->config[$f['key']])) {
                return response()->json(['message' => 'Champ requis : '.$f['label']], 422);
            }
        }
        try {
            $message = ConnectorRegistry::driver($connector->type)->test($connector);
        } catch (\Throwable $e) {
            $connector->save();

            return response()->json(['message' => 'Connexion impossible : '.$e->getMessage()], 422);
        }
        $connector->fill(['is_connected' => true, 'connected_by' => $request->user()->id, 'connected_at' => now()])->save();

        return ['connector' => $connector->load('agents')->toPublic(), 'message' => $message];
    }

    public function disconnect(Connector $connector)
    {
        $connector->update(['is_connected' => false, 'config' => null, 'connected_at' => null, 'connected_by' => null]);

        return ['connector' => $connector->load('agents')->toPublic()];
    }

    public function destroy(Connector $connector)
    {
        abort_unless($connector->is_custom, 422, 'Seuls les connecteurs personnalisés peuvent être supprimés.');
        $connector->delete();

        return response()->noContent();
    }

    /** Retour de Google après autorisation (route web). */
    public function oauthCallback(Request $request)
    {
        $front = rtrim(config('noon.frontend_url'), '/').'/connecteurs';
        $ctx = Cache::pull('oauth:'.$request->query('state'));
        if (! $ctx || ! $request->query('code')) {
            return redirect($front.'?oauth=erreur');
        }
        $connector = Connector::find($ctx['connector_id']);
        try {
            OAuth::exchange($connector, $request->query('code'));
            ConnectorRegistry::driver($connector->type)->test($connector);
            $connector->fill(['is_connected' => true, 'connected_by' => $ctx['user_id'], 'connected_at' => now()])->save();
        } catch (\Throwable) {
            return redirect($front.'?oauth=erreur&id='.$connector?->id);
        }

        return redirect($front.'?oauth=ok&id='.$connector->id);
    }

    /** Les champs secrets laissés vides ou masqués gardent leur valeur. */
    private function mergeConfig(Connector $c, array $incoming): array
    {
        $cfg = $c->config ?? [];
        foreach ($c->definition()['fields'] ?? [] as $f) {
            if (! array_key_exists($f['key'], $incoming)) {
                continue;
            }
            $v = $incoming[$f['key']];
            if (! empty($f['secret']) && ($v === '' || $v === '__set__' || $v === null)) {
                continue;
            }
            $cfg[$f['key']] = is_string($v) ? trim($v) : $v;
        }

        return $cfg;
    }
}
