<?php

namespace App\Http\Controllers;

use App\Billing\Quota;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Combo;
use App\Models\Connector;
use App\Models\KnowledgeEntry;
use App\Models\Language;
use App\Support\SpeechClient;
use Illuminate\Http\Request;

/** Tout ce dont l'interface a besoin au démarrage : mêmes chiffres partout (barre latérale, accueil, mobile). */
class BootstrapController extends Controller
{
    public function __invoke(Request $request, SpeechClient $speech)
    {
        $user = $request->user()->load('group');
        $user->update(['last_seen_at' => now()]);

        $conversations = $user->conversations()->with('agent')->orderByDesc('last_message_at')->orderByDesc('id')->limit(40)->get();
        $connected = Connector::where('is_connected', true)->get()->filter(fn ($c) => $c->usableBy($user))->values();

        return [
            'user' => $user->toPublic(),
            'default_agent' => Agent::default()->load('connectors')->toPublic($user),
            'counts' => [
                'agents' => Agent::accessibleBy($user)->count(),
                'combos' => Combo::count(),
                'connectors_connected' => $connected->count(),
                'knowledge_approved' => KnowledgeEntry::approved()->count(),
                'knowledge_pending' => $user->canModerate() ? KnowledgeEntry::where('status', 'pending')->count() : 0,
            ],
            'connectors' => $connected->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'short' => $c->shortName(), 'letter' => $c->letter, 'hue' => $c->hue])->values(),
            'models' => AiModel::where('is_enabled', true)->orderBy('priority')->get()->filter->isConfigured()
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'family' => $m->family, 'label' => $m->name.' · '.AiModel::FAMILIES[$m->family]['label']])->values(),
            'languages' => LanguageController::list($speech),
            'pinned' => $conversations->where('is_pinned', true)->map->toSummary()->values(),
            'recent' => $conversations->map->toSummary()->values(),
            'favorite_combos' => Combo::where('is_favorite', true)->limit(6)->get()->map->toPublic()->values(),
            'speech' => ['online' => $speech->capabilities()['online']],
            'quota' => Quota::summary($user),
        ];
    }
}
