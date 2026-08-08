# `plan_trainingbe.md` — Specsheet operativa · **Backend**

> **Documento self-contained.** Copre il repository `trainingbe`. Il gemello per l'app è
> `trainingfe/plan_trainingfe.md`. I due si riferiscono a vicenda per ID di fase (`B6`, `A4`)
> ma ciascuno è leggibile da solo: chi riprende il progetto su un'altra macchina non deve
> avere entrambi i repo per poter lavorare su uno dei due.
>
> **Stato:** fase **B0** chiusa · versione documento `v1.1.0` · aggiornato **2026-08-08**.

## Mappa dei documenti del progetto

| Documento | Dove | Cosa |
|---|---|---|
| `plan_trainingbe.md` | `trainingbe/` | **Questo file.** Piano di sviluppo del backend |
| `plan_trainingfe.md` | `trainingfe/` | Piano di sviluppo dell'app Flutter |
| `codebase_reference.md` | `trainingbe/` | Atlante del codice backend — *nasce a fine B1* |
| `codebase_reference.md` | `trainingfe/` | Atlante del codice app — *nasce a fine A1* |
| `codebase_reference.md` (generale) | *da decidere, vedi §16* | Atlante di piattaforma: come i due progetti si incastrano, contratto API, flusso dati end-to-end |

---

## 0. Cos'è questo documento

Il `codebase_reference.md` è **l'atlante**: dice *dov'è* e *che firma ha* ogni cosa già scritta.
Questo piano è la **specsheet operativa**: dice *cosa costruire, in che ordine, come e perché*.

**Criterio guida (non negoziabile):** se il progetto viene abbandonato su questa macchina e
ripreso su un'altra, un coding agent o un programmatore umano deve poter riprendere lo sviluppo
**senza perdere nulla**, seguendo questo documento passo per passo. Ogni classe è elencata con
**percorso reale** e **firma reale**; ogni decisione è motivata; ogni fase è una lista di azioni
eseguibili in ordine.

**Come si usa:** §7 (Tracking) → prima sottofase non spuntata → §8, ID stabile (`grep -n "B4.3"`)
→ esegui i passi → a fine **fase** esegui il **Rituale di fine fase** (§5), senza aspettare che
ti venga chiesto.

---

## 1. Il prodotto in una pagina

**Training Companion** era una web-app self-hosted mono-utente (Laravel 13 + Blade, dietro
Authelia, su homelab) per tracking allenamento + alimentazione con AI. Quel progetto è descritto
in `memory/training_ref.md` nel repo padre: **è il capitolato funzionale di partenza**, non il
codice da riusare (vedi ADR-09).

**Cosa diventa:** una piattaforma **pubblica, multi-tenant, white-label** venduta alle palestre.

### 1.1 I tre livelli

| Livello | Chi | Superficie | Cosa fa |
|---|---|---|---|
| **Piattaforma** | Solo il proprietario (`super_admin`) | Filament `/god` | Crea/sospende palestre, piani e quote AI, consumi e costi AI aggregati e per-tenant, impersonazione, libreria esercizi globale |
| **Palestra** | `gym_admin` + `trainer` | Filament `/admin` | Branding, trainer, iscritti, assegnazione iscritto→trainer, schede (manuali e da PDF), piani alimentari, chat |
| **Iscritto** | `member` | App Flutter (`trainingfe`) | Esegue le schede assegnate, segue il piano alimentare, diario cibo con AI, peso e foto progressi, dashboard, chat col trainer |

### 1.2 Regole di ruolo (definiscono metà del data model)

- **`gym_admin`** — tutto dentro la propria palestra.
- **`trainer`** — sub-amministratore: vede **solo gli iscritti a lui assegnati**, ne modifica
  schede e piani alimentari, chatta con loro. Non vede gli altri iscritti né branding/fatturazione.
- **`member`** — **nessun accesso ai pannelli web**: solo app.
- **`super_admin`** — `tenant_id = NULL`, unico utente che esiste fuori da una palestra.

### 1.3 Fuori scope v1

Abbonamenti/pagamenti degli iscritti, check-in, tornelli · marketplace di schede fra palestre ·
app dedicata per il trainer (usa il pannello, responsive) · wearable diversi da Health Connect (B9).

---

## 2. Decisioni architetturali (ADR)

Scelte già fatte. Non ridiscuterle senza aggiornare questo documento.

### ADR-01 — Multi-tenancy: **single database + colonna `tenant_id`**
Un solo DB. Ogni tabella di dominio ha `tenant_id` con FK. Isolamento da **global scope Eloquent**
applicato via trait.
**Perché.** Migrazioni e deploy unici; onboarding di una palestra = una `INSERT`; costi piatti;
query cross-tenant (dashboard `/god`) banali.
**Il rischio e come lo disinnesco.** Il rischio è il **leak da query che dimentica lo scope**.
Tre mitigazioni obbligatorie: (a) il trait `BelongsToTenant` è l'unico modo ammesso di dichiarare
un modello tenant-owned; (b) `TenantIsolationTest` **enumera per riflessione tutti i modelli** e
fallisce nominando la classe se uno ha la colonna ma non il trait; (c) uscire dallo scope richiede
`TenantContext::runWithoutTenant()` **esplicito** — non esiste un flag silenzioso.

### ADR-02 — White-label: **app unica, tema a runtime**
Vedi `trainingfe/plan_trainingfe.md` §ADR-A01. Lato backend l'implicazione è **un solo endpoint
pubblico** (`/api/v1/branding/lookup`) che restituisce il tema di una palestra dato il suo codice.

### ADR-03 — Pannelli: **Filament v5, due panel provider distinti**
`/god` (piattaforma) e `/admin` (palestra), stesso guard `web`, autorizzazione via
`User::canAccessPanel(Panel $panel): bool` + policy.
**Perché.** CRUD, tabelle, filtri, form, widget quasi gratis su una superficie interamente
gestionale. Il prezzo è meno libertà sul design pixel-perfect — accettabile su un backoffice,
non lo sarebbe sull'app.
⚠️ **Filament è alla v5.7.6, non alla v4.** Richiede `illuminate/contracts ^13` ✔ e `livewire ^4.1`.
Guide e pacchetti terzi per v3/v4 **non** sono compatibili.

### ADR-04 — API: **REST versionata `/api/v1` + Sanctum personal access token**
L'app parla solo con `/api/v1/*`, `Authorization: Bearer`. Niente sessioni, niente CSRF.
**Perché.** Stateless, identico su iOS/Android/emulatore, token revocabili per-device dal pannello.
Il prefisso `v1` esiste dal primo giorno perché rompere un'app già installata sugli store costa
molto più che rompere una web-app.
**Conseguenza vincolante.** Il tenant si risolve **dall'utente autenticato**, mai da un header o
parametro fornito dal client.

### ADR-05 — AI: **layer astratto, driver e modelli configurabili a runtime**
Tutte le chiamate AI passano da `App\Services\Ai\Contracts\AiProvider`. Due implementazioni:
`AnthropicProvider` e `OpenAiProvider`. Driver e **modello per singolo task** vivono in
`config/ai.php`, alimentata da `.env`, con override per-tenant su `tenants.ai_driver`.
**Perché l'astrazione.** In multi-tenant l'AI è il costo variabile: un layer unico è l'unico posto
dove si contano i token, si applicano le quote e si cambia fornitore senza toccare i controller.
**Perché la config per-task e non un modello unico.** Stimare un piatto di pasta da una frase e
fare il parsing di un PDF di scheda sono task con difficoltà diverse di un ordine di grandezza.
Usare il modello top per entrambi è denaro buttato; usare quello economico per entrambi rompe
l'import. La matrice completa con i prezzi è in **§9**.

### ADR-06 — Ruoli: **`spatie/laravel-permission` in modalità teams**
`teams = true`, `team_foreign_key = tenant_id`. Ruoli: `super_admin` (team `null`), `gym_admin`,
`trainer`, `member`.
**Perché.** Lo stesso indirizzo email non deve poter essere `trainer` in una palestra e
`gym_admin` in un'altra per un errore di assegnazione: la modalità teams lega il ruolo al tenant
a livello di pivot, non di convenzione.

### ADR-07 — Chat: **Laravel Reverb** (WebSocket self-hosted)
Broadcasting su canali privati `conversation.{id}`, autorizzati in `routes/channels.php`.
**Perché.** Niente dipendenza a consumo da Pusher, gira nello stesso stack, in staging è un
container in più. L'app mantiene un **fallback di polling** (vedi `plan_trainingfe.md` §A7).

### ADR-08 — Media: **`spatie/laravel-medialibrary`**
Foto progressi, foto allenamento, loghi palestra e PDF sorgente passano da medialibrary, con
conversion `thumb` (400px) e `preview` (1200px).
**Perché.** La galleria progressi in griglia lazy era già un requisito; generare thumbnail a mano
è lavoro sprecato. File su disco privato, serviti da endpoint autenticati — **mai URL pubblici**.

### ADR-09 — Il codice storico è un **capitolato, non una base**
Non si porta il codice di `training` (Blade + Alpine, single-user, Authelia). Si riscrive.
**Perché.** Ogni controller storico assume un utente unico e una sessione SSO: portarlo
significherebbe riscriverlo comunque, ma con addosso assunzioni sbagliate.
**Cosa si porta quasi identico**, perché è logica pura, corretta e testabile: `FoodUnit`,
`CalorieCalculator`, la formula MET di `WorkoutCalorieService`, le soglie dell'ipnogramma.

