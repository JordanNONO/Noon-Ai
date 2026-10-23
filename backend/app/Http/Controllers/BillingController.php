<?php

namespace App\Http\Controllers;

use App\Billing\Billing;
use App\Billing\Quota;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return [
            'plans' => Plan::where('is_public', true)->orderBy('sort')->get()->map->toPublic()->values(),
            'quota' => Quota::summary($user),
            'payments' => Payment::with('plan')->where('user_id', $user->id)->latest()->limit(20)->get()->map->toPublic()->values(),
            'methods' => [
                'card' => Billing::stripeEnabled(),
                'mobile_money' => Billing::mobileMoney(),
                'merchant_name' => Setting::get('momo_merchant_name', 'Noon IA'),
            ],
            'sales_contact' => Setting::get('sales_contact'),
        ];
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'plan' => 'required|string|exists:plans,key',
            'method' => 'required|in:card,mobile_money',
            'months' => 'nullable|integer|in:1,3,6,12',
            'phone' => 'required_if:method,mobile_money|nullable|string|max:30',
            'operator' => 'required_if:method,mobile_money|nullable|in:orange,mtn',
        ]);
        $plan = Plan::where('key', $data['plan'])->firstOrFail();
        abort_if($plan->isFree() || $plan->contact_sales, 422, 'Cette offre ne se paie pas en ligne.');
        $months = $data['months'] ?? 1;
        $user = $request->user();

        if ($data['method'] === 'card') {
            abort_unless(Billing::stripeEnabled(), 422, 'Le paiement par carte n\'est pas encore activé.');
            $res = Billing::startStripe($user, $plan, $months);

            return ['redirect' => $res['redirect'], 'payment' => $res['payment']->load('plan')->toPublic()];
        }

        $numbers = Billing::mobileMoney();
        abort_unless(isset($numbers[$data['operator']]), 422, 'Ce moyen de paiement n\'est pas encore disponible.');
        $payment = Billing::startMobileMoney($user, $plan, $months, AuthController::normalizePhone($data['phone']), $data['operator']);

        return [
            'payment' => $payment->load('plan')->toPublic(),
            'instructions' => [
                'operator' => $data['operator'] === 'orange' ? 'Orange Money' : 'MTN Mobile Money',
                'number' => $numbers[$data['operator']],
                'merchant_name' => Setting::get('momo_merchant_name', 'Noon IA'),
                'amount' => $payment->amount,
                'reference' => $payment->reference,
            ],
        ];
    }

    public function cancelPayment(Request $request, Payment $payment)
    {
        abort_unless($payment->user_id === $request->user()->id && $payment->status === 'pending', 404);
        $payment->update(['status' => 'canceled']);

        return ['payment' => $payment->load('plan')->toPublic()];
    }

    /** Webhook Stripe : active l'abonnement après un paiement réussi. */
    public function stripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        if (! Billing::verifyStripeSignature($payload, $request->header('Stripe-Signature'))) {
            return response()->json(['message' => 'Signature invalide'], 400);
        }
        $event = json_decode($payload, true);
        if (($event['type'] ?? '') === 'checkout.session.completed' && ($event['data']['object']['payment_status'] ?? '') === 'paid') {
            $ref = $event['data']['object']['client_reference_id'] ?? null;
            $payment = $ref ? Payment::where('reference', $ref)->first() : null;
            if ($payment) {
                Billing::markPaid($payment, null, ['stripe_session' => $event['data']['object']['id'] ?? null]);
            } else {
                Log::warning('Webhook Stripe : paiement introuvable', ['ref' => $ref]);
            }
        }

        return ['received' => true];
    }

    /* ---------- Administration ---------- */

    public function adminIndex(Request $request)
    {
        $status = $request->query('status', 'pending');

        return [
            'payments' => Payment::with('plan', 'user')->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest()->limit(200)->get()->map->toPublic()->values(),
            'plans' => Plan::orderBy('sort')->get()->map->toPublic()->values(),
            'revenue' => [
                'month_xaf' => (float) Payment::where('status', 'paid')->where('currency', 'XAF')->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
                'month_eur' => (float) Payment::where('status', 'paid')->where('currency', 'EUR')->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
                'active_subscriptions' => \App\Models\Subscription::current()->count(),
            ],
            'settings' => [
                'momo_orange_number' => Setting::get('momo_orange_number'),
                'momo_mtn_number' => Setting::get('momo_mtn_number'),
                'momo_merchant_name' => Setting::get('momo_merchant_name', 'Noon IA'),
                'sales_contact' => Setting::get('sales_contact'),
                'card_enabled' => Billing::stripeEnabled(),
            ],
        ];
    }

    public function validatePayment(Request $request, Payment $payment)
    {
        abort_unless($payment->status === 'pending', 422, 'Ce paiement a déjà été traité.');
        Billing::markPaid($payment, $request->user(), ['validated_manually' => true]);

        return ['payment' => $payment->fresh(['plan', 'user'])->toPublic()];
    }

    public function rejectPayment(Payment $payment)
    {
        abort_unless($payment->status === 'pending', 422, 'Ce paiement a déjà été traité.');
        $payment->update(['status' => 'failed']);

        return ['payment' => $payment->fresh(['plan', 'user'])->toPublic()];
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $plan->update($request->validate([
            'name' => 'sometimes|string|max:60',
            'tagline' => 'sometimes|nullable|string|max:160',
            'price_xaf' => 'sometimes|integer|min:0',
            'price_eur' => 'sometimes|numeric|min:0',
            'daily_messages' => 'sometimes|nullable|integer|min:1',
            'daily_voice' => 'sometimes|nullable|integer|min:0',
            'premium_models' => 'sometimes|boolean',
            'seats' => 'sometimes|integer|min:1|max:500',
            'features' => 'sometimes|array',
            'features.*' => 'string|max:120',
            'is_public' => 'sometimes|boolean',
            'is_highlighted' => 'sometimes|boolean',
        ]));

        return ['plan' => $plan->toPublic()];
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'momo_orange_number' => 'nullable|string|max:30',
            'momo_mtn_number' => 'nullable|string|max:30',
            'momo_merchant_name' => 'nullable|string|max:80',
            'sales_contact' => 'nullable|string|max:120',
        ]);
        foreach ($data as $k => $v) {
            Setting::put($k, $v);
        }

        return $this->adminIndex($request);
    }
}
