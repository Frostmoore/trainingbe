# Training Companion — Backend (`trainingbe`)

Backend **Laravel 13** della piattaforma **Training Companion**: SaaS multi-tenant white-label
per palestre (allenamento + alimentazione con AI).

- **Pannello piattaforma** (`/god`) — solo super-admin: palestre, piani, quote AI, consumi.
- **Pannello palestra** (`/admin`) — gym admin e trainer: branding, iscritti, schede, piani alimentari, chat.
- **API** (`/api/v1`) — consumata dall'app Flutter degli iscritti (repo [`trainingfe`](https://github.com/Frostmoore/trainingfe)).

## 📘 Documentazione — vive nel repo documentale

> **In questo repository non c'è documentazione, di proposito.**
>
> Piani e atlanti stanno tutti in **`TrainingCompanionAI`** (Gitea), cartella `memory/`:
>
> | File | Cosa |
> |---|---|
> | `memory/plan_trainingbe.md` | **La specsheet di questo backend** — fasi B0–B10, classi con firme e percorsi reali, ADR motivati, matrice modelli AI e costi |
> | `memory/plan_trainingfe.md` | La specsheet dell'app (fasi A0–A8) |
> | `memory/codebase_reference.md` | Atlante di piattaforma — *nasce a fine B1* |
> | `memory/codebase_reference_be.md` | Atlante di questo codice — *nasce a fine B1* |
>
> **Perché non qui:** il projects-tracker importa i documenti **solo dal repo principale** del
> progetto; i sottoprogetti (questo) li traccia soltanto. Un `plan_*.md` messo qui non verrebbe
> mai risincronizzato e diventerebbe una copia stantia.

## Stack

Laravel 13 · PHP 8.3 · MariaDB · Filament 5 · Sanctum · spatie/laravel-permission (teams)
· spatie/laravel-medialibrary · Reverb (chat) · Anthropic PHP SDK (AI)

## Avvio in locale

> ⚠️ Il progetto richiede **PHP ≥ 8.3**. Il PHP di XAMPP è 8.2.12 e **non basta**, e Apache di
> XAMPP gira su quello: in sviluppo si usa `artisan serve`, non Apache.
>
> L'interprete corretto è dichiarato in [`.php-version`](./.php-version) e invocato da
> [`bin/php.cmd`](./bin/php.cmd) — **usa sempre quello**, così il PATH della sessione non conta.
> Nessun toolchain viene aggiornato a livello di macchina (ADR-11).

```bash
composer install
cp .env.example .env
bin\php.cmd artisan key:generate
bin\php.cmd artisan migrate --seed
bin\php.cmd artisan serve
```

## Versionamento

I branch si chiamano come le versioni, da `v1.0.0`. Ogni commit avanza la versione secondo
l'entità della modifica (piccola `+0.0.1`, media `+0.1.0`, grande `+1.0.0`).
Due remote: `origin` → Gitea (rete privata, Tailscale) · `github` → vetrina pubblica.

## Licenza

Proprietario. Tutti i diritti riservati.
