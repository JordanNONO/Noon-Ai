<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

/**
 * Langues proposées. Les codes sont ceux de la norme ISO 639-3, utilisés aussi
 * par les modèles vocaux MMS. Les langues bamiléké démarrent « expérimentales » :
 * leur qualité progressera avec la Bibliothèque des savoirs.
 */
class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $langs = [
            ['fr', 'Français', 'Français', 'international', 'good'],
            ['en', 'Anglais', 'English', 'international', 'good'],
            ['bbj', 'Ghomala\'', 'Ghɔmálá\'', 'bamileke', 'experimental'],
            ['byv', 'Medumba', 'Mə̀dʉ̂mbɑ̀', 'bamileke', 'experimental'],
            ['fmp', 'Fe\'fe\' (Nufi)', 'Fè\'éfě\'è', 'bamileke', 'experimental'],
            ['ybb', 'Yemba', 'Yémba', 'bamileke', 'experimental'],
            ['nnh', 'Ngiemboon', null, 'bamileke', 'experimental'],
            ['nnz', 'Nda\'nda\'', null, 'bamileke', 'experimental'],
            ['xmg', 'Mengaka', null, 'bamileke', 'experimental'],
            ['nla', 'Ngombale', null, 'bamileke', 'experimental'],
            ['jgo', 'Ngomba', null, 'bamileke', 'experimental'],
        ];
        foreach ($langs as $i => [$code, $name, $native, $family, $quality]) {
            Language::updateOrCreate(['code' => $code], [
                'name' => $name, 'native_name' => $native, 'family' => $family, 'quality' => $quality, 'is_active' => true, 'sort' => $i,
            ]);
        }
    }
}
