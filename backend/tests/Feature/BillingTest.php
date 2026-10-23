<?php

namespace Tests\Feature;

use App\Billing\Quota;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function member(array $attrs = []): User
    {
        return User::create(array_merge(['name' => 'Maman Jeanne', 'phone' => '+237690000001', 'password' => '1234'], $attrs));
    }

    private function sendMessages(User $user, int $n): void
    {
        $conv = Conversation::create(['user_id' => $user->id, 'title' => 'Test']);
        for ($i = 0; $i < $n; $i++) {
            Message::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'm'.$i]);
        }
    }

    public function test_free_plan_blocks_after_daily_limit(): void
    {
        $user = $this->member();
        $this->sendMessages($user, 20);

        $this->actingAs($user)->postJson('/api/conversations/messages', ['content' => 'encore'])
            ->assertStatus(402)->assertJsonPath('code', 'quota');
        $this->assertSame(0, Quota::summary($user)['remaining_today']);
    }

    public function test_mobile_money_payment_activates_after_admin_validation(): void
    {
        Setting::put('momo_orange_number', '690000000');
        $user = $this->member();

        $res = $this->actingAs($user)->postJson('/api/billing/checkout', ['plan' => 'essentiel', 'method' => 'mobile_money', 'phone' => '690 11 22 33', 'operator' => 'orange'])
            ->assertOk()->assertJsonPath('instructions.amount', 1500);
        $paymentId = $res->json('payment.id');
        $this->assertSame('Gratuit', Quota::plan($user)->name);

        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->postJson("/api/admin/payments/{$paymentId}/validate")->assertOk()->assertJsonPath('payment.status', 'paid');

        $this->assertSame('essentiel', Quota::plan($user->fresh())->key);
        $this->sendMessages($user, 25);
        $this->assertSame(175, Quota::summary($user->fresh())['remaining_today']);
    }

    public function test_family_plan_covers_the_whole_group(): void
    {
        Setting::put('momo_mtn_number', '670000000');
        $parent = $this->member();
        $pid = $this->actingAs($parent)->postJson('/api/billing/checkout', ['plan' => 'famille', 'method' => 'mobile_money', 'phone' => '670112233', 'operator' => 'mtn'])->json('payment.id');
        $this->actingAs(User::where('role', 'admin')->first())->postJson("/api/admin/payments/{$pid}/validate")->assertOk();

        $parent->refresh();
        $this->assertNotNull($parent->group_id);
        $child = $this->member(['phone' => '+237690000002', 'name' => 'Fils', 'group_id' => $parent->group_id]);
        $this->assertSame('famille', Quota::plan($child)->key);
    }

    public function test_member_cannot_validate_payments(): void
    {
        $this->actingAs($this->member())->getJson('/api/admin/billing')->assertForbidden();
    }

    public function test_stripe_webhook_rejects_bad_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $this->postJson('/api/billing/stripe/webhook', ['type' => 'checkout.session.completed'], ['Stripe-Signature' => 't=1,v1=faux'])->assertStatus(400);
    }
}
