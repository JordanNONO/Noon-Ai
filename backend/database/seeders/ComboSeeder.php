<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Combo;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Parcours d'exemple : plusieurs assistants qui travaillent l'un après l'autre. */
class ComboSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::where('role', 'admin')->value('id');
        $id = fn (string $slug) => Agent::where('slug', $slug)->value('id');

        $combos = [
            ['Préparer des funérailles', true, [
                ['ceremonies-et-famille', 'Établir le programme, la liste des tâches et le budget', 'Prépare un programme de funérailles clair : étapes avant, pendant et après, liste des tâches avec qui fait quoi, et un budget détaillé. Utilise create_document pour le programme.', 'full', true],
                ['noon', 'Rédiger le faire-part et le message d\'annonce', 'À partir du programme, rédige un faire-part sobre et un court message d\'annonce à envoyer à la famille par WhatsApp. Laisse entre crochets les informations à compléter.', 'files', false],
            ]],
            ['Conte du soir bilingue', true, [
                ['conteur', 'Raconter un conte court pour les enfants', 'Raconte un conte traditionnel court (5 minutes à lire), adapté à des enfants de 6 à 10 ans, avec une morale.', 'full', false],
                ['apprendre-la-langue', 'Apprendre 5 mots du conte', 'À partir du conte, choisis 5 mots simples et apprends-les dans la langue de la personne, en t\'appuyant sur la Bibliothèque des savoirs. Si un mot n\'y est pas, dis-le.', 'full', false],
            ]],
            ['Organiser une réunion de famille', false, [
                ['ceremonies-et-famille', 'Proposer l\'ordre du jour et le budget', 'Propose un ordre du jour, une liste de choses à prévoir et un petit budget pour une réunion de famille.', 'full', false],
                ['noon', 'Écrire l\'invitation', 'Rédige une invitation chaleureuse et courte, prête à être envoyée par WhatsApp.', 'summary', false],
            ]],
        ];

        foreach ($combos as [$name, $fav, $steps]) {
            $combo = Combo::updateOrCreate(['name' => $name], ['trigger_type' => 'manual', 'is_favorite' => $fav, 'created_by' => $adminId]);
            $combo->steps()->delete();
            foreach ($steps as $i => [$slug, $task, $prompt, $handoff, $gate]) {
                $combo->steps()->create([
                    'position' => $i + 1, 'agent_id' => $id($slug), 'task' => $task, 'prompt' => $prompt,
                    'model_tier' => 'standard', 'handoff' => $handoff, 'requires_validation' => $gate, 'connector_ids' => [],
                ]);
            }
        }
    }
}
