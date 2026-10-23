<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Group;
use App\Models\UsageLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Consommation de tokens, par groupe et par personne — calculée depuis le journal réel. */
class UsageController extends Controller
{
    private function range(string $period): array
    {
        $end = CarbonImmutable::now()->endOfDay();
        $days = match ($period) {
            '7j' => 7,
            'trimestre' => 90,
            default => 30,
        };

        return [$end->subDays($days - 1)->startOfDay(), $end, $days];
    }

    public function index(Request $request)
    {
        $period = $request->query('period', '30j');
        $groupId = $request->query('group_id') ? (int) $request->query('group_id') : null;
        [$from, $to, $nDays] = $this->range($period);

        $base = UsageLog::whereBetween('created_at', [$from, $to]);
        $total = (int) (clone $base)->sum(DB::raw('tokens_in + tokens_out'));
        $cost = (float) (clone $base)->sum('cost');
        $activeUsers = (clone $base)->distinct('user_id')->count('user_id');

        $byGroup = (clone $base)->select('group_id', DB::raw('SUM(tokens_in + tokens_out) as t'))->groupBy('group_id')->pluck('t', 'group_id');
        $groups = Group::orderBy('name')->get()->map(fn ($g) => [
            'id' => $g->id, 'name' => $g->name, 'tokens' => (int) ($byGroup[$g->id] ?? 0), 'budget' => $g->monthly_token_budget,
        ]);
        if ($byGroup->has('')) {
            $groups->push(['id' => 0, 'name' => 'Sans groupe', 'tokens' => (int) $byGroup[''], 'budget' => null]);
        }
        $groups = $groups->sortByDesc('tokens')->values();

        $scoped = (clone $base)->when($groupId !== null, fn ($q) => $groupId === 0 ? $q->whereNull('group_id') : $q->where('group_id', $groupId));

        $perDay = (clone $scoped)->select(DB::raw('DATE(created_at) as d'), DB::raw('SUM(tokens_in + tokens_out) as t'))->groupBy('d')->pluck('t', 'd');
        $days = [];
        for ($i = 0; $i < $nDays; $i++) {
            $d = $from->addDays($i)->toDateString();
            $days[] = ['date' => $d, 'tokens' => (int) ($perDay[$d] ?? 0)];
        }

        $perUser = (clone $scoped)->select('user_id', DB::raw('SUM(tokens_in + tokens_out) as t'), DB::raw('COUNT(DISTINCT conversation_id) as c'))
            ->groupBy('user_id')->orderByDesc('t')->get();
        $users = User::with('group')->whereIn('id', $perUser->pluck('user_id'))->get()->keyBy('id');
        $topAgents = (clone $scoped)->select('user_id', 'agent_id', DB::raw('SUM(tokens_in + tokens_out) as t'))->groupBy('user_id', 'agent_id')->get()
            ->groupBy('user_id')->map(fn ($rows) => $rows->sortByDesc('t')->first()->agent_id);
        $agentNames = Agent::whereIn('id', $topAgents->values()->filter())->pluck('name', 'id');
        $scopeTotal = max(1, (int) $perUser->sum('t'));

        // Alerte de budget : comparé au mois en cours
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $monthByGroup = UsageLog::where('created_at', '>=', $monthStart)->select('group_id', DB::raw('SUM(tokens_in + tokens_out) as t'))->groupBy('group_id')->pluck('t', 'group_id');
        $alerts = Group::whereNotNull('monthly_token_budget')->get()
            ->filter(fn ($g) => ($monthByGroup[$g->id] ?? 0) > $g->monthly_token_budget)
            ->map(fn ($g) => ['group' => $g->name, 'used' => (int) $monthByGroup[$g->id], 'budget' => $g->monthly_token_budget])->values();

        return [
            'period' => $period,
            'tiles' => [
                'tokens' => $total,
                'cost' => round($cost, 2),
                'active_users' => $activeUsers,
                'groups' => Group::count(),
                'average' => $activeUsers ? (int) round($total / $activeUsers) : 0,
            ],
            'groups' => $groups,
            'days' => $days,
            'people' => $perUser->map(function ($row) use ($users, $topAgents, $agentNames, $scopeTotal) {
                $u = $users[$row->user_id] ?? null;

                return [
                    'id' => $row->user_id,
                    'name' => $u?->shortName() ?? '—',
                    'initials' => $u?->initials() ?? '—',
                    'group' => $u?->group?->name ?? 'Sans groupe',
                    'tokens' => (int) $row->t,
                    'share' => round($row->t / $scopeTotal * 100),
                    'top_agent' => $agentNames[$topAgents[$row->user_id] ?? 0] ?? '—',
                    'conversations' => (int) $row->c,
                ];
            })->values(),
            'alerts' => $alerts,
        ];
    }

    public function export(Request $request)
    {
        [$from, $to] = $this->range($request->query('period', '30j'));
        $rows = UsageLog::with(['agent:id,name'])->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get();
        $users = User::with('group')->whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');

        return response()->streamDownload(function () use ($rows, $users) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM pour Excel
            fputcsv($out, ['Date', 'Personne', 'Groupe', 'Assistant', 'Tokens entrée', 'Tokens sortie', 'Coût estimé (€)'], ';');
            foreach ($rows as $r) {
                $u = $users[$r->user_id] ?? null;
                fputcsv($out, [$r->created_at->format('Y-m-d H:i'), $u?->name, $u?->group?->name, $r->agent?->name, $r->tokens_in, $r->tokens_out, number_format($r->cost, 4, ',', '')], ';');
            }
            fclose($out);
        }, 'noon-consommation-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
