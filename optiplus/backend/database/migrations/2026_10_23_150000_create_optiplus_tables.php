<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schéma complet d'OptiPlus : pôles, agents, connecteurs, modèles IA,
 * conversations, combos et suivi de consommation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pôles / services de l'entreprise
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_label');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('monthly_token_budget')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('email')->constrained()->nullOnDelete();
            $table->string('role')->default('member')->after('service_id'); // admin | member
            $table->string('job_title')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('job_title');
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
            $table->string('hue')->default('sage');
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->string('warning')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('supports_tools')->default(true);
            $table->boolean('supports_vision')->default(false);
            $table->boolean('is_advanced')->default(false); // « Avancé (raisonnement) »
            $table->decimal('cost_in_per_mtok', 10, 4)->default(0);
            $table->decimal('cost_out_per_mtok', 10, 4)->default(0);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamps();
        });

        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('category')->unique(); // sante | rh | redaction | code | long | general
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

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('initial', 2);
            $table->string('hue')->default('sage');
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->longText('system_prompt');
            $table->string('category')->default('general'); // clé de routing_rules
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_orchestrator')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Matrice d'accès agent × service
        Schema::create('agent_service', function (Blueprint $table) {
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['agent_id', 'service_id']);
        });

        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type'); // voir App\Connectors\ConnectorRegistry
            $table->boolean('is_custom')->default(false);
            $table->string('letter', 2);
            $table->string('hue')->default('sage');
            $table->string('description');
            $table->text('config')->nullable(); // JSON chiffré
            $table->boolean('is_connected')->default(false);
            $table->boolean('is_sensitive')->default(false); // réservé admins + Tech
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
            $table->json('steps')->nullable(); // cartes « appel d'agent / connecteur »
            $table->json('document')->nullable(); // {title, body} affiché dans le panneau
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

        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'service_id']);
        });
    }

    public function down(): void
    {
        foreach (['usage_logs', 'combo_runs', 'pending_actions', 'attachments', 'messages', 'conversations', 'combo_steps', 'combos', 'agent_connector', 'connectors', 'agent_service', 'agents', 'settings', 'routing_rules', 'ai_models'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['role', 'job_title', 'is_active', 'voice_settings', 'last_seen_at']);
        });
        Schema::dropIfExists('services');
    }
};
