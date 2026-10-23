<?php

namespace App\Billing;

use App\Models\Group;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Encaissement des abonnements :
 *  - Mobile Money (Orange Money, MTN MoMo) : la personne envoie le montant au numéro marchand
 *    avec la référence, un administrateur confirme la réception, l'abonnement s'active ;
 *  - carte bancaire via Stripe Checkout (diaspora), activé automatiquement par webhook.
 */
class Billing
{
    public static function stripeEnabled(): bool
    {
        return (bool) config('services.stripe.secret');
    }

    public static function mobileMoney(): array
    {
        return array_filter([
            'orange' => Setting::get('momo_orange_number'),
            'mtn' => Setting::get('momo_mtn_number'),
        ]);
    }

    public static function newReference(): string
    {
        do {
            $ref = 'NOON-'.strtoupper(Str::random(6));
        } while (Payment::where('reference', $ref)->exists());

        return $ref;
    }

    public static function startMobileMoney(User $user, Plan $plan, int $months, string $phone, string $operator): Payment
    {
        return Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'provider' => 'manual', 'reference' => self::newReference(),
            'months' => $months, 'amount' => $plan->price_xaf * $months, 'currency' => 'XAF',
            'phone' => $phone, 'operator' => $operator, 'status' => 'pending',
        ]);
    }

    /** @return array{payment: Payment, redirect: string} */
    public static function startStripe(User $user, Plan $plan, int $months): array
    {
        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'provider' => 'stripe', 'reference' => self::newReference(),
            'months' => $months, 'amount' => $plan->price_eur * $months, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $front = rtrim(config('noon.frontend_url'), '/');
        $res = Http::withToken(config('services.stripe.secret'))->asForm()->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => $payment->reference,
            'customer_email' => $user->email,
            'success_url' => $front.'/offres?paiement=ok',
            'cancel_url' => $front.'/offres?paiement=annule',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => (int) round($payment->amount * 100),
                    'product_data' => ['name' => "Noon IA — {$plan->name} ({$months} mois)"],
                ],
            ]],
            'metadata' => ['reference' => $payment->reference],
        ]);
        if (! $res->successful()) {
            $payment->update(['status' => 'failed', 'raw' => $res->json()]);
            throw new \RuntimeException('Le paiement par carte est indisponible : '.($res->json('error.message') ?? 'erreur Stripe'));
        }
        $payment->update(['provider_ref' => $res->json('id')]);

        return ['payment' => $payment, 'redirect' => $res->json('url')];
    }

    /** Vérifie la signature d'un webhook Stripe (en-tête Stripe-Signature). */
    public static function verifyStripeSignature(string $payload, ?string $header): bool
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret || ! $header) {
            return false;
        }
        $parts = collect(explode(',', $header))->mapWithKeys(function ($p) {
            [$k, $v] = array_pad(explode('=', $p, 2), 2, '');

            return [$k => $v];
        });
        $t = (int) ($parts['t'] ?? 0);
        if (! $t || abs(time() - $t) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);
        $given = collect(explode(',', $header))->filter(fn ($p) => str_starts_with($p, 'v1='))->map(fn ($p) => substr($p, 3));

        return $given->contains(fn ($sig) => hash_equals($expected, $sig));
    }

    /** Paiement reçu : crée ou prolonge l'abonnement. */
    public static function markPaid(Payment $payment, ?User $validator = null, array $raw = []): Subscription
    {
        return DB::transaction(function () use ($payment, $validator, $raw) {
            $payment->refresh();
            if ($payment->status === 'paid' && $payment->subscription_id) {
                return Subscription::find($payment->subscription_id);
            }
            $user = $payment->user;
            $plan = $payment->plan;

            // Une offre Famille couvre le groupe du titulaire ; on le crée s'il n'en a pas
            $groupId = null;
            if ($plan->seats > 1) {
                if (! $user->group_id) {
                    $group = Group::create(['name' => 'Famille de '.$user->firstName(), 'slug' => Str::slug('famille-'.$user->firstName()).'-'.Str::lower(Str::random(4)), 'kind' => 'famille']);
                    $user->update(['group_id' => $group->id]);
                }
                $groupId = $user->group_id;
            }

            $sub = Subscription::where('user_id', $user->id)->where('plan_id', $plan->id)->current()->first();
            $start = $sub?->current_period_end ?? now();
            if (! $sub) {
                $sub = new Subscription(['user_id' => $user->id, 'plan_id' => $plan->id]);
            }
            $sub->fill([
                'group_id' => $groupId,
                'status' => 'active',
                'provider' => $payment->provider,
                'current_period_end' => $start->copy()->addMonthsNoOverflow($payment->months),
            ])->save();

            $payment->update([
                'status' => 'paid', 'paid_at' => now(), 'subscription_id' => $sub->id,
                'validated_by' => $validator?->id, 'raw' => $raw ?: $payment->raw,
            ]);

            return $sub;
        });
    }
}
