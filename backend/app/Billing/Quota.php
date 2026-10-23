<?php

namespace App\Billing;

use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Offre effective d'une personne et ce qu'il lui reste aujourd'hui.
 * Une offre « Famille » couvre tous les membres du groupe de son titulaire.
 * Les administrateurs ne sont jamais limités.
 */
class Quota
{
    public static function subscription(User $user): ?Subscription
    {
        return Subscription::current()->with('plan')
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->group_id, fn ($w) => $w->orWhere('group_id', $user->group_id)))
            ->orderByDesc('current_period_end')
            ->first();
    }

    public static function plan(User $user): Plan
    {
        return self::subscription($user)?->plan ?? Plan::free();
    }

    public static function messagesToday(User $user): int
    {
        return Message::where('role', 'user')->where('created_at', '>=', now()->startOfDay())
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))->count();
    }

    public static function voiceToday(User $user): int
    {
        return (int) Cache::get(self::voiceKey($user), 0);
    }

    public static function countVoice(User $user): void
    {
        $key = self::voiceKey($user);
        Cache::add($key, 0, now()->endOfDay());
        Cache::increment($key);
    }

    private static function voiceKey(User $user): string
    {
        return 'voice:'.$user->id.':'.now()->toDateString();
    }

    /** Message d'erreur si la personne a atteint sa limite de messages, sinon null. */
    public static function messageBlock(User $user): ?string
    {
        if ($user->isAdmin()) {
            return null;
        }
        $plan = self::plan($user);
        if ($plan->daily_messages !== null && self::messagesToday($user) >= $plan->daily_messages) {
            return "Vous avez utilisé vos {$plan->daily_messages} messages du jour avec l'offre {$plan->name}. Revenez demain, ou passez à une offre supérieure dans « Offres ».";
        }

        return null;
    }

    public static function voiceBlock(User $user): ?string
    {
        if ($user->isAdmin()) {
            return null;
        }
        $plan = self::plan($user);
        if ($plan->daily_voice !== null && self::voiceToday($user) >= $plan->daily_voice) {
            return "La voix en langue bamiléké est limitée à {$plan->daily_voice} utilisations par jour avec l'offre {$plan->name}. Passez à une offre supérieure pour parler sans limite.";
        }

        return null;
    }

    public static function summary(User $user): array
    {
        $sub = self::subscription($user);
        $plan = $sub?->plan ?? Plan::free();
        $used = self::messagesToday($user);
        $unlimited = $user->isAdmin() || $plan->daily_messages === null;

        return [
            'plan' => ['key' => $plan->key, 'name' => $plan->name, 'daily_messages' => $plan->daily_messages, 'premium_models' => $plan->premium_models || $user->isAdmin(), 'local_voice' => $plan->daily_voice === null, 'seats' => $plan->seats],
            'used_today' => $used,
            'remaining_today' => $unlimited ? null : max(0, $plan->daily_messages - $used),
            'voice_remaining_today' => ($user->isAdmin() || $plan->daily_voice === null) ? null : max(0, $plan->daily_voice - self::voiceToday($user)),
            'renews_at' => $sub?->current_period_end?->toIso8601String(),
            'is_owner' => $sub ? $sub->user_id === $user->id : false,
            'status' => $sub ? 'active' : 'free',
        ];
    }
}
