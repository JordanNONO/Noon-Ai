<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            ModelSeeder::class,
            ConnectorSeeder::class,
        ]);

        // Premier administrateur (identifiants lus dans .env)
        $password = env('ADMIN_PASSWORD') ?: Str::password(12, symbols: false);
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@noon.local')],
            [
                'name' => env('ADMIN_NAME', 'Administrateur'),
                'phone' => env('ADMIN_PHONE') ?: null,
                'password' => $password,
                'role' => 'admin',
                'language' => 'fr',
            ],
        );
        if ($admin->wasRecentlyCreated && ! env('ADMIN_PASSWORD')) {
            $this->command?->warn("Administrateur créé : {$admin->email} — code provisoire : {$password}");
        }

        $this->call([
            AgentSeeder::class,
            KnowledgeSeeder::class,
            ComboSeeder::class,
        ]);
    }
}