### ADR-10 — **Sviluppo locale prima, staging dopo**
B0→B8 in locale su XAMPP/MariaDB; il deploy è **B10**.
**Conseguenza pratica:** niente scorciatoie che funzionano solo in locale (`APP_URL` hardcodato,
path Windows nei seeder, `storage:link` dato per scontato). Ogni fase resta deployabile.

### ADR-11 — **Toolchain scoped al progetto, mai globale**
Né PHP né Flutter vengono aggiornati a livello di macchina. Il backend usa un PHP dichiarato
esplicitamente per il progetto (§3.4); l'app usa **FVM** per pinnare Flutter solo in `trainingfe`.
**Perché.** La macchina ospita altri progetti. Un upgrade globale li rompe silenziosamente, e il
danno si scopre settimane dopo su un progetto che nessuno stava toccando.

---

## 3. Stack e versioni (verificate 2026-08-08)

| Componente | Vincolo | Verificato |
|---|---|---|
| PHP | `^8.3` | `E:\coding\php83\php.exe` → **8.3.21** ✔ |
| laravel/framework | `^13.0` | ultima `13.24.0`, richiede `php ^8.3` ✔ |
| filament/filament | `^5.7` | ultima `5.7.6`, `illuminate/contracts ^11.28\|^12\|^13` ✔ |
| laravel/sanctum | `^4.3` | `illuminate/support ^11\|^12\|^13` ✔ |
| spatie/laravel-permission | `^8.3` | `php ^8.3`, `illuminate ^12\|^13` ✔ |
| spatie/laravel-medialibrary | `^11.23` | `illuminate ^10.2…^13` ✔ |
| laravel/reverb | `^1.11` | `illuminate ^10.47…^13` ✔ |
| laravel/horizon | `^5.48` | solo staging ✔ |
| anthropic-ai/sdk | `^0.41` | ultima `0.41.0`, `php ^8.1` ✔ |
| openai-php/laravel | `^0.20` | driver alternativo, `php ^8.2` ✔ |
| smalot/pdfparser | `^2.12` | estrazione testo PDF di riserva ✔ |
| pestphp/pest | `^4.0` | ⚠️ **v5 richiede PHP ≥ 8.4**: restare su `^4` |
| DB dev | MariaDB `10.4.32` (XAMPP) | funziona, ma è **EOL** |
| DB staging | MariaDB `11.4 LTS` / MySQL `8.4` | target di **B10.4** |

### 3.4 PHP: come si scopa al progetto (ADR-11)

Il `php` in PATH è già `E:\coding\php83\php.exe` (8.3.21) e **soddisfa Laravel 13**. Quello di
XAMPP è 8.2.12 e **non basta**. Niente va installato o cambiato a livello di macchina; si rende
solo esplicita la scelta:

- `trainingbe/.php-version` → `8.3.21` (dichiarativo, letto da tool tipo phpenv/asdf).
- `trainingbe/bin/php.cmd` → wrapper che invoca l'interprete corretto, così ogni script e ogni
  agente usa lo stesso PHP anche se il PATH della sessione cambia.
- **In sviluppo si usa `php artisan serve`, non Apache di XAMPP** (che gira su 8.2.12 e fallirebbe).
- PHP 8.4 non serve: l'unico vantaggio sarebbe Pest v5, che non vale un secondo interprete.
  Se un giorno si installa 8.4, si aggiorna `.php-version` e si sale a `pest ^5`.

---

## 4. Convenzioni

### 4.1 Lingua
**Codice, classi, colonne, chiavi di config, rotte API: inglese. UI, validazioni, documentazione:
italiano** (traduzioni in `lang/it/`).
*Perché:* l'app storica mescolava le due cose (`/diario` accanto a `FoodEntry`) e il risultato è
che non si sa mai come si chiama una cosa.

### 4.2 Naming
Tabelle `snake_case` plurale · modelli `StudlyCase` singolare · controller
`App\Http\Controllers\Api\V1\<Risorsa>Controller` · request
`App\Http\Requests\Api\V1\<Azione><Risorsa>Request` · resource
`App\Http\Resources\V1\<Risorsa>Resource` · job = verbo all'infinito (`ParseWorkoutPdf`) ·
enum `App\Enums\<Nome>` con backing `string`.

### 4.3 Regole di codice
- Ogni modello tenant-owned usa `BelongsToTenant`. Eccezioni solo con una riga in §15.
- Ogni endpoint restituisce un `JsonResource`. Mai un array grezzo, mai un modello Eloquent.
- Ogni scrittura passa da un Form Request. Niente `$request->all()`.
- Ogni classe con logica non banale ha un test (catalogo §12, aggiornato nella stessa fase).
- Enum, non stringhe magiche: ruolo, stato tenant, pasto, sorgente kcal, tipo foto, stato scheda.
- Zero segreti in git. `.env` gitignorato, `.env.example` con ogni chiave e valori fittizi.

---

## 5. ⚠️ RITUALE DI FINE FASE — obbligatorio

Alla fine di ciascuna **fase** (non sottofase), **senza aspettare che venga chiesto**, in ordine:

**5.1 — Aggiorna `plan_trainingbe.md`.** Spunta le checkbox in §7. Decisioni nuove → §2 come ADR;
trappole nuove → §14; rinvii → §15. Aggiorna la riga «Stato» in testa.

**5.2 — Aggiorna i `codebase_reference.md`.** Quello **di progetto** (`trainingbe/`) e quello
**generale di piattaforma** (§16) se la fase ha toccato il contratto fra backend e app.
Devono contenere: indice «dove sta cosa», albero dei file annotato, ogni classe con ogni metodo e
**firma completa**, ogni tabella con colonne/indici/vincoli, ogni endpoint con
middleware/input/output/codici d'errore, ogni chiave di config, catalogo test, regole non
negoziabili, trappole disinnescate, debito tecnico, e il *perché* delle scelte non ovvie.

**Verifica meccanica obbligatoria** prima di chiudere il punto:
```bash
grep -rn --include=*.php -E '^\s+(public|protected)\s+(static\s+)?function ' app/ | sed 's/{$//'
php artisan route:list --json > storage/app/private/_routes.json
php artisan db:show --counts && php artisan db:table <tabella>
```
Confronta l'output con quanto documentato. **Un atlante sbagliato è peggio di nessun atlante.**

