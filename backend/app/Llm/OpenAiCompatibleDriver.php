<?php

namespace App\Llm;

use App\Models\AiModel;
use Illuminate\Support\Facades\Http;

/**
 * API « chat/completions » : Ollama (/v1), Mistral, Groq, OpenRouter,
 * Gemini (point d'accès compatible) et toute API du même format.
 */
class OpenAiCompatibleDriver implements LlmDriver
{
    public function chat(AiModel $model, string $system, array $messages, array $tools, bool $advanced, ?callable $onText): LlmResponse
    {
        $base = $model->resolvedBaseUrl();
        if ($model->driver === 'ollama' && ! str_ends_with($base, '/v1')) {
            $base .= '/v1';
        }

        $body = [
            'model' => $model->model_name,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $this->convert($messages, $model)),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];
        if ($tools && $model->supports_tools) {
            $body['tools'] = array_map(fn ($t) => ['type' => 'function', 'function' => [
                'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters'],
            ]], $tools);
        }

        $req = Http::timeout(config('noon.llm_timeout'))->withOptions(['stream' => true])->acceptJson();
        if ($key = $model->resolvedApiKey()) {
            $req = $req->withToken($key);
        }
        if (str_contains($base, 'openrouter.ai')) {
            $req = $req->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'Noon IA']);
        }
        $res = $req->post($base.'/chat/completions', $body);
        if (! $res->successful()) {
            $msg = $res->json('error.message') ?? $res->json('error') ?? mb_strimwidth($res->body(), 0, 300);
            throw new LlmException(is_string($msg) ? $msg : json_encode($msg), $res->status());
        }

        $stream = $res->toPsrResponse()->getBody();
        $buffer = '';
        $text = '';
        $calls = [];
        $usage = ['in' => 0, 'out' => 0];
        $finish = null;

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    break 2;
                }
                $chunk = json_decode($data, true);
                if (! is_array($chunk)) {
                    continue;
                }
                if (! empty($chunk['usage'])) {
                    $usage['in'] = (int) ($chunk['usage']['prompt_tokens'] ?? 0);
                    $usage['out'] = (int) ($chunk['usage']['completion_tokens'] ?? 0);
                }
                $choice = $chunk['choices'][0] ?? null;
                if (! $choice) {
                    continue;
                }
                $delta = $choice['delta'] ?? [];
                if (isset($delta['content']) && $delta['content'] !== '') {
                    $text .= $delta['content'];
                    $onText && $onText($delta['content']);
                }
                foreach ($delta['tool_calls'] ?? [] as $tc) {
                    $i = $tc['index'] ?? count($calls);
                    $calls[$i] ??= ['id' => '', 'name' => '', 'args' => ''];
                    $calls[$i]['id'] = $tc['id'] ?? $calls[$i]['id'];
                    $calls[$i]['name'] .= $tc['function']['name'] ?? '';
                    $calls[$i]['args'] .= $tc['function']['arguments'] ?? '';
                }
                $finish = $choice['finish_reason'] ?? $finish;
            }
        }

        $toolCalls = [];
        foreach ($calls as $i => $c) {
            $args = json_decode($c['args'] ?: '{}', true);
            $toolCalls[] = ['id' => $c['id'] ?: 'call_'.$i.'_'.bin2hex(random_bytes(4)), 'name' => $c['name'], 'args' => is_array($args) ? $args : ['__invalid_json' => $c['args']]];
        }
        if ($finish === 'length') {
            $toolCalls = [];
        }

        // Certains serveurs ne renvoient pas l'usage : estimation ≈ 4 caractères par token
        if (! $usage['in']) {
            $usage['in'] = (int) ceil(mb_strlen(json_encode($body['messages'])) / 4);
        }
        if (! $usage['out']) {
            $usage['out'] = (int) ceil(mb_strlen($text) / 4);
        }

        return new LlmResponse($text, $toolCalls, $usage['in'], $usage['out'], $finish, null);
    }

    private function convert(array $messages, AiModel $model): array
    {
        $out = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'tool') {
                foreach ($m['results'] as $r) {
                    $out[] = ['role' => 'tool', 'tool_call_id' => $r['id'], 'content' => $r['content']];
                }

                continue;
            }
            if ($m['role'] === 'assistant') {
                $msg = ['role' => 'assistant', 'content' => (string) $m['content']];
                if (! empty($m['tool_calls'])) {
                    $msg['tool_calls'] = array_map(fn ($c) => ['id' => $c['id'], 'type' => 'function', 'function' => ['name' => $c['name'], 'arguments' => json_encode((object) $c['args'])]], $m['tool_calls']);
                }
                $out[] = $msg;

                continue;
            }
            if (! empty($m['images']) && $model->supports_vision) {
                $parts = [['type' => 'text', 'text' => (string) $m['content']]];
                foreach ($m['images'] as $img) {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$img['mime'].';base64,'.$img['data']]];
                }
                $out[] = ['role' => 'user', 'content' => $parts];
            } else {
                $out[] = ['role' => 'user', 'content' => (string) $m['content']];
            }
        }

        return $out;
    }
}
