<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Support\SpeechClient;

class LanguageController extends Controller
{
    /**
     * Langues actives avec ce que la voix sait faire pour chacune :
     * français et anglais par le navigateur / téléphone, langues bamiléké par le service vocal.
     */
    public static function list(SpeechClient $speech): array
    {
        $caps = $speech->capabilities();

        return Language::where('is_active', true)->orderBy('sort')->get()->map(function (Language $l) use ($caps) {
            $data = $l->toPublic();
            $device = ! $l->isLocalLanguage();
            $data['speech_to_text'] = $device || in_array($l->code, $caps['stt'], true);
            $data['text_to_speech'] = $device || in_array($l->code, $caps['tts'], true);
            $data['speech_engine'] = $device ? 'device' : 'service';

            return $data;
        })->values()->all();
    }

    public function index(SpeechClient $speech)
    {
        return ['languages' => self::list($speech), 'speech_online' => $speech->capabilities()['online']];
    }
}
