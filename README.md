# Training Companion — Backend (`trainingbe`)

Backend **Laravel 13** della piattaforma **Training Companion**: SaaS multi-tenant white-label
per palestre (allenamento + alimentazione con AI).

- **Pannello piattaforma** (`/god`) — solo super-admin: palestre, piani, quote AI, consumi.
- **Pannello palestra** (`/admin`) — gym admin e trainer: branding, iscritti, schede, piani alimentari, chat.
- **API** (`/api/v1`) — consumata dall'app Flutter degli iscritti (repo [`trainingfe`](https://github.com/Frostmoore/trainingfe)).

## 📘 Documentazione

| Documento | Cos'è |
|---|---|
| [`plan_training_companion.md`](./plan_training_companion.md) | **La specsheet operativa di sviluppo.** Fasi, sottofasi, classi da creare con firme e percorsi reali, decisioni architetturali con le motivazioni. È la fonte di verità per lo sviluppo — vale anche per `trainingfe`. |
| `codebase_reference.md` | L'atlante del codice prodotto. *Non esiste ancora: nasce alla fine della fase F1.* |

## Stack

Laravel 13 · PHP 8.3 · MariaDB · Filament 5 · Sanctum · spatie/laravel-permission (teams)
· spatie/laravel-medialibrary · Reverb (chat) · Anthropic PHP SDK (AI)

## Avvio in locale

> ⚠️ Il progetto richiede **PHP ≥ 8.3**. Su questa macchina il `php` in PATH è
> `E:\coding\php83\php.exe` (8.3.21) — **non** quello di XAMPP (8.2.12).
> In sviluppo si usa `php artisan serve`, non Apache.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## Versionamento

I branch si chiamano come le versioni, da `v1.0.0`. Ogni commit avanza la versione secondo
l'entità della modifica (piccola `+0.0.1`, media `+0.1.0`, grande `+1.0.0`).
Due remote: `origin` → Gitea (rete privata, Tailscale) · `github` → vetrina pubblica.

## Licenza

Proprietario. Tutti i diritti riservati.
