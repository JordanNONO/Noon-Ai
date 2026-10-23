# Noon IA

L'intelligence artificielle qui parle votre langue. Noon répond à tout, à l'écrit comme à la voix, en français, en anglais et dans les langues du Cameroun, à commencer par les langues bamiléké (ghomala', medumba, fe'fe', yemba…).

| Dossier | Contenu | Stack |
|---|---|---|
| `backend` | API : assistants, langues, bibliothèque des savoirs, connecteurs, modèles IA, offres et paiements, consommation | Laravel 12 · MySQL/MariaDB · Sanctum |
| `frontend` | Application web (ordinateur et téléphone) | React · Vite · TypeScript · Tailwind |
| `mobile` | Application Android + iOS *(à venir)* | Flutter |
| `speech` | Service vocal pour les langues locales *(à venir)* | Python · modèles MMS |

Toutes les applications partagent la même API : mêmes conversations, mêmes chiffres, même offre partout.

## Démarrage (développement)

```bash
# Backend
cd backend
cp .env.example .env        # remplir DB_*, ADMIN_EMAIL, ADMIN_PASSWORD et au moins un fournisseur d'IA
composer install
php artisan key:generate
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder   # comptes de démonstration (local uniquement)
php artisan serve           # http://localhost:8000

# Frontend
cd frontend
cp .env.example .env
npm install
npm run dev                 # http://localhost:5173
```

Planificateur (parcours planifiés, durée de conservation) : `php artisan schedule:work`.

## Fournisseurs d'IA

Les modèles se gèrent dans l'écran **Modèles IA** :

- **Ollama** (sur nos serveurs, gratuits) : Mistral Small, Qwen3, Gemma 3, gpt-oss — `OLLAMA_BASE_URL`
- **Claude (Anthropic)** via le SDK PHP officiel — `ANTHROPIC_API_KEY`
- **Compatible OpenAI** : Mistral, Groq, Gemini, OpenRouter — `MISTRAL_API_KEY`, `GROQ_API_KEY`…

Sans aucun modèle joignable, Noon affiche un message clair et les administrateurs voient le détail technique.

## Modèle économique

- **Gratuit** : 20 messages par jour, quelques échanges vocaux en langue locale.
- **Essentiel**, **Famille** (jusqu'à 6 personnes), **Pro** : abonnements mensuels.
- **Associations & entreprises** : sur devis (marque blanche, API).

Paiement par **Mobile Money** (Orange Money, MTN MoMo : la personne envoie le montant avec une référence, un administrateur confirme) ou par **carte** via Stripe (`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`). Les prix sont modifiables dans *Administration › Offres et paiements*.

## Tests

```bash
cd backend && php artisan test
```
