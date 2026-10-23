<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Réponses en flux (Server-Sent Events) : le texte de l'IA s'affiche au fil de l'eau. */
trait StreamsEvents
{
    protected function sse(callable $work): StreamedResponse
    {
        return response()->stream(function () use ($work) {
            @ini_set('zlib.output_compression', '0');
            @set_time_limit(0);
            ignore_user_abort(true);
            // En production, on vide les tampons pour que chaque morceau parte tout de suite
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            $emit = function (array $event) {
                echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                @ob_flush();
                flush();
            };
            try {
                $work($emit);
            } catch (\Throwable $e) {
                Log::error($e);
                $emit(['type' => 'error', 'message' => $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                    ? 'Élément introuvable ou non autorisé.'
                    : 'Une erreur est survenue. Réessayez dans un instant.']);
            }
            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
