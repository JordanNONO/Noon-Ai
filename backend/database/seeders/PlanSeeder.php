<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Offres proposées au lancement. Les prix sont des propositions de départ,
 * modifiables par un administrateur dans Administration › Offres et paiements.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['gratuit', 'Gratuit', 'Pour découvrir Noon', 0, 0, 20, 5, false, 1, false, false, [
                '20 messages par jour',
                'Français, anglais et langues du Cameroun à l\'écrit',
                '5 échanges vocaux par jour en langue locale',
                'Modèles IA gratuits',
            ]],
            ['essentiel', 'Essentiel', 'Pour tous les jours', 1500, 2.99, 200, null, false, 1, false, false, [
                '200 messages par jour',
                'Voix en langue locale sans limite',
                'Documents, photos et PDF',
                'Parcours et assistants personnels',
            ]],
            ['famille', 'Famille', 'Toute la famille, un seul abonnement', 4000, 6.99, 300, null, true, 6, true, false, [
                'Jusqu\'à 6 personnes de la famille',
                '300 messages par jour et par personne',
                'Voix en langue locale sans limite',
                'Les meilleurs modèles IA pour les questions difficiles',
            ]],
            ['pro', 'Pro', 'Pour travailler sans limite', 7500, 11.99, null, null, true, 1, false, false, [
                'Messages illimités',
                'Les meilleurs modèles IA en priorité',
                'Connecteurs Gmail, Drive, Agenda, WhatsApp',
                'Gros documents et longues analyses',
            ]],
            ['organisation', 'Associations & entreprises', 'Pour vos membres ou vos équipes', 0, 0, null, null, true, 50, false, true, [
                'Comptes pour tous vos membres',
                'Assistants et bibliothèque à vos couleurs',
                'Accès à l\'API de Noon',
                'Accompagnement et facture',
            ]],
        ];

        foreach ($plans as $i => [$key, $name, $tag, $xaf, $eur, $msgs, $voice, $premium, $seats, $hi, $sales, $features]) {
            Plan::updateOrCreate(['key' => $key], [
                'name' => $name, 'tagline' => $tag, 'price_xaf' => $xaf, 'price_eur' => $eur, 'daily_messages' => $msgs,
                'daily_voice' => $voice, 'premium_models' => $premium, 'seats' => $seats, 'is_highlighted' => $hi,
                'contact_sales' => $sales, 'features' => $features, 'is_public' => true, 'sort' => $i,
            ]);
        }
    }
}
