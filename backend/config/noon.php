<?php

return [
    'name' => 'Noon IA',
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'ollama_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
    'openai_compat_url' => env('OPENAI_COMPAT_BASE_URL'),

    // Service vocal (Python, modèles MMS) : voix → texte et texte → voix en langues bamiléké
    'speech_url' => env('SPEECH_SERVICE_URL', 'http://127.0.0.1:8001'),

    // Nombre maximal d'allers-retours outil ↔ modèle pour une même réponse
    'max_tool_rounds' => 6,
    // Délai (secondes) accordé à un modèle pour répondre
    'llm_timeout' => (int) env('NOON_LLM_TIMEOUT', 180),

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],
];
