<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Client du service vocal (dossier /speech) : reconnaissance et synthèse vocales
 * pour les langues que les navigateurs ne connaissent pas (langues bamiléké).
 */
class SpeechClient
{
    private function base(): string
    {
        return rtrim((string) config('noon.speech_url'), '/');
    }

    /** @return array{online: bool, stt: array<int, string>, tts: array<int, string>} */
    public function capabilities(): array
    {
        return Cache::remember('speech.capabilities', 300, function () {
            try {
                $res = Http::timeout(3)->get($this->base().'/health');
                if ($res->successful()) {
                    return ['online' => true, 'stt' => $res->json('stt', []), 'tts' => $res->json('tts', [])];
                }
            } catch (\Throwable) {
            }

            return ['online' => false, 'stt' => [], 'tts' => []];
        });
    }

    public function transcribe(string $absolutePath, string $language): string
    {
        $res = Http::timeout(120)->attach('file', fopen($absolutePath, 'r'), basename($absolutePath))
            ->post($this->base().'/transcribe', ['language' => $language]);
        if (! $res->successful()) {
            throw new \RuntimeException($res->json('detail') ?? 'Le service vocal ne répond pas.');
        }

        return trim((string) $res->json('text'));
    }

    /** @return string contenu audio WAV */
    public function synthesize(string $text, string $language): string
    {
        $res = Http::timeout(120)->post($this->base().'/synthesize', ['text' => $text, 'language' => $language]);
        if (! $res->successful()) {
            throw new \RuntimeException($res->json('detail') ?? 'Le service vocal ne répond pas.');
        }

        return $res->body();
    }
}
