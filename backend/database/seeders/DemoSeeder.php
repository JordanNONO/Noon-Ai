<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Comptes de démonstration pour le développement local UNIQUEMENT.
 *   php artisan db:seed --class=DemoSeeder
 *
 * - demo-admin@noon.local / noon-demo-4821 (administrateur)
 * - +237690000099 / 4821 (« Maman Odile », membre, parle medumba)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->error('DemoSeeder refuse de tourner hors environnement local.');

            return;
        }

        $family = Group::firstOrCreate(['slug' => 'famille-demo'], ['name' => 'Famille Démo', 'kind' => 'famille']);

        User::updateOrCreate(['email' => 'demo-admin@noon.local'], [
            'name' => 'Admin Démo', 'password' => 'noon-demo-4821', 'role' => 'admin', 'language' => 'fr', 'group_id' => $family->id,
        ]);
        User::updateOrCreate(['phone' => '+237690000099'], [
            'name' => 'Maman Odile', 'password' => '4821', 'role' => 'member', 'language' => 'byv', 'group_id' => $family->id,
        ]);
    }
}
