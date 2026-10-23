<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Support\SpeechClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Voix en langues bamiléké, via le service vocal. Le français et l'anglais passent par l'appareil. */
class SpeechController extends Controller
{
    public function transcribe(Request $request, SpeechClient $speech)
    {
        $data = $request->validate([
            'audio' => 'required|file|max:20480',
            'language' => 'required|string|exists:languages,code',
        ]);
        $name = Str::uuid().'.'.($request->file('audio')->getClientOriginalExtension() ?: 'webm');
        $path = $request->file('audio')->storeAs('voice/'.$request->user()->id, $name, 'local');

        try {
            $text = $speech->transcribe(Storage::disk('local')->path($path), $data['language']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Je n\'ai pas pu écouter en '.Language::nameOf($data['language']).' pour le moment. Vous pouvez écrire, ou parler en français.',
                'detail' => $e->getMessage(),
            ], 503);
        }

        // Le jeton permet de rattacher l'enregistrement au message envoyé
        return ['text' => $text, 'audio_token' => $name];
    }

    public function synthesize(Request $request, SpeechClient $speech)
    {
        $data = $request->validate([
            'text' => 'required|string|max:3000',
            'language' => 'required|string|exists:languages,code',
        ]);
        try {
            $wav = $speech->synthesize($data['text'], $data['language']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'La voix en '.Language::nameOf($data['language']).' n\'est pas disponible pour le moment.'], 503);
        }

        return response($wav, 200, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, max-age=3600']);
    }
}
