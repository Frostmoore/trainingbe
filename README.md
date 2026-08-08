# Training Companion — Backend (`trainingbe`)

Backend **Laravel 13** della piattaforma **Training Companion**: SaaS multi-tenant white-label
per palestre (allenamento + alimentazione con AI).

- **Pannello piattaforma** (`/god`) — solo super-admin: palestre, piani, quote AI, consumi.
- **Pannello palestra** (`/admin`) — gym admin e trainer: branding, iscritti, schede, piani alimentari, chat.
- **API** (`/api/v1`) — consumata dall'app Flutter degli iscritti (repo [`trainingfe`](https://github.com/Frostmoore/trainingfe)).

## 📘 Documentazione

| Documento | Dove | Cos'è |
|---|---|---|
| [`plan_trainingbe.md`](./plan_trainingbe.md) | qui | **La specsheet operativa del backend.** Fasi B0–B10, classi da creare con firme e percorsi reali, ADR con le motivazioni, matrice modelli AI e costi |
| `plan_trainingfe.md` | [`trainingfe`](https://github.com/Frostmoore/trainingfe) | La specsheet operativa dell'app (fasi A0–A8) |
| `codebase_reference.md` | qui | Atlante del codice backend. *Non esiste ancora: nasce a fine **B1*** |
| `codebase_reference.md` | `trainingfe` | Atlante del codice app. *Nasce a fine **A1*** |
| `codebase_reference.md` generale | *da decidere* | Atlante di piattaforma: contratto API, flusso dati end-to-end. Vedi [`plan_trainingbe.md` §16](./plan_trainingbe.md) |

Ogni piano è **self-contained**: chi riprende il progetto su un'altra macchina non deve avere
entrambi i repo per poter lavorare su uno dei due.

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
