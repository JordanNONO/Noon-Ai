<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\RoutingRule;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class ModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // Sur nos serveurs (Ollama)
            ['Mistral Small', 'Mistral AI · modèle ouvert', 'local', 'ollama', null, null, 'mistral-small3.2', 'M', 'indigo', 'Très bon en français, rapide. Idéal pour discuter, écrire une lettre ou un message.', ['Français', 'Rapide'], null, true, true, false, false, 0, 0, 10],
            ['Qwen3', 'Alibaba · Apache 2.0', 'local', 'ollama', null, null, 'qwen3:8b', 'Q', 'ochre', 'Polyvalent, plusieurs tailles selon nos machines.', ['Polyvalent'], null, true, true, false, false, 0, 0, 20],
            ['Gemma 3', 'Google · modèle ouvert', 'local', 'ollama', null, null, 'gemma3:12b', 'G', 'clay', 'Lit aussi les images : photos, documents, ordonnances.', ['Images'], null, false, false, true, false, 0, 0, 30],
            ['gpt-oss', 'OpenAI · Apache 2.0', 'local', 'ollama', null, null, 'gpt-oss:20b', 'O', 'stone', 'Bon raisonnement pour les questions difficiles.', ['Raisonnement'], null, false, true, false, true, 0, 0, 40],
            // API gratuites
            ['Mistral (offre Experiment)', 'API Mistral · gratuite', 'free', 'openai', 'https://api.mistral.ai/v1', 'MISTRAL_API_KEY', 'mistral-small-latest', 'M', 'indigo', 'Modèles Mistral hébergés, sans carte bancaire.', ['Français'], 'Les demandes peuvent servir à entraîner leurs modèles : pas de données personnelles.', false, true, false, false, 0, 0, 50],
            ['Groq', 'API gratuite', 'free', 'openai', 'https://api.groq.com/openai/v1', 'GROQ_API_KEY', 'llama-3.3-70b-versatile', 'G', 'ochre', 'Réponses très rapides sur des modèles ouverts.', ['Rapide'], 'Limite d\'environ 1 000 demandes par jour.', false, true, false, false, 0, 0, 60],
            ['Gemini (AI Studio)', 'API Google · gratuite', 'free', 'openai', 'https://generativelanguage.googleapis.com/v1beta/openai', 'GEMINI_API_KEY', 'gemini-2.5-flash', 'G', 'clay', 'Très long contexte : bon pour les gros documents.', ['Long document', 'Images'], 'Limites quotidiennes basses selon le modèle.', false, true, true, false, 0, 0, 70],
            ['OpenRouter (modèles gratuits)', 'Passerelle multi-modèles', 'free', 'openai', 'https://openrouter.ai/api/v1', 'OPENROUTER_API_KEY', 'meta-llama/llama-3.3-70b-instruct:free', 'R', 'stone', 'Une seule clé pour une vingtaine de modèles gratuits.', ['Secours'], '50 demandes par jour sans recharge.', false, true, false, false, 0, 0, 80],
            // API payantes
            ['Claude Opus 5.5', 'Anthropic · API payante', 'paid', 'anthropic', null, 'ANTHROPIC_API_KEY', 'claude-opus-5-5', 'C', 'clay', 'Le plus capable : langues rares, longues explications, documents complexes.', ['Expert', 'Langues', 'Images'], null, (bool) env('ANTHROPIC_API_KEY'), true, true, true, 4, 20, 90],
        ];

        foreach ($models as $m) {
            [$name, $provider, $family, $driver, $base, $env, $model, $letter, $hue, $desc, $tags, $warn, $enabled, $tools, $vision, $adv, $cin, $cout, $prio] = $m;
            AiModel::updateOrCreate(['name' => $name], [
                'provider_label' => $provider, 'family' => $family, 'driver' => $driver, 'base_url' => $base, 'api_key_env' => $env,
                'model_name' => $model, 'letter' => $letter, 'hue' => $hue, 'description' => $desc, 'tags' => $tags, 'warning' => $warn,
                'is_enabled' => $enabled, 'supports_tools' => $tools, 'supports_vision' => $vision, 'is_advanced' => $adv,
                'cost_in_per_mtok' => $cin, 'cost_out_per_mtok' => $cout, 'priority' => $prio,
            ]);
        }

        $claude = AiModel::where('model_name', 'claude-opus-5-5')->first();
        $rules = [
            ['general', 'Conversations de tous les jours', 'standard', false, null, 'Le modèle le plus rapide disponible.'],
            ['langue', 'Langues et culture bamiléké', 'large', false, $claude?->is_enabled ? $claude->id : null, 'Le modèle le plus capable disponible, pour limiter les erreurs.'],
            ['personnel', 'Informations personnelles et familiales', 'sensitive', false, null, 'Cochez « nos serveurs uniquement » pour qu\'elles ne sortent jamais.'],
            ['long', 'Très gros documents', 'large', false, AiModel::where('name', 'Gemini (AI Studio)')->value('id'), 'Seulement si le document ne contient rien de personnel.'],
        ];
        foreach ($rules as $i => [$cat, $label, $level, $local, $modelId, $note]) {
            RoutingRule::updateOrCreate(['category' => $cat], ['label' => $label, 'level' => $level, 'local_only' => $local, 'ai_model_id' => $modelId, 'note' => $note, 'sort' => $i]);
        }

        Setting::put('auto_fallback', true);
    }
}
