# Noon-Ai — OptiPlus

Plateforme interne d'agents IA et de connecteurs du Groupe HIS.

| Dossier | Contenu | Stack |
|---|---|---|
| `optiplus/backend` | API, agents, connecteurs, modèles IA, consommation | Laravel 12 · MySQL/MariaDB · Sanctum |
| `optiplus/frontend` | Application web (desktop) | React · Vite · TypeScript · Tailwind |
| `optiplus/mobile` | Application mobile Android + iOS | Flutter |

Les trois applications partagent la même API : agents, connecteurs, conversations et compteurs sont les mêmes partout.

## Démarrage rapide (développement)

```bash
# Backend
cd optiplus/backend
cp .env.example .env        # puis remplir DB_*, ADMIN_EMAIL, ADMIN_PASSWORD et au moins un fournisseur d'IA
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve           # http://localhost:8000

# Frontend
cd optiplus/frontend
npm install
npm run dev                 # http://localhost:5173
```

## Fournisseurs d'IA

Les modèles se gèrent dans l'écran **Modèles IA**. Pilotes disponibles :

- **Ollama** (modèles hébergés chez HIS : Mistral Small, Qwen3, Devstral, Gemma 3, gpt-oss) — `OLLAMA_BASE_URL`
- **Claude (Anthropic)** via le SDK PHP officiel — `ANTHROPIC_API_KEY`
- **Compatible OpenAI** : Mistral, Groq, Gemini, OpenRouter ou toute API du même format

Les règles de choix envoient les données de santé et RH uniquement vers les modèles hébergés chez HIS.

> Projet en cours de construction.
