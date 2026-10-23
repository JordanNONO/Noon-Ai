<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schéma de Noon IA : langues, groupes, assistants, connecteurs, modèles IA,
 * conversations, parcours, bibliothèque des savoirs et consommation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Langues parlées par Noon (français, anglais, langues bamiléké…)
        Schema::create('languages', function (Blueprint $table) {
            $table->string('code', 12)->primary(); // fr, en, bbj (ghomala'), byv (medumba)…
            $table->string('name');
            $table->string('native_name')->nullable();
            $table->string('family')->default('bamileke'); // bamileke | international
            $table->boolean('speech_to_text')->default(false); // reconnaissance vocale disponible
            $table->boolean('text_to_speech')->default(false); // synthèse vocale disponible
            $table->string('quality')->default('beta'); // good | beta | experimental
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Familles, villages, associations : pour regrouper les membres
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('kind')->default('famille'); // famille | village | association | autre
            $table->string('description')->nullable();
            $table->unsignedBigInteger('monthly_token_budget')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 30)->nullable()->unique()->after('email');
            $table->foreignId('group_id')->nullable()->after('phone')->constrained()->nullOnDelete();
            $table->string('role')->default('member')->after('group_id'); // admin | moderator | member
            $table->string('language', 12)->default('fr')->after('role');
            $table->boolean('bilingual_replies')->default(true)->after('language'); // patois + traduction française
            $table->boolean('large_text')->default(false)->after('bilingual_replies');
            $table->boolean('is_active')->default(true)->after('large_text');
            $table->json('voice_settings')->nullable()->after('is_active');
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider_label');
            $table->string('family'); // local | free | paid
            $table->string('driver'); // ollama | openai | anthropic
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable(); // chiffrée
            $table->string('api_key_env')->nullable(); // variable .env de secours
            $table->string('model_name');
            $table->string('letter', 2);
            $table->string('hue')->default('indigo');
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->string('warning')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('supports_tools')->default(true);
            $table->boolean('supports_vision')->default(false);
            $table->boolean('is_advanced')->default(false);
            $table->decimal('cost_in_per_mtok', 10, 4)->default(0);
            $table->decimal('cost_out_per_mtok', 10, 4)->default(0);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamps();
        });

        // « Règles de choix » : quel modèle pour quel type de demande
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('category')->unique(); // general | langue | personnel | long | code
            $table->string('level')->default('standard'); // sensitive | standard | large
            $table->boolean('local_only')->default(false);
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Assistants : Noon (par défaut) et des assistants spécialisés optionnels
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('initial', 2);
            $table->string('hue')->default('indigo');
            $table->string('theme')->default('general'); // general | langue | culture | cuisine | famille | apprendre
            $table->string('description');
            $table->longText('system_prompt');
            $table->string('category')->default('general'); // clé de routing_rules
            $table->json('starters')->nullable(); // suggestions de questions
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_default')->default(false); // Noon
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type');
            $table->boolean('is_custom')->default(false);
            $table->string('letter', 2);
            $table->string('hue')->default('indigo');
            $table->string('description');
            $table->text('config')->nullable(); // JSON chiffré
            $table->boolean('is_connected')->default(false);
            $table->boolean('is_sensitive')->default(false); // réservé aux administrateurs
            $table->string('perm_read')->default('always'); // always | ask | block
            $table->string('perm_write')->default('ask');
            $table->string('perm_send')->default('ask');
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_connector', function (Blueprint $table) {
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->constrained()->cascadeOnDelete();
            $table->primary(['agent_id', 'connector_id']);
        });

        // Parcours : plusieurs assistants qui s'enchaînent (ex. « Préparer une cérémonie »)
        Schema::create('combos', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trigger_type')->default('manual'); // manual | schedule
            $table->string('schedule_cron')->nullable();
            $table->string('schedule_label')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('combo_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('task');
            $table->text('prompt');
            $table->string('model_tier')->default('standard'); // standard | advanced
            $table->string('handoff')->default('full'); // full | summary | files
            $table->boolean('requires_validation')->default(false);
            $table->json('connector_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('Nouvelle conversation');
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('combo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 12)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->json('connector_ids')->nullable();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('content')->nullable();
            $table->longText('translation')->nullable(); // traduction française d'une réponse en patois
            $table->string('language', 12)->nullable();
            $table->string('audio_path')->nullable(); // message vocal de l'utilisatrice
            $table->json('steps')->nullable();
            $table->json('document')->nullable();
            $table->json('sources')->nullable(); // savoirs de la bibliothèque utilisés
            $table->json('attachments')->nullable();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('status')->default('done'); // streaming | done | error | waiting
            $table->string('feedback')->nullable(); // up | down
            $table->timestamps();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('path');
            $table->longText('extracted_text')->nullable();
            $table->timestamps();
        });

        Schema::create('pending_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('combo_run_id')->nullable();
            $table->string('kind'); // tool | combo_gate
            $table->string('tool')->nullable();
            $table->json('payload')->nullable();
            $table->string('summary');
            $table->string('status')->default('pending'); // pending | approved | rejected | done | failed
            $table->json('result')->nullable();
            $table->timestamps();
        });

        Schema::create('combo_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('running'); // running | waiting | done | failed
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->json('outputs')->nullable();
            $table->text('input')->nullable();
            $table->timestamps();
        });

        // Bibliothèque des savoirs : contributions de la communauté, validées par un modérateur
        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // mot | expression | proverbe | conte | recette | histoire | coutume | chant
            $table->string('language', 12); // langue du texte original
            $table->string('title');
            $table->longText('content'); // texte original (patois ou français)
            $table->longText('translation')->nullable(); // traduction française
            $table->text('meaning')->nullable(); // sens, explication, contexte d'usage
            $table->string('pronunciation')->nullable();
            $table->string('village')->nullable(); // origine : Bandjoun, Bangangté, Dschang…
            $table->string('audio_path')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->unsignedInteger('uses')->default(0); // nombre de réponses qui s'en sont servies
            $table->timestamps();
            $table->index(['status', 'type', 'language']);
        });

        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'group_id']);
        });
    }

    public function down(): void
    {
        foreach (['usage_logs', 'knowledge_entries', 'combo_runs', 'pending_actions', 'attachments', 'messages', 'conversations', 'combo_steps', 'combos', 'agent_connector', 'connectors', 'agents', 'settings', 'routing_rules', 'ai_models'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'role', 'language', 'bilingual_replies', 'large_text', 'is_active', 'voice_settings', 'last_seen_at']);
        });
        Schema::dropIfExists('groups');
        Schema::dropIfExists('languages');
    }
};
