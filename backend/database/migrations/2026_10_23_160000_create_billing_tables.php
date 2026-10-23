<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Offres, abonnements et paiements (Mobile Money, carte bancaire). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // gratuit | essentiel | famille | diaspora | organisation
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->unsignedInteger('price_xaf')->default(0); // prix mensuel en francs CFA
            $table->decimal('price_eur', 8, 2)->default(0); // prix mensuel en euros (diaspora, carte)
            $table->unsignedInteger('daily_messages')->nullable(); // null = illimité
            $table->unsignedInteger('daily_voice')->nullable(); // voix en langue bamiléké par jour, null = illimité
            $table->boolean('premium_models')->default(false); // accès aux modèles payants (Claude…)
            $table->unsignedSmallInteger('seats')->default(1); // personnes couvertes (offre famille)
            $table->json('features')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_highlighted')->default(false);
            $table->boolean('contact_sales')->default(false); // sur devis
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // titulaire
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete(); // famille couverte
            $table->foreignId('plan_id')->constrained();
            $table->string('status')->default('pending'); // pending | active | expired | canceled
            $table->string('provider')->nullable(); // manual | stripe
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('provider'); // manual (Mobile Money vérifié) | stripe
            $table->string('reference')->unique(); // référence à indiquer lors du transfert
            $table->string('provider_ref')->nullable(); // identifiant chez le prestataire
            $table->unsignedInteger('months')->default(1);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('phone', 30)->nullable(); // numéro Mobile Money du payeur
            $table->string('operator')->nullable(); // orange | mtn
            $table->string('status')->default('pending'); // pending | paid | failed | canceled
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
