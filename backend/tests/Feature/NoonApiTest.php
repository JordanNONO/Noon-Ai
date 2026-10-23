<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\KnowledgeEntry;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NoonApiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function admin(): User
    {
        return User::where('role', 'admin')->first();
    }

    private function member(array $attrs = []): User
    {
        return User::create(array_merge(['name' => 'Maman Jeanne', 'phone' => '+237690000001', 'password' => '1234', 'language' => 'byv'], $attrs));
    }

    /** Un seul modèle actif : Groq, dont les réponses sont simulées. */
    private function onlyGroq(): void
    {
        AiModel::query()->update(['is_enabled' => false]);
        AiModel::where('name', 'Groq')->update(['is_enabled' => true, 'api_key' => encrypt('test-key', false)]);
        $m = AiModel::where('name', 'Groq')->first();
        $m->api_key = 'test-key';
        $m->save();
    }

    private function sse(array $chunks): string
    {
        return collect($chunks)->map(fn ($c) => 'data: '.json_encode($c)."\n\n")->join('')."data: [DONE]\n\n";
    }

    private function events(string $body): array
    {
        return collect(explode("\n\n", $body))->map(fn ($l) => trim(substr(trim($l), 5)))
            ->filter(fn ($l) => $l !== '' && $l !== '[DONE]')->map(fn ($l) => json_decode($l, true))->filter()->values()->all();
    }

    public function test_register_with_phone_and_login(): void
    {
        $this->postJson('/api/auth/register', ['name' => 'Maman Odile', 'phone' => '+237 6 90 11 22 33', 'password' => '2580', 'language' => 'bbj'])
            ->assertOk()->assertJsonPath('user.language', 'bbj');

        $this->postJson('/api/auth/login', ['identifier' => '+237690112233', 'password' => '2580'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'first_name']]);

        $this->postJson('/api/auth/login', ['identifier' => '+237690112233', 'password' => 'faux'])->assertStatus(422);
    }

    public function test_bootstrap_counts_match_database(): void
    {
        $user = $this->member();
        $res = $this->actingAs($user)->getJson('/api/bootstrap')->assertOk();

        $res->assertJsonPath('default_agent.slug', 'noon');
        $res->assertJsonPath('counts.agents', 7);
        $res->assertJsonPath('counts.knowledge_approved', KnowledgeEntry::approved()->count());
        $this->assertNotEmpty($res->json('languages'));
    }

    public function test_member_cannot_reach_admin_routes(): void
    {
        $this->actingAs($this->member())->getJson('/api/admin/members')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/admin/members')->assertOk();
    }

    public function test_protected_admin_keeps_rights(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->putJson('/api/admin/members/'.$admin->id, ['role' => 'member'])->assertStatus(422);
    }

    public function test_knowledge_contribution_needs_moderation(): void
    {
        $member = $this->member();
        $id = $this->actingAs($member)->postJson('/api/knowledge', [
            'type' => 'mot', 'language' => 'byv', 'title' => 'Merci', 'content' => '[mot en medumba]', 'translation' => 'merci',
        ])->assertOk()->assertJsonPath('entry.status', 'pending')->json('entry.id');

        // Pas encore visible dans la bibliothèque publique
        $this->actingAs($member)->getJson('/api/knowledge')->assertJsonMissing(['id' => $id]);
        $this->actingAs($member)->postJson("/api/knowledge/{$id}/review", ['status' => 'approved'])->assertForbidden();

        $this->actingAs($this->admin())->postJson("/api/knowledge/{$id}/review", ['status' => 'approved'])->assertOk();
        $this->assertSame('approved', KnowledgeEntry::find($id)->status);
    }

    public function test_chat_streams_answer_and_splits_translation(): void
    {
        $this->onlyGroq();
        Http::fake(['api.groq.com/*' => Http::response($this->sse([
            ['choices' => [['delta' => ['content' => 'Réponse en medumba.']]]],
            ['choices' => [['delta' => ['content' => "\n---FR---\nRéponse en français."], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30]],
        ]))]);

        $user = $this->member();
        $res = $this->actingAs($user)->post('/api/conversations/messages', ['content' => 'Bonjour Noon'], ['Accept' => 'text/event-stream']);
        $events = $this->events($res->streamedContent());

        $this->assertContains('delta', array_column($events, 'type'));
        $done = collect($events)->firstWhere('type', 'done');
        $this->assertSame('Réponse en medumba.', $done['message']['content']);
        $this->assertSame('Réponse en français.', $done['message']['translation']);
        $this->assertSame(150, $done['message']['tokens']);

        $this->assertDatabaseHas('usage_logs', ['user_id' => $user->id, 'tokens_in' => 120, 'tokens_out' => 30]);
        $this->assertSame(1, Conversation::where('user_id', $user->id)->count());
    }

    public function test_tool_call_reads_knowledge_library(): void
    {
        $this->onlyGroq();
        Http::fakeSequence('api.groq.com/*')
            ->push($this->sse([
                ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'search_knowledge', 'arguments' => '{"query":"ndop"}']]]], 'finish_reason' => 'tool_calls']]],
            ]))
            ->push($this->sse([
                ['choices' => [['delta' => ['content' => 'Le ndop est un tissu teint à l\'indigo.'], 'finish_reason' => 'stop']]],
            ]));

        $user = $this->member(['language' => 'fr']);
        $res = $this->actingAs($user)->post('/api/conversations/messages', ['content' => 'C\'est quoi le ndop ?']);
        $done = collect($this->events($res->streamedContent()))->firstWhere('type', 'done');

        $this->assertSame('done', $done['message']['status']);
        $this->assertStringContainsString('Bibliothèque des savoirs', $done['message']['steps'][0]['label']);
        $this->assertNotEmpty($done['message']['sources']);
        $this->assertGreaterThan(0, KnowledgeEntry::where('title', 'Le tissu ndop')->value('uses'));
    }

    public function test_no_model_gives_clear_error(): void
    {
        AiModel::query()->update(['is_enabled' => false]);
        $res = $this->actingAs($this->member())->post('/api/conversations/messages', ['content' => 'Bonjour']);
        $done = collect($this->events($res->streamedContent()))->firstWhere('type', 'done');

        $this->assertSame('error', $done['message']['status']);
        $this->assertStringContainsString('Modèles IA', $done['message']['content']);
    }

    public function test_conversations_are_private(): void
    {
        $owner = $this->member();
        $other = $this->member(['phone' => '+237690000002', 'name' => 'Autre']);
        $conv = Conversation::create(['user_id' => $owner->id, 'title' => 'Privée']);
        Message::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'secret']);

        $this->actingAs($other)->getJson("/api/conversations/{$conv->id}")->assertNotFound();
        $this->actingAs($owner)->getJson("/api/conversations/{$conv->id}")->assertOk()->assertJsonCount(1, 'messages');
    }

    public function test_usage_is_computed_from_logs(): void
    {
        $this->actingAs($this->admin())->getJson('/api/usage?period=7j')->assertOk()
            ->assertJsonPath('tiles.tokens', 0)->assertJsonCount(7, 'days');
    }
}
