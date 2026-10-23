<?php

namespace Database\Seeders;

use App\Models\KnowledgeEntry;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Quelques savoirs généraux en français pour démarrer la bibliothèque.
 * Aucun mot ni proverbe en langue bamiléké n'est pré-rempli : ces contenus
 * doivent venir de locuteurs et être validés par un modérateur.
 */
class KnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $entries = [
            ['histoire', 'Les peuples bamiléké', 'Les Bamiléké sont un ensemble de peuples des hauts plateaux de l\'Ouest du Cameroun (région de l\'Ouest, autour de villes comme Bafoussam, Dschang, Bangangté, Mbouda ou Bafang). Ils sont organisés en nombreuses chefferies et parlent plusieurs langues apparentées, de la famille des langues bantoues des Grassfields.', null, 'Il n\'y a pas « une » langue bamiléké mais plusieurs : ghomala\', medumba, fe\'fe\', yemba, ngiemboon, nda\'nda\', etc.'],
            ['coutume', 'La chefferie et le Fo', 'Chaque groupement bamiléké est dirigé par un chef traditionnel, le Fo (on dit aussi Fon ou Mfo selon les langues), entouré de notables et de sociétés coutumières. La chefferie est à la fois un centre politique, religieux et culturel.', null, 'Le rôle exact des notables et des sociétés varie d\'une chefferie à l\'autre.'],
            ['coutume', 'Le tissu ndop', 'Le ndop est un tissu de coton teint à l\'indigo, décoré de motifs géométriques blancs obtenus par réserve (coutures serrées avant la teinture). Il est associé aux chefferies des Grassfields et porté lors des grandes cérémonies.', null, 'Ses motifs inspirent l\'identité visuelle de Noon IA.'],
            ['coutume', 'Les funérailles', 'Chez les Bamiléké, les funérailles sont un moment majeur de la vie familiale et communautaire. Elles sont souvent célébrées un certain temps après l\'enterrement, et rassemblent la famille élargie, les amis et le village, avec danses, repas et hommages.', null, 'Le déroulement, la durée et les obligations changent selon les villages et les familles : renseignez-vous toujours auprès des aînés.'],
            ['recette', 'Taro à la sauce jaune', 'Plat de fête emblématique de l\'Ouest : du taro cuit puis pilé en pâte lisse, servi avec une sauce jaune à base d\'huile de palme, d\'épices, de sel gemme et de viande ou de peau de bœuf, souvent accompagnée de légumes.', null, 'Chaque famille a sa recette et ses proportions : la bibliothèque attend vos versions détaillées.'],
            ['chant', 'Le ben skin', 'Le ben skin est une danse et un genre musical populaires de l\'Ouest du Cameroun, reconnaissable à ses rythmes rapides et à ses mouvements du bassin, très présent dans les fêtes et les mariages.', null, null],
            ['chant', 'Le mangambeu', 'Le mangambeu est un rythme musical traditionnel bamiléké, associé notamment à la région de Bangangté, devenu populaire dans la musique camerounaise moderne.', null, null],
        ];
        foreach ($entries as [$type, $title, $content, $translation, $meaning]) {
            KnowledgeEntry::updateOrCreate(['title' => $title], [
                'type' => $type, 'language' => 'fr', 'content' => $content, 'translation' => $translation, 'meaning' => $meaning,
                'author_id' => $admin?->id, 'status' => 'approved', 'reviewed_by' => $admin?->id, 'reviewed_at' => now(),
            ]);
        }
    }
}