**5.3 — Messaggio di fine fase, ESTREMAMENTE DETTAGLIATO.** Un solo messaggio con: stato
dell'implementazione complessiva; **checkbox** della fase e di tutte le sue sottofasi con lo stato
reale; **commento** sullo stato generale del progetto **e** su quello specifico della fase (cosa
funziona, cosa è stato verificato e come, cosa è rimasto fuori e perché); file creati/modificati e
test aggiunti con **l'esito reale dell'esecuzione** (se falliscono si dice, con l'output).

**5.4 — Commit e push su un branch con versione nuova** (§6), su entrambe le remote.

---

## 6. Versionamento

Branch = versione, da `v1.0.0`. Avanza **a ogni commit**:

| Entità | Incremento |
|---|---|
| Piccola (fix, doc, refactor locale) | `+0.0.1` |
| Media (feature dentro una fase) | `+0.1.0` |
| Grande (fine fase, breaking change, nuova area) | `+1.0.0` |

**Perché:** tracciabilità totale — documenti allineati al codice, storia leggibile per versioni, e
a colpo d'occhio si valuta cosa è fatto senza doverlo chiedere.

```bash
git checkout -b vX.Y.Z
git add -A && git commit -m "<tipo>: <descrizione>"
git push -u origin vX.Y.Z      # Gitea (push-to-create)
git push github vX.Y.Z         # GitHub
```

| Repo | `origin` (Gitea, via Tailscale) | `github` |
|---|---|---|
| `trainingbe` | `https://git.home.varitest.ovh/smp-webmaster/trainingbe.git` | `https://github.com/Frostmoore/trainingbe.git` |
| `trainingfe` | `https://git.home.varitest.ovh/smp-webmaster/trainingfe.git` | `https://github.com/Frostmoore/trainingfe.git` |

`trainingbe` e `trainingfe` avanzano di versione **in modo indipendente**. Utente Gitea:
**`smp-webmaster`**. Se il push `origin` fallisce, verificare Tailscale **prima di ogni altra cosa**.

---

## 7. TRACKING DELLE FASI — Backend

`[ ]` da fare · `[~]` in corso · `[x]` fatto e verificato.

### `[x]` B0 — Bootstrap del repository
- `[x]` **B0.1** Ricognizione ambiente (PHP, Composer, DB, git, remote)
- `[x]` **B0.2** Decisioni architetturali fissate (§2) e stack verificato (§3)
- `[x]` **B0.3** Stesura di `plan_trainingbe.md`
- `[x]` **B0.4** `git init`, `.gitignore`, README, remote Gitea + GitHub
- `[x]` **B0.5** Push `v1.0.0` su entrambe le remote
- `[x]` **B0.6** PHP scoped al progetto (`.php-version`, `bin/php.cmd`) — ADR-11

### `[ ]` B1 — Fondamenta multi-tenant e autenticazione
- `[ ]` **B1.1** Installazione Laravel 13 e configurazione base
- `[ ]` **B1.2** Modello `Tenant`, migration, `TenantStatus`
- `[ ]` **B1.3** `TenantContext`, `TenantScope`, trait `BelongsToTenant`
- `[ ]` **B1.4** `User` esteso, `Profile`, ruoli spatie in modalità teams
- `[ ]` **B1.5** Sanctum: register, login, logout, me, devices, revoke
- `[ ]` **B1.6** Middleware `ResolveTenant` + `EnsureTenantActive`
- `[ ]` **B1.7** Endpoint pubblico di branding
- `[ ]` **B1.8** Seeder: ruoli, super admin, palestra demo
- `[ ]` **B1.9** `TenantIsolationTest` + `AuthApiTest` — **gate qualitativo della fase**
- `[ ]` **B1.10** Creazione di `trainingbe/codebase_reference.md` e del generale (§16)

### `[ ]` B2 — Pannello `/god`
- `[ ]` **B2.1** `GodPanelProvider` + policy di accesso
- `[ ]` **B2.2** `TenantResource` (CRUD, branding, piano, quota AI, stato, rigenera codice)
- `[ ]` **B2.3** `UserResource` globale + impersonazione tracciata
- `[ ]` **B2.4** `ExerciseResource` (libreria globale)
- `[ ]` **B2.5** Dashboard: tenant attivi, iscritti, token AI del mese, costo stimato

### `[ ]` B3 — Pannello `/admin`
- `[ ]` **B3.1** `GymPanelProvider` + tenant dall'utente + branding dinamico del pannello
- `[ ]` **B3.2** Pagina Impostazioni palestra (branding, codice d'invito)
- `[ ]` **B3.3** `TrainerResource`
- `[ ]` **B3.4** `MemberResource` (CRUD, invito, stato, relation manager)
- `[ ]` **B3.5** Assegnazione iscritto ↔ trainer + scoping delle viste del trainer
- `[ ]` **B3.6** Policy complete + `RoleScopingTest`

### `[ ]` B4 — Dominio allenamento
- `[ ]` **B4.1** Migration/modelli: `exercises`, `workout_plans`, `plan_exercises`
- `[ ]` **B4.2** Migration/modelli: `workout_sessions`, `session_sets`, `body_metrics`, foto
- `[ ]` **B4.3** `WorkoutCalorieService` (MET + hook AI + override manuale)
- `[ ]` **B4.4** Filament: editor schede + assegnazione a iscritto
- `[ ]` **B4.5** API v1 allenamento
- `[ ]` **B4.6** Test dominio allenamento

### `[ ]` B5 — Dominio nutrizione
- `[ ]` **B5.1** Migration/modelli: `food_entries`, `food_favorites`, `daily_burns`
- `[ ]` **B5.2** Migration/modelli: `nutrition_plans`, `_meals`, `_items`
- `[ ]` **B5.3** Port di `FoodUnit` e `CalorieCalculator` (+ test: è logica pura)
- `[ ]` **B5.4** Filament: editor piano alimentare + assegnazione
- `[ ]` **B5.5** API v1 nutrizione
- `[ ]` **B5.6** Test dominio nutrizione

### `[ ]` B6 — Layer AI
- `[ ]` **B6.1** Contratto `AiProvider`, DTO, `AiManager`, `config/ai.php`
- `[ ]` **B6.2** `AnthropicProvider`: cibo testo/foto, kcal, consiglio
- `[ ]` **B6.3** `OpenAiProvider` (driver alternativo, modelli configurabili)
- `[ ]` **B6.4** `ai_usage_logs`, `AiUsageRecorder`, calcolo costo
- `[ ]` **B6.5** Quote per tenant + `AiQuotaExceededException` → 429
- `[ ]` **B6.6** API v1 AI + test con `FakeAiProvider` (nessun test tocca la rete)

### `[ ]` B7 — Import schede da PDF
- `[ ]` **B7.1** Upload PDF nel pannello + `workout_plan_imports`
- `[ ]` **B7.2** Job `ParseWorkoutPdf` (document block + structured output)
- `[ ]` **B7.3** `ExerciseMatcher` (riconciliazione con la libreria)
- `[ ]` **B7.4** UI di revisione della bozza prima della pubblicazione
- `[ ]` **B7.5** Escalation automatica su modello superiore se confidence bassa
- `[ ]` **B7.6** Percorso bulk in Batch API (−50%) per l'onboarding di una palestra
- `[ ]` **B7.7** Test con 3 PDF campione

### `[ ]` B8 — Chat trainer ↔ iscritto
- `[ ]` **B8.1** Migration/modelli: `conversations`, `messages`
- `[ ]` **B8.2** Broadcasting Reverb + autorizzazione canali
- `[ ]` **B8.3** Filament: interfaccia chat lato trainer
- `[ ]` **B8.4** API v1 chat (+ contratto di polling di riserva per l'app)
- `[ ]` **B8.5** `device_tokens` (invio push in B10.6)

### `[ ]` B9 — Sonno e ingest wearable (opzionale)
- `[ ]` **B9.1** `health_samples` + endpoint ingest con token per-utente
- `[ ]` **B9.2** Ipnogramma e valutazione salute del sonno (API)

### `[ ]` B10 — Hardening e staging
- `[ ]` **B10.1** Copertura test dei percorsi critici
- `[ ]` **B10.2** Rate limiting, throttling AI, `audit_logs`
- `[ ]` **B10.3** `/security-review` e correzioni
- `[ ]` **B10.4** Docker compose, MariaDB 11.4, Horizon, Reverb
- `[ ]` **B10.5** Deploy su staging + runbook
- `[ ]` **B10.6** Invio notifiche push

---

## 8. GUIDA ALLO SVILUPPO

---

### B0 — Bootstrap ✅

Fatto: repo inizializzati su `v1.0.0` con remote `origin` (Gitea, push-to-create riuscito) e
`github`, `.gitignore`, README, questo piano.

**B0.6 — PHP scoped (ADR-11).** Creare:
- `trainingbe/.php-version` con `8.3.21`
- `trainingbe/bin/php.cmd`:
  ```bat
  @echo off
  "E:\coding\php83\php.exe" %*
  ```
  e usarlo per ogni comando (`bin\php.cmd artisan migrate`). Se il progetto migra su un'altra
  macchina, si cambia una riga in un file invece di indovinare quale `php` sia in PATH.

---

### B1 — Fondamenta multi-tenant e autenticazione

**Obiettivo.** Uno scheletro in cui *è impossibile* leggere i dati di un'altra palestra per
distrazione, con autenticazione API funzionante e un test che lo dimostra.

#### B1.1 — Installazione
```bash
cd trainingbe
composer create-project laravel/laravel _tmp "^13.0"   # cartella non vuota: vedi nota
composer require laravel/sanctum:^4.3 spatie/laravel-permission:^8.3 \
                 spatie/laravel-medialibrary:^11.23 filament/filament:^5.7 \
                 anthropic-ai/sdk:^0.41 smalot/pdfparser:^2.12
composer require --dev pestphp/pest:^4.0 pestphp/pest-plugin-laravel
```
> **Nota.** `create-project` esige una cartella vuota: installare in `_tmp/`, poi spostare il
> contenuto su e cancellare `_tmp/`. **Non toccare** `plan_trainingbe.md`, `README.md`,
> `.gitignore`, `.php-version`, `bin/`.

`.env`:
```
APP_NAME="Training Companion"
APP_TIMEZONE=Europe/Rome
APP_LOCALE=it
APP_FALLBACK_LOCALE=it
DB_CONNECTION=mysql
DB_DATABASE=training_companion
```
> ⚠️ **Timezone.** Eloquent **non** converte il fuso in scrittura — è la trappola che ha già morso
> l'app storica. `APP_TIMEZONE=Europe/Rome` dal primo giorno, e ogni timestamp esterno va
> normalizzato esplicitamente (§14).

#### B1.2 — `Tenant`

**Migration `tenants`**

| Colonna | Tipo | Note |
|---|---|---|
| `id` | `bigIncrements` | |
| `name` | `string(120)` | nome commerciale |
| `slug` | `string(60)` | UNIQUE |
| `join_code` | `string(12)` | UNIQUE, il codice che l'iscritto digita nell'app |
| `status` | `string(20)` | enum `TenantStatus`, default `trial` |
| `plan` | `string(20)` | `starter`\|`pro`\|`enterprise` |
| `logo_path` | `string` null | cache di comodo per il branding |
| `color_primary` / `_secondary` / `_accent` | `string(7)` | default `#111827` / `#6B7280` / `#F59E0B` |
| `contact_email` | `string` null | |
| `locale` / `timezone` | `string` | default `it` / `Europe/Rome` |
| `ai_monthly_token_cap` | `unsignedBigInteger` null | `null` = illimitato |
| `ai_driver` | `string(20)` null | override di `config('ai.default')` |
| `settings` | `json` null | ripostiglio: **nulla che vada in una `WHERE`** |
| `trial_ends_at` | `timestamp` null | |
| `timestamps`, `softDeletes` | | |

**`app/Enums/TenantStatus.php`**
```php
enum TenantStatus: string {
    case Trial = 'trial'; case Active = 'active';
    case Suspended = 'suspended'; case Cancelled = 'cancelled';
    public function label(): string;
    public function allowsLogin(): bool;      // Trial|Active
}
```

**`app/Models/Tenant.php`**
| Metodo | Firma | Effetto |
|---|---|---|
| `users` | `users(): HasMany` | |
| `members` / `trainers` | `(): HasMany` | filtrati per ruolo |
| `isActive` | `isActive(): bool` | `status->allowsLogin()` e trial non scaduto |
| `branding` | `branding(): array` | `{name, slug, logo_url, colors:{primary,secondary,accent}, locale}` — payload dell'endpoint pubblico |
| `aiTokensUsedThisMonth` | `(): int` | somma su `ai_usage_logs` |
| `hasAiQuotaLeft` | `(): bool` | `cap === null \|\| usati < cap` |

`casts`: `status => TenantStatus::class`, `settings => 'array'`, `trial_ends_at => 'datetime'`.

#### B1.3 — Il cuore della tenancy

**`app/Support/Tenancy/TenantContext.php`** (singleton in `AppServiceProvider`)
| Metodo | Firma | Effetto |
|---|---|---|
| `set` | `set(?Tenant $tenant): void` | |
| `get` / `id` / `has` / `forget` | `(): ?Tenant` / `(): ?int` / `(): bool` / `(): void` | |
| `runAs` | `runAs(Tenant $tenant, \Closure $callback): mixed` | esegue e **ripristina il precedente anche su eccezione** (`try/finally`) |
| `runWithoutTenant` | `runWithoutTenant(\Closure $callback): mixed` | **unica** via legittima per uscire dallo scope; ripristina in `finally` |

**`app/Models/Scopes/TenantScope.php implements Scope`** — `apply(Builder $b, Model $m): void`.
Se `TenantContext::has()` è falso **non filtra**; altrimenti `where {table}.tenant_id = :id`.
> **Perché permissivo a contesto vuoto, invece di negare tutto?** Migration, seeder, comandi
> artisan e il pannello `/god` girano legittimamente senza tenant. La sicurezza non sta qui: sta
> nel fatto che ogni richiesta HTTP autenticata **imposta sempre** il contesto (`ResolveTenant`) e
> che `/god` è protetto da policy. Se lo scope negasse tutto, ogni comando di manutenzione
> richiederebbe un bypass — e i bypass diventerebbero rumore di fondo, che è il modo classico in
> cui un controllo di sicurezza smette di essere letto.

**`app/Models/Concerns/BelongsToTenant.php`** (trait)
| Metodo | Firma | Effetto |
|---|---|---|
| `bootBelongsToTenant` | `protected static function bootBelongsToTenant(): void` | registra `TenantScope`; su `creating` riempie `tenant_id` da `TenantContext::id()` se nullo |
| `tenant` | `tenant(): BelongsTo` | |
| `scopeForTenant` | `scopeForTenant(Builder $q, Tenant\|int $t): Builder` | filtro esplicito, usato da `/god` |

**`app/Models/Concerns/BelongsToTenantOrGlobal.php`** — variante per `exercises`:
`where(fn($q) => $q->where('tenant_id', $id)->orWhereNull('tenant_id'))`.
> Usare `BelongsToTenant` su `exercises` nasconderebbe la libreria globale a tutti (§14).

#### B1.4 — Utenti e ruoli

**`app/Enums/UserRole.php`** — `SuperAdmin`, `GymAdmin`, `Trainer`, `Member`;
`label(): string`, `isPlatformLevel(): bool`.

**`app/Models/User.php implements FilamentUser`** — `tenant_id` nullable FK, `HasApiTokens`,
`HasRoles`, `InteractsWithMedia`.
| Metodo | Firma | Effetto |
|---|---|---|
| `canAccessPanel` | `canAccessPanel(\Filament\Panel $panel): bool` | `god` → solo `super_admin`; `admin` → `gym_admin`\|`trainer` con tenant attivo; `member` → **sempre false** |
| `tenant` / `profile` | `(): BelongsTo` / `(): HasOne` | |
| `assignedMembers` | `(): BelongsToMany` | trainer → iscritti (pivot `trainer_member`) |
| `assignedTrainers` | `(): BelongsToMany` | iscritto → trainer |
| `isSuperAdmin` / `isGymAdmin` / `isTrainer` / `isMember` | `(): bool` | |
| `latestWeight` | `(): ?float` | ultimo `body_metrics.weight_kg` |

**`app/Models/Profile.php`** — `user_id` UNIQUE, `sex` enum(m,f), `birthdate`, `height_cm`,
`activity_level`, `goal`, `target_weight_kg`, `meal_hours` json; `age(): ?int`.

**Pivot `trainer_member`** — `trainer_id`, `member_id`, `tenant_id`, `assigned_at`, `assigned_by`.
UNIQUE(`trainer_id`,`member_id`).

Config spatie: `teams => true`, `team_foreign_key => 'tenant_id'`.

#### B1.5 / B1.7 — API di autenticazione e branding

**`app/Http/Controllers/Api/V1/AuthController.php`**
| Metodo | Firma | Effetto |
|---|---|---|
| `register` | `register(RegisterRequest $r): JsonResponse` | crea un `member` nel tenant risolto dal `join_code`; ritorna token |
| `login` | `login(LoginRequest $r): JsonResponse` | valida credenziali **e** `tenant->isActive()`; ritorna token + user + branding |
| `logout` | `logout(Request $r): JsonResponse` | revoca il token corrente |
| `me` | `me(Request $r): UserResource` | |
| `devices` | `devices(Request $r): AnonymousResourceCollection` | |
| `revokeDevice` | `revokeDevice(Request $r, int $tokenId): JsonResponse` | |

**`app/Http/Controllers/Api/V1/BrandingController.php`**
| `lookup` | `lookup(Request $r): JsonResponse` | **pubblico**, throttle `10,1`. `?code=ABC123` → `Tenant::branding()`. |

> ⚠️ **404 indistinguibile** fra «codice inesistente» e «palestra sospesa», e **solo** il payload
> di branding nel corpo. Altrimenti l'endpoint diventa un oracolo per enumerare i clienti.

#### B1.6 — Middleware
- `ResolveTenant` — `handle(Request $request, \Closure $next): Response`. Se c'è un utente
  autenticato con `tenant_id`, `TenantContext::set()`. **Non legge mai header o parametri del
  client** (ADR-04).
- `EnsureTenantActive` — 403 `tenant_inactive` se `!$tenant->isActive()`.

In `bootstrap/app.php`: alias `tenant` e `tenant.active`; rotte protette →
`['auth:sanctum','tenant','tenant.active']`.

#### B1.9 — Il gate qualitativo della fase

**`tests/Feature/Tenancy/TenantIsolationTest.php`** deve contenere almeno:
1. `it_scopes_every_tenant_owned_model` — riflessione su tutti i modelli in `app/Models`: se ha
   `tenant_id` deve usare il trait, altrimenti fallisce **nominando la classe**.
2. `it_hides_other_tenants_records`.
3. `it_auto_fills_tenant_id_on_create`.
4. `it_restores_context_after_exception_in_runAs`.
5. `it_keeps_global_exercises_visible` (per `BelongsToTenantOrGlobal`).
6. `it_forbids_login_for_suspended_tenant` · `it_returns_404_for_unknown_join_code`.

**Definizione di fatto B1.** `migrate:fresh --seed` ok; `php artisan test` verde; login via API
restituisce token + branding; **verificare davvero** che il test di isolamento fallisca togliendo
il trait da un modello, poi rimetterlo.

---

### B2 — Pannello `/god`

- **B2.1** `app/Providers/Filament/GodPanelProvider.php` — `id('god')`, `path('god')`, colori
  neutri fissi (**il pannello di piattaforma non è brandizzato**).
- **B2.2** `app/Filament/God/Resources/TenantResource.php` — form dati + branding (color picker,
  upload logo con preview) + piano + `ai_monthly_token_cap` + `ai_driver` + stato. Tabella con
  iscritti e consumo AI del mese. Azioni: sospendi/riattiva, rigenera `join_code`.
- **B2.3** `UserResource` globale (`runWithoutTenant`), filtri per tenant e ruolo. Azione
  **Impersona** che scrive in `audit_logs`. *Perché tracciata:* il supporto è la ragione numero uno
  per cui servirà entrare in un account cliente, e farlo senza traccia è inaccettabile.
- **B2.4** `ExerciseResource` — libreria globale (`tenant_id` null).
- **B2.5** Widget: tenant attivi, iscritti totali, token AI del mese, **costo stimato** (§9),
  top 5 tenant per consumo.

---

### B3 — Pannello `/admin`

- **B3.1** `GymPanelProvider` — `id('admin')`, `path('admin')`. Imposta `TenantContext`
  dall'utente e applica i colori del tenant al tema a runtime. *Perché brandizzato anche qui:* il
  gym_admin vede il proprio marchio, non il mio — è metà del valore percepito del white-label.
- **B3.2** `app/Filament/Gym/Pages/GymSettings.php` — solo `gym_admin`.
- **B3.3** `TrainerResource` · **B3.4** `MemberResource` (invito via email + `join_code`,
  relation manager: schede, piani alimentari, ultimi allenamenti).
- **B3.5** **Lo scoping del trainer si implementa in `getEloquentQuery()` di ogni resource**, non
  nella vista: un trainer che apre `MemberResource` vede solo `auth()->user()->assignedMembers()`.
- **B3.6** `TenantPolicy`, `UserPolicy`, `WorkoutPlanPolicy`, `NutritionPlanPolicy`,
  `ConversationPolicy`. `RoleScopingTest`: accesso diretto all'id di un iscritto non assegnato → 403.

**Definizione di fatto B3.** Tre login (gym_admin, trainer, member) danno tre comportamenti
corretti e distinti, con test che lo dimostrano.

---

### B4 — Dominio allenamento

#### B4.1 / B4.2 — Tabelle
- **`exercises`** — `tenant_id` **null** (globale) o valorizzato (custom), `name`, `muscle_group`,
  `equipment`, `is_custom`, `created_by`. INDEX(`tenant_id`,`name`). Trait `BelongsToTenantOrGlobal`.
- **`workout_plans`** — `tenant_id`, `member_id` null (null = template), `created_by`, `name`,
  `notes`, `status` (`draft`\|`published`\|`archived`), `source` (`manual`\|`pdf_import`), `published_at`.
- **`plan_exercises`** — `plan_id`, `exercise_id`, `position`, `sets`, `reps` **stringa**
  («8-12» è legittimo), `rest_sec`, `target_weight`, `notes`.
- **`workout_sessions`** — `tenant_id`, `user_id`, `plan_id` null, `started_at`, `ended_at`,
  `kcal_burned`, `kcal_source` (`manual`\|`ai`\|`formula`), `notes`.
- **`session_sets`** — `session_id`, `exercise_id`, `set_number`, `reps`, `weight`, `rest_sec`, `done_at`.
- **`body_metrics`** — `tenant_id`, `user_id`, `date`, `weight_kg`, `body_fat_pct`.
  UNIQUE(`user_id`,`date`).
- **Foto** — medialibrary, collection `progress` / `workout`; `tenant_id` come colonna custom su `media`.

#### B4.3 — `app/Services/Training/WorkoutCalorieService.php`
| Metodo | Firma |
|---|---|
| costruttore | `__construct(private AiManager $ai)` |
| | `bodyweight(User $user): float` — ultimo peso, fallback `75.0` |
| | `manualDaily(User $user, CarbonInterface $date): ?int` |
| | `dailyBurned(User $user, CarbonInterface $date, float $kg): DailyBurnResult` |
| | `formulaKcal(WorkoutSession $s, float $kg): int` — `const MET = 5.0` |
| | `kcalOf(WorkoutSession $s, float $kg): int` — salvato, altrimenti formula |
| | `estimateAndStore(WorkoutSession $s, User $user): void` — AI con fallback formula, **idempotente** |

> **Regola confermata:** il valore **manuale batte sempre la stima**, e le aggregazioni passano da
> `kcalOf`/`dailyBurned` senza mai ricalcolare. Nell'app storica dashboard, calendario e diario
> mostravano tre numeri diversi: è il motivo per cui questa regola è scritta qui.

#### B4.5 — API v1
```
GET    /api/v1/workout-plans                 schede assegnate
GET    /api/v1/workout-plans/{id}            dettaglio con esercizi
POST   /api/v1/workout-sessions              avvia (opz. plan_id)
GET    /api/v1/workout-sessions              storico paginato
GET    /api/v1/workout-sessions/{id}
POST   /api/v1/workout-sessions/{id}/sets    logga/aggiorna una serie
POST   /api/v1/workout-sessions/{id}/finish  chiude e stima kcal
PATCH  /api/v1/workout-sessions/{id}/kcal    override manuale (null = torna a stima)
DELETE /api/v1/workout-sessions/{id}
GET    /api/v1/exercises                     libreria (globale + tenant)
POST   /api/v1/body-metrics   ·  GET /api/v1/body-metrics
POST   /api/v1/photos  ·  GET /api/v1/photos  ·  GET /api/v1/photos/{id}/file  ·  DELETE /api/v1/photos/{id}
```

**Definizione di fatto B4.** Un trainer crea una scheda in `/admin`, la assegna, e
`/api/v1/workout-plans` di quell'iscritto la restituisce; un altro iscritto non la vede.

---

### B5 — Dominio nutrizione

#### B5.1 / B5.2 — Tabelle
- **`food_entries`** — `tenant_id`, `user_id`, `eaten_at`, `meal` (enum 6 pasti), `description`,
  `grams`, `qty`, `unit`, `kcal/protein/carbs/fat`, `*100`, `source`
  (`manual`\|`ai_text`\|`ai_photo`\|`favorite`\|`plan`), `ai_raw` json. INDEX(`user_id`,`eaten_at`).
- **`food_favorites`** — come sopra + `is_meal` bool e `items` json.
- **`daily_burns`** — `tenant_id`, `user_id`, `date`, `kcal`. UNIQUE(`user_id`,`date`).
- **`nutrition_plans`** — `tenant_id`, `member_id`, `created_by`, `name`, `target_kcal`,
  `target_protein_g`, `target_carbs_g`, `target_fat_g`, `notes`, `status`, `starts_at`, `ends_at`.
- **`nutrition_plan_meals`** — `plan_id`, `meal`, `position`, `title`, `notes`.
- **`nutrition_plan_items`** — `meal_id`, `position`, `description`, `qty`, `unit`, `grams`, macro.

> **Perché il piano è separato dal diario.** Il piano è *prescrizione*, il diario è *consuntivo*.
> Metterli nella stessa tabella sembra economico e poi rende impossibile la domanda che vale
> davvero per la palestra: «quanto ha aderito al piano?». Con due tabelle, `source = 'plan'` sulle
> `food_entries` collega consuntivo a prescrizione e l'aderenza diventa una join.

#### B5.3 — Logica pura portata (con test)

**`app/Services/Nutrition/FoodUnit.php`** — solo statici.
```php
public const FACTORS = ['g'=>1,'mg'=>0.001,'hg'=>100,'kg'=>1000,'ml'=>1,'cl'=>10,'dl'=>100,
                        'l'=>1000,'bicchiere'=>200,'cucchiaio'=>15,'tazza'=>240,
                        'cucchiaino'=>5,'scoop'=>30];
public const ORDER = [...];
public static function valid(?string $unit): ?string;
public static function toGrams(?float $qty, ?string $unit): ?float;
```

**`app/Services/Nutrition/CalorieCalculator.php`**
```php
public const ACTIVITY = [...];      // sedentary, light, moderate, active, athlete
public const GOAL_DELTA = [...];    // lose, cut, maintain, bulk
public const MACRO_SPLIT = [...];   // % per obiettivo
public function bmi(float $kg, float $cm): float;
public function bmr(string $sex, float $kg, float $cm, int $age): float;   // Mifflin-St Jeor
public function tdee(float $bmr, string $activity): float;
public function calorieTarget(float $tdee, string $goal): int;
public function macros(int $kcal, string $goal): array;    // {protein_g, carb_g, fat_g}
```
> **Nota di port.** La firma storica di `macros()` aveva un `$kg` non più usato, tenuto per
> compatibilità: **qui si toglie**. Non c'è nulla da retrocompatibilizzare, e un parametro morto in
> una firma nuova è un bug che aspetta.
> **Perché % del target invece di g/kg:** resta plausibile anche in deficit su persone pesanti, e
> `lose`/`cut` alzano le proteine per la ritenzione muscolare.

#### B5.5 — API v1
```
GET    /api/v1/diary?date=YYYY-MM-DD     voci per pasto + totali + target del giorno
POST   /api/v1/food-entries  ·  PATCH /api/v1/food-entries/{id}  ·  DELETE /api/v1/food-entries/{id}
POST   /api/v1/food-entries/{id}/favorite
GET    /api/v1/food-favorites  ·  POST /api/v1/food-favorites/meal
POST   /api/v1/food-favorites/{id}/add  ·  DELETE /api/v1/food-favorites/{id}
POST   /api/v1/daily-burn
GET    /api/v1/nutrition-plan            piano attivo dell'utente
GET    /api/v1/targets                   target kcal e macro correnti
```

**Regole confermate.** Il calcolo è **sempre in grammi**: `grams` è la fonte di verità,
`qty`/`unit` sono presentazione. Target del giorno = target base + bruciate del giorno.

---

### B6 — Layer AI

**Obiettivo.** Un solo punto attraverso cui passa ogni token, misurato e limitato.
**La matrice modelli/costi è in §9** — vive lì perché è una decisione di prodotto che cambierà,
mentre il codice di questa fase non deve cambiare quando cambia.

#### B6.1 — Contratto e DTO

**`app/Services/Ai/Contracts/AiProvider.php`**
```php
interface AiProvider {
    public function name(): string;
    public function foodFromText(string $text, AiCallContext $ctx): FoodEstimate;
    public function foodFromImage(string $absolutePath, string $mimeType, AiCallContext $ctx): FoodEstimate;
    public function workoutCalories(WorkoutAiContext $context, AiCallContext $ctx): int;
    public function dailyAdvice(array $context, AiCallContext $ctx): string;
    public function parseWorkoutPdf(string $absolutePath, AiCallContext $ctx): ParsedWorkoutPlan;
}
```

**`app/Services/Ai/AiCallContext.php`** — `readonly class`
```php
public function __construct(
    public int $tenantId,
    public int $userId,
    public AiFeature $feature,   // FoodText|FoodPhoto|WorkoutKcal|DailyAdvice|PdfImport
) {}
```

DTO in `app/Services/Ai/Data/`: `FoodEstimate` (`items: FoodItem[]`, `totals`, `confidence: float`),
`FoodItem` (`name, qty, unit, grams, kcal, protein, carbs, fat`), `ParsedWorkoutPlan`
(`name, notes, exercises: ParsedWorkoutExercise[]`, `confidence`), `ParsedWorkoutExercise`,
`WorkoutAiContext`.

**`app/Services/Ai/AiManager.php`**
```php
public function __construct(private Container $app, private TenantContext $tenants) {}
public function driver(?string $name = null): AiProvider;  // tenant->ai_driver > config('ai.default')
public function for(AiFeature $feature): AiProvider;
public function modelFor(AiFeature $feature, ?string $driver = null): string;
```

**`config/ai.php`** — vedi §10 per la lista completa delle chiavi `.env`.
```php
return [
    'default'   => env('AI_DRIVER', 'anthropic'),
    'providers' => [
        'anthropic' => ['key' => env('ANTHROPIC_API_KEY')],
        'openai'    => ['key' => env('OPENAI_API_KEY')],
    ],
    'models'  => [ /* per driver, per feature — §9 */ ],
    'pricing' => [ /* millesimi di centesimo per token, in/out — §9 */ ],
    'quota'   => ['default_monthly_tokens' => env('AI_DEFAULT_MONTHLY_TOKENS', 2_000_000)],
    'pdf'     => ['escalation_confidence' => env('AI_PDF_ESCALATION_CONFIDENCE', 0.7)],
];
```

#### B6.2 — `AnthropicProvider`

Client: `new \Anthropic\Client(apiKey: config('ai.providers.anthropic.key'))`.
⚠️ **L'SDK PHP usa named argument in camelCase** (`maxTokens`, non `max_tokens`); le chiavi
*annidate* mantengono la forma documentata caso per caso — copiare la forma esatta dagli esempi,
**senza convertire in blocco**.

Cibo da testo, con structured output (niente parsing fragile):
```php
$message = $this->client->messages->create(
    model: $this->manager->modelFor(AiFeature::FoodText),
    maxTokens: 2048,
    system: [['type' => 'text', 'text' => self::FOOD_SYSTEM_PROMPT,
              'cacheControl' => ['type' => 'ephemeral']]],
    messages: [['role' => 'user', 'content' => $text]],
    outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::FOOD_SCHEMA]],
);
```
> **Perché `cacheControl` sul system prompt.** Il prompt del cibo è lungo (regole di conversione
> food-aware) e **identico per ogni chiamata di ogni utente di ogni palestra**: è esattamente il
> caso d'uso del prompt caching — il prefisso stabile si paga una volta e poi si legge a ~0.1×.
> **Vincolo:** il prefisso deve superare il minimo cacheabile, che **non è uniforme**: 1024 token
> per Sonnet 5 e Haiku 4.5, 512 per Opus 5. Sotto soglia non viene cachato **e non lo dice**: si
> verifica leggendo `usage.cacheReadInputTokens` alla seconda chiamata.
> **Corollario:** niente data, id utente o nome palestra nel system prompt — invaliderebbero il
> prefisso a ogni richiesta, azzerando il risparmio senza alcun errore visibile.

Cibo da foto: content block immagine base64 + stesso schema. **Ridimensionare lato server a max
1568px sul lato lungo** prima dell'invio: oltre non serve per un piatto e i token immagine crescono.

Schema `self::FOOD_SCHEMA`: `items[]` con `{name, qty, unit, grams, kcal, protein, carbs, fat}` +
`totals` + `confidence`, `additionalProperties: false`, tutti i campi in `required`.
> **`grams` è food-aware**: il prompt impone che 1 cucchiaio d'olio sia 14 g e non 15 (la tabella
> generica di `FoodUnit` resta il fallback deterministico **solo** per l'inserimento manuale).

**Errori.** Catturare `Anthropic\Core\Exceptions\RateLimitException` (→ 503 con `Retry-After`),
`APIStatusException` e `APIConnectionException` (→ 502 `ai_unavailable`). **Mai far uscire
un'eccezione grezza dell'SDK verso l'API pubblica.**
**Rifiuti.** Controllare `$message->stopReason === 'refusal'` **prima** di leggere
`$message->content`: il rifiuto arriva con HTTP 200 e leggere `content[0]` incondizionatamente si
rompe.

#### B6.3 — `OpenAiProvider`
Stessa interfaccia, `openai-php/laravel`. I model id vivono in `config/ai.php` sotto
`models.openai.*` e si impostano da `.env`: **non sono hardcodati**, così puntarlo ai modelli già
in uso sul server è una riga di env, non un deploy.

#### B6.4 — Metering
**`ai_usage_logs`** — `tenant_id`, `user_id`, `provider`, `model`, `feature`, `input_tokens`,
`output_tokens`, `cache_read_tokens`, `cost_millicents`, `duration_ms`, `success`, `error_code`,
`created_at`. INDEX(`tenant_id`,`created_at`), INDEX(`tenant_id`,`feature`).

**`app/Services/Ai/AiUsageRecorder.php`**
```php
public function record(AiCallContext $ctx, string $provider, string $model,
                       int $inputTokens, int $outputTokens, int $cacheReadTokens,
                       int $durationMs, bool $success, ?string $errorCode = null): AiUsageLog;
public function costMillicents(string $model, int $in, int $out, int $cacheRead = 0): int;
```
Il log si scrive **anche in caso di errore**: una chiamata fallita dopo aver consumato input token
è comunque costata.

#### B6.5 — Quote
```php
// app/Services/Ai/Quota/TenantAiQuota.php
public function assertWithinQuota(Tenant $tenant): void;  // throws AiQuotaExceededException
public function remaining(Tenant $tenant): ?int;          // null = illimitato
```
→ HTTP **429** con `{"error":"ai_quota_exceeded","resets_at":"…"}`.
> **Perché 429 e non 402.** Per l'app è un limite di frequenza sulla risorsa AI, non un problema di
> pagamento dell'utente: l'iscritto non ha una carta nel nostro sistema. Il 402 sarebbe corretto
> verso la palestra, ma quel canale è il pannello, non l'API.

#### B6.6 — API v1 e test
```
POST /api/v1/ai/food/text     {text, meal?}  -> voci create
POST /api/v1/ai/food/photo    multipart      -> voci create
GET  /api/v1/ai/advice                       -> consiglio del giorno
```
> **Consiglio del giorno — meccanismo confermato:** cache in `ai_advices` per (user, data, kind)
> con `context_hash` = md5 del contesto. **La data fa parte del contesto**, quindi l'hash cambia a
> mezzanotte (rigenerazione garantita) e a ogni pasto/allenamento, **senza alcun cron dedicato**.
> È una soluzione elegante e va mantenuta.

**Test:** `tests/Feature/Ai/` con un `FakeAiProvider` nel container. **Nessun test tocca la rete.**

---

### B7 — Import schede da PDF

- **B7.1** `workout_plan_imports` — `tenant_id`, `uploaded_by`, `member_id` null, `media_id`,
  `status` (`queued`\|`processing`\|`review`\|`done`\|`failed`), `parsed_payload` json, `model_used`,
  `confidence`, `error`, `workout_plan_id` null.
- **B7.2** `app/Jobs/ParseWorkoutPdf.php` — `handle(AiManager $ai, ExerciseMatcher $m): void`.
  Il PDF va come **document block base64** (`application/pdf`) con `outputConfig.format` sullo
  schema `ParsedWorkoutPlan`.
  **Limiti:** 32 MB per richiesta, **600 pagine** — che scendono a **100** sui modelli con
  contesto 200K (Haiku 4.5). Per questo task **non usare Haiku**.
  **PDF scansionati:** `smalot/pdfparser` non fa OCR e non aiuta; il fallback è mandare le pagine
  come immagini.
- **B7.3** `app/Services/Training/ExerciseMatcher.php` — `match(string $name, ?int $tenantId): Exercise`.
  Normalizza (lowercase, accenti, sinonimi), cerca in libreria globale poi tenant, altrimenti crea
  custom. *Perché un servizio dedicato:* «Panca piana», «panca piana bilanciere» e «Bench press»
  devono finire sullo stesso esercizio, altrimenti la libreria di ogni palestra degenera in poche
  settimane.
- **B7.4** Pagina Filament di revisione: tabella editabile con PDF sorgente affiancato e
  `confidence` per riga. **Pubblicazione solo esplicita**: nessun import va in produzione senza che
  un umano lo abbia guardato.
- **B7.5** **Escalation.** Se `confidence < config('ai.pdf.escalation_confidence')` o il parsing
  fallisce, il job **ritenta una sola volta** sul modello superiore (§9) e registra `model_used`.
  *Perché:* la stragrande maggioranza dei PDF è testo pulito e non ha bisogno del modello top —
  pagarlo per tutti per coprire il 10% difficile è la definizione di spreco.
- **B7.6** **Percorso bulk.** Quando una palestra entra caricando tutto lo storico (decine o
  centinaia di schede), la latenza non conta: usare la **Batches API a −50%**. Struttura: un
  comando `php artisan imports:dispatch-batch {tenant}` che accoda, poi un poller che raccoglie i
  risultati. Il percorso sincrono resta il default per il singolo upload di un trainer.
- **B7.7** 3 PDF campione in `tests/Fixtures/pdf/`: testuale pulito, tabellare, scansionato.

**Definizione di fatto B7.** Un PDF reale produce una bozza con ≥80% degli esercizi correttamente
riconosciuti, revisionabile e pubblicabile.

---

### B8 — Chat trainer ↔ iscritto

- **B8.1** `conversations` (`tenant_id`, `trainer_id`, `member_id`, `last_message_at`,
  UNIQUE(`trainer_id`,`member_id`)) · `messages` (`conversation_id`, `sender_id`, `body`,
  `read_at`, `media_id` null). INDEX(`conversation_id`,`created_at`).
- **B8.2** `app/Events/MessageSent.php implements ShouldBroadcast` → canale privato
  `conversation.{id}`. `routes/channels.php` autorizza **solo i due partecipanti**, verificando
  anche il tenant.
- **B8.3** `app/Filament/Gym/Pages/Chat.php` — lista conversazioni + thread.
- **B8.4** API: `GET /api/v1/conversations`, `GET /api/v1/conversations/{id}/messages?before=`,
  `POST …/messages`, `POST …/read`. **Il contratto deve reggere anche il polling**: l'app ricade su
  polling a 15s se il socket non si apre — su rete mobile capita, e una chat che «non arriva»
  distrugge la fiducia nel prodotto.
- **B8.5** `device_tokens` (`user_id`, `token`, `platform`, `last_used_at`). Invio push in B10.6.

---

### B9 — Sonno e ingest wearable (opzionale)

Port di `HealthIngestController` con un cambiamento sostanziale: il token di ingest diventa
**per-utente** (`users.health_ingest_token`), non globale. `POST /api/v1/health/ingest` fuori dal
gruppo `auth:sanctum`, autenticato dal solo token, rate-limited.
**Regola confermata:** dall'orologio si prende **solo il sonno**. Le kcal restano manuali o stimate
— la sincronizzazione del watch è troppo in ritardo per un numero che l'utente guarda in tempo reale.
Timestamp UTC → `Carbon::parse($utc)->setTimezone(config('app.timezone'))` **esplicito** (§14).
Codici fase Health Connect → `1/7/3 = sveglio, 4/2 = leggero, 5 = profondo, 6 = REM`.
Soglie salute: `asleep ≥420 ok / ≥360 warn`; `deep% ≥15/≥10`; `rem% ≥20/≥15`; `awake ≤30/≤60`.

---

### B10 — Hardening e staging

- **B10.2** Rate limit: `auth` 5/min · `ai/*` 20/ora per utente **e** quota tenant · ingest 60/ora.
  `audit_logs` per: impersonazione, cambio ruolo, sospensione tenant, pubblicazione scheda,
  eliminazione iscritto.
- **B10.3** `/security-review` sul branch, correggere tutto prima del deploy.
- **B10.4** `docker-compose.yml`: php-fpm 8.3+, nginx, MariaDB 11.4, Redis, Horizon, Reverb.
  ⚠️ Le differenze fra MariaDB 10.4 (dev) e 11.4 (staging) — JSON, CTE, collation `utf8mb4` — si
  verificano **qui, prima del primo deploy**, non dopo.
- **B10.5** Runbook di deploy scritto in `codebase_reference.md`.

---

## 9. Matrice AI: modelli, costi, criterio di scelta

> **Questa sezione è la sola fonte di verità sui modelli.** Cambia senza toccare il codice:
> tutto è in `config/ai.php` alimentata da `.env` (ADR-05). Prezzi verificati **2026-08-08**,
> per milione di token.

### 9.1 Driver di default: **Anthropic**

**Perché.** L'app ha bisogno di tre capacità che devono stare insieme: **vision** (stima cibo da
foto), **structured output garantito** (lo schema JSON del cibo non può sbagliare) e **PDF nativo**
(import schede). Claude le copre tutte e tre nello stesso endpoint, e `outputConfig.format` valida
lo schema **lato server** invece di sperare in un JSON ben formato — che nell'app storica era una
fonte ricorrente di voci di diario rotte.

Il driver OpenAI resta implementato e selezionabile con una riga di `.env`: se si preferisce
continuare con i modelli già in uso sul server, si cambia `AI_DRIVER=openai` e si impostano i
`AI_MODEL_*` corrispondenti, **senza toccare una riga di codice**.

### 9.2 Matrice per task — «il più economico che dia risultati ottimali»

| Task | Modello | $/1M in → out | Perché proprio questo |
|---|---|---|---|
| **Cibo da testo** | `claude-haiku-4-5` | 1 → 5 | Volume altissimo (ogni pasto di ogni iscritto). Il task è «leggi una frase, applica una tabella nutrizionale»: il modello economico basta. Con prompt caching il prefisso stabile scende a ~0.1× |
| **Cibo da foto** | `claude-sonnet-5` | 3 → 15 *(promo 2 → 10 fino al 31-08-2026)* | Qui il difficile non è riconoscere il cibo ma **stimare la porzione**, ed è dove i modelli piccoli sbagliano di più. Haiku 4.5 ha comunque vision: **da testare con foto reali** (§9.4) — se la qualità regge si scende, ed è una riga di `.env` |
| **kcal allenamento** | `claude-haiku-4-5` | 1 → 5 | L'output è un intero. Esiste già la formula MET come rete di sicurezza |
| **Consiglio del giorno** | `claude-haiku-4-5` | 1 → 5 | Output breve, una volta al giorno per utente, con cache su hash del contesto |
| **Import PDF — default** | `claude-sonnet-5` | 3 → 15 | La maggioranza dei PDF di scheda è testo pulito e tabelle: Sonnet basta. Costo reale per import ≈ pochi centesimi |
| **Import PDF — escalation** | `claude-opus-5` | 5 → 25 | **Solo** se `confidence` sotto soglia o parsing fallito (B7.5). Copre il caso difficile senza pagarlo su tutti |
| **Import PDF — bulk** | `claude-sonnet-5` via **Batches API** | **−50%** → 1.5 → 7.5 | Onboarding di una palestra che carica lo storico: la latenza non conta, lo sconto sì (B7.6) |

⚠️ **Vincolo tecnico che esclude Haiku dall'import PDF:** il limite di 600 pagine per richiesta
scende a **100** sui modelli con finestra da 200K token, e Haiku 4.5 è uno di quelli.

### 9.3 Le tre leve che riducono il costo più della scelta del modello

1. **Prompt caching sul system prompt del cibo** (B6.2). Prefisso identico per tutti gli utenti di
   tutte le palestre → si paga una volta, poi si legge a ~0.1×. È il risparmio singolo più grande
   dell'intera piattaforma, e **si perde interamente** se si mette un valore variabile nel prompt.
2. **Ridimensionare le immagini a 1568px** prima dell'invio. I token immagine crescono con l'area:
   una foto da fotocamera a piena risoluzione può costare 3× senza migliorare di nulla la stima
   di un piatto.
3. **Batches API dove la latenza non conta** (import bulk): −50% netto.

### 9.4 Il test che va fatto prima di considerare chiusa la scelta

Il numero che non posso conoscere a tavolino è **quanto sbaglia Haiku 4.5 sulla stima delle
porzioni da foto rispetto a Sonnet 5, sulle foto reali dei tuoi utenti**. Alla fine di B6:

- Raccogliere ~30 foto di piatti con il peso reale noto.
- Girarle su entrambi i modelli e confrontare l'errore medio sui grammi.
- Se l'errore di Haiku è entro ~15% di quello di Sonnet, **scendere a Haiku**: significa −66% sul
  task AI più costoso della piattaforma.

Fino ad allora resta Sonnet 5, che è la scelta prudente. **La chiave API da mettere in `.env` è
quella Anthropic** (`ANTHROPIC_API_KEY`); tutto il resto sono `AI_MODEL_*` modificabili a caldo.

---

## 10. Config / env

| Chiave | Default | Significato |
|---|---|---|
| `APP_TIMEZONE` | `Europe/Rome` | ⚠️ obbligatoria (§14) |
| `APP_LOCALE` | `it` | |
| `AI_DRIVER` | `anthropic` | override per-tenant su `tenants.ai_driver` |
| `ANTHROPIC_API_KEY` | — | **solo in `.env`** |
| `OPENAI_API_KEY` | — | driver alternativo |
| `AI_MODEL_FOOD_TEXT` | `claude-haiku-4-5` | §9.2 |
| `AI_MODEL_FOOD_PHOTO` | `claude-sonnet-5` | §9.2 — candidato a scendere a Haiku dopo il test §9.4 |
| `AI_MODEL_WORKOUT_KCAL` | `claude-haiku-4-5` | |
| `AI_MODEL_ADVICE` | `claude-haiku-4-5` | |
| `AI_MODEL_PDF_IMPORT` | `claude-sonnet-5` | ⚠️ mai un modello a contesto 200K (limite 100 pagine) |
| `AI_MODEL_PDF_ESCALATION` | `claude-opus-5` | usato solo sotto soglia di confidence |
| `AI_PDF_ESCALATION_CONFIDENCE` | `0.7` | |
| `AI_DEFAULT_MONTHLY_TOKENS` | `2000000` | quota per tenant senza cap esplicito |
| `REVERB_APP_KEY` / `_SECRET` / `_ID` | — | chat |
| `SEED_SUPERADMIN_EMAIL` / `_PASSWORD` | — | solo per il seeder |

---

## 11. Schema DB di destinazione (riepilogo)

**Piattaforma (senza `tenant_id`):** `tenants`, `users` (`tenant_id` nullable per il super admin),
`roles`, `permissions`, `model_has_roles`, `audit_logs`, più le tabelle di framework
(`migrations`, `sessions`, `cache`, `jobs`, `job_batches`, `failed_jobs`,
`personal_access_tokens`, `media`).

**Tenant-owned (`tenant_id` NOT NULL + trait `BelongsToTenant`):** `profiles`, `trainer_member`,
`workout_plans`, `plan_exercises`, `workout_sessions`, `session_sets`, `body_metrics`,
`daily_burns`, `food_entries`, `food_favorites`, `nutrition_plans`, `nutrition_plan_meals`,
`nutrition_plan_items`, `conversations`, `messages`, `device_tokens`, `ai_usage_logs`,
`ai_advices`, `workout_plan_imports`, `health_samples`.

**Eccezione motivata:** `exercises` ha `tenant_id` **nullable** (null = libreria globale) e usa
`BelongsToTenantOrGlobal`. È l'unica ammessa; ogni altra richiede una riga in §15 e l'aggiornamento
di `TenantIsolationTest`.

---

## 12. Catalogo dei test

| Test | File | Cosa dimostra | Fase |
|---|---|---|---|
| `TenantIsolationTest` | `tests/Feature/Tenancy/` | Nessun modello tenant-owned sfugge allo scope; i dati di due palestre non si vedono; la libreria globale resta visibile | B1 |
| `AuthApiTest` | `tests/Feature/Api/V1/` | Register con `join_code`, login, 403 su tenant sospeso, revoca device, 404 indistinguibile sul branding | B1 |
| `RoleScopingTest` | `tests/Feature/Authorization/` | Il trainer vede solo i propri assegnati; 403 sull'accesso diretto | B3 |
| `WorkoutCalorieServiceTest` | `tests/Unit/Training/` | Manuale > stima; idempotenza di `estimateAndStore` | B4 |
| `FoodUnitTest` | `tests/Unit/Nutrition/` | Ogni fattore; `null` su unità sconosciuta | B5 |
| `CalorieCalculatorTest` | `tests/Unit/Nutrition/` | BMR/TDEE/target/macro su casi noti, entrambi i sessi | B5 |
| `DiaryApiTest` | `tests/Feature/Api/V1/` | Totali coerenti; target = base + bruciate | B5 |
| `AiProviderContractTest` | `tests/Feature/Ai/` | Fake e driver reali rispettano lo stesso contratto | B6 |
| `AiQuotaTest` | `tests/Feature/Ai/` | 429 a quota superata; log scritto anche in errore | B6 |
| `PdfImportTest` | `tests/Feature/Import/` | I 3 PDF campione producono bozze plausibili; l'escalation scatta sotto soglia | B7 |
| `ChatAuthorizationTest` | `tests/Feature/Chat/` | Un terzo utente non può ascoltare il canale privato | B8 |

---

## 13. Regole non negoziabili

1. **Segreti solo in `.env`.** `.env.example` elenca ogni chiave con valori fittizi.
2. **Ogni modello tenant-owned usa il trait.** Uscire dallo scope richiede
   `TenantContext::runWithoutTenant()` esplicito.
3. **Il tenant non si legge mai da input del client.** Si deriva dall'utente autenticato. Unica
   eccezione: l'endpoint pubblico di branding, che espone solo branding e risponde 404 in modo
   indistinguibile.
4. **`grams` è la fonte di verità** del calcolo calorico; `qty`/`unit` sono presentazione.
5. **Dal wearable si prende solo il sonno.**
6. **Il valore manuale batte sempre la stima**, e le aggregazioni passano da `kcalOf`/`dailyBurned`.
7. **Nessun file media ha URL pubblico.**
8. **Nessun import PDF va in produzione senza revisione umana.**
9. **Nessun test tocca la rete.** L'AI si testa col fake provider.
10. **`/api/v1` non si rompe.** Un cambiamento incompatibile apre `/api/v2`.
11. **Nessun toolchain viene aggiornato globalmente** (ADR-11).

---

## 14. Trappole note (con causa tecnica)

**Ereditate dall'app storica — sono già costate tempo una volta.**

- **Timezone.** Eloquent **non** converte il fuso in scrittura. Ogni timestamp esterno va convertito
  esplicitamente: `Carbon::parse($utc)->setTimezone(config('app.timezone'))`.
- **Permessi su storage.** File e directory creati da un processo **root** (CLI, seeder, job in
  container) nascono `700`: php-fpm non li legge → foto in 404. Il disco `local` di Laravel 11+ è
  `storage/app/private`. In staging: `chmod -R a+rX` dopo ogni operazione da CLI che crea file.
- **Unità di misura.** La conversione volume/casalinghe→grammi **dipende dal cibo**: food-aware per
  l'AI, tabella generica `FoodUnit` per il manuale.

**Nuove, specifiche di questo progetto.**

- **`exercises` con `tenant_id` nullable.** `BelongsToTenant` nasconderebbe la libreria globale a
  tutti: serve `BelongsToTenantOrGlobal`.
- **Prompt caching e prefisso stabile.** Qualsiasi valore variabile nel system prompt (data, nome
  palestra, id utente) invalida la cache a ogni richiesta e la fa pagare a prezzo pieno **senza
  alcun errore**. Il contesto variabile va nel messaggio utente. Verificare con
  `usage.cacheReadInputTokens`.
- **Soglia minima di cache non uniforme.** 1024 token per Sonnet 5 e Haiku 4.5, **512** per Opus 5.
  Sotto soglia non si cacha e non lo dice.
- **PDF e finestra di contesto.** Il limite di 600 pagine scende a 100 sui modelli a 200K
  (Haiku 4.5). Per l'import usare Sonnet 5 o Opus 5.
- **Rifiuti dell'API.** `stopReason === 'refusal'` arriva con HTTP 200: leggere `content[0]` senza
  controllare `stopReason` si rompe.
- **PHP.** `E:\coding\php83\php.exe` (8.3.21), non quello di XAMPP (8.2.12) — e Apache di XAMPP
  gira sul secondo, quindi in dev si usa `artisan serve`.
- **Filament è alla v5**, non alla v4: guide e pacchetti terzi per v3/v4 non sono compatibili.
- **SDK PHP Anthropic:** named argument **camelCase** (`maxTokens`), ma le chiavi annidate variano
  per feature — copiare la forma dall'esempio documentato, non convertire in blocco.

---

## 15. Debito tecnico / cosa NON esiste

Elencato in anticipo per evitare che qualcuno lo cerchi invano.

- **Fatturazione e pagamenti:** non esistono. `tenants.plan` è un'etichetta, non un abbonamento.
- **Notifiche push:** token registrati in B8.5, invio solo in B10.6.
- **OCR per PDF scansionati:** `smalot/pdfparser` non fa OCR; il fallback è mandare le pagine come
  immagini. Se fallisce anche quello, l'import va in `failed` e si inserisce a mano.
- **App per il trainer:** non esiste; usa il pannello web.
- **Sonno (B9):** opzionale, potrebbe non entrare in v1.
- **`tenants.settings` (json):** ripostiglio. Nulla che vada in una `WHERE`.
- **MariaDB 10.4 in dev:** EOL. Le differenze con la 11.4 di staging si verificano in B10.4.
- **PHP 8.4 / Pest v5:** non installati. Nessun beneficio che valga un secondo interprete.

---

## 16. ⚠️ Punto aperto: dove vive il `codebase_reference.md` generale

Servono **tre** atlanti: `trainingbe/codebase_reference.md`, `trainingfe/codebase_reference.md`
e uno **generale di piattaforma** (come i due progetti si incastrano, contratto API, flusso dati
end-to-end, matrice «feature → fase backend + fase app»).

I primi due hanno una casa ovvia. Il terzo no, perché la cartella padre
`E:\coding\XAMPP\htdocs\TrainingCompanionAI\` non è un repository. Tre opzioni:

| Opzione | Come | Costo | Rischio |
|---|---|---|---|
| **A** — Versionare la cartella padre come terzo repo (`trainingcompanion`), con dentro il generale e `memory/` | `git init` sul padre, `trainingbe/` e `trainingfe/` in `.gitignore` | Un repo in più da gestire | Nessuno |
| **B** — Il generale vive in `trainingbe/` accanto a quello di progetto, con nome diverso (es. `codebase_reference_platform.md`) | Zero setup | Nome non conforme alla convenzione richiesta | Chi apre `trainingfe` da solo non lo trova |
| **C** — Il generale sta nel padre, non versionato | Zero setup | — | **Si perde cambiando macchina** — viola il criterio guida di §0 |

**Raccomandazione: A.** È l'unica che rispetta il criterio guida («ripreso su un'altra macchina
senza perdere nulla») senza tradire la convenzione sui nomi. Decisione da confermare **prima di
chiudere B1**, che è la fase in cui i tre atlanti nascono.
