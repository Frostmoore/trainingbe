# `plan_training_companion.md` — Specsheet operativa di sviluppo

> **Fonte di verità.** Questo documento vive in `trainingbe/plan_training_companion.md`.
> `trainingfe/` contiene solo un puntatore. Se divergono, **vince questo file**.
>
> **Stato:** Fase F0 in corso · versione documento `v1.0.0` · ultimo aggiornamento **2026-08-08**.

---

## 0. Cos'è questo documento

Il `codebase_reference.md` (che nascerà a fine F1) è **l'atlante** del codice: dice *dov'è* e *che firma ha* ogni cosa.
Questo `plan_training_companion.md` è la **specsheet operativa dello sviluppo**: dice *cosa costruire, in che ordine, come e perché*.

**Criterio guida (non negoziabile):** se il progetto viene abbandonato su questa macchina e ripreso su un'altra, un coding agent o un programmatore umano deve poter riprendere lo sviluppo **senza perdere nulla**, seguendo questo documento passo per passo. Ogni classe da creare è elencata con **percorso reale** e **firma reale**; ogni decisione è motivata; ogni fase è eseguibile come lista di azioni concrete.

**Come si usa:**
1. Apri §7 (Tracking) → trova la prima sottofase non spuntata.
2. Vai alla sezione corrispondente in §8 (Guida allo sviluppo) — gli ID sono stabili (`F4.3`), quindi un `grep -n "F4.3"` la trova al primo colpo.
3. Esegui i passi in ordine.
4. A fine **fase** (non sottofase) esegui il **Rituale di fine fase** (§5). Obbligatorio, senza aspettare che venga chiesto.

---

## 1. Il prodotto in una pagina

**Training Companion** era una web-app self-hosted mono-utente (Laravel 13 + Blade, dietro Authelia, su homelab) per tracking allenamento + alimentazione con AI. Lo stato di quel progetto è descritto in `memory/training_ref.md` nel repo padre: **quello è il capitolato funzionale di partenza**, non il codice da riusare.

**Cosa diventa:** una piattaforma **pubblica, multi-tenant e white-label** venduta alle palestre.

### 1.1 I tre livelli

| Livello | Chi | Superficie | Cosa fa |
|---|---|---|---|
| **Piattaforma** | Solo io (`super_admin`) | Pannello Filament `/god` | Crea/sospende palestre, imposta piani e quote AI, vede consumi e costi AI aggregati e per-tenant, impersona, gestisce la libreria esercizi globale |
| **Palestra** | `gym_admin` + `trainer` | Pannello Filament `/admin` | Branding white-label, gestione trainer, gestione iscritti, assegnazione iscritto→trainer, creazione/import schede, creazione piani alimentari, chat con gli iscritti assegnati |
| **Iscritto** | `member` | App Flutter (iOS/Android) | Esegue le schede assegnate, segue il piano alimentare, usa il diario cibo con AI, registra peso e foto progressi, vede dashboard, chatta col proprio trainer |

### 1.2 Regole di ruolo (definiscono metà del data model)

- Il **`gym_admin`** vede e fa tutto dentro la propria palestra.
- Il **`trainer`** è un sub-amministratore: vede **solo gli iscritti a lui assegnati**, può creare/modificare le loro schede e i loro piani alimentari, e chattare con loro. Non vede gli altri iscritti né le impostazioni di fatturazione/branding.
- Il **`member`** **non ha accesso ad alcun pannello web**: usa esclusivamente l'app.
- Il **`super_admin`** ha `tenant_id = NULL` ed è l'unico utente che può esistere fuori da una palestra.

### 1.3 Cosa NON è in scope (v1)

- Gestione abbonamenti/pagamenti degli iscritti, check-in in palestra, tornelli.
- Marketplace di schede fra palestre.
- App dedicata per il trainer (il trainer usa il pannello web, anche da mobile — Filament è responsive).
- Sincronizzazione con wearable diversi da Health Connect (vedi F11).

---

## 2. Decisioni architetturali (ADR) — il *perché*

Ogni riga qui sotto è una scelta già fatta. Non ridiscuterle senza aggiornare questo documento.

### ADR-01 — Multi-tenancy: **single database + colonna `tenant_id`**
**Scelta.** Un solo database. Ogni tabella di dominio ha `tenant_id` con FK. Isolamento garantito da un **global scope Eloquent** applicato automaticamente via trait.
**Perché.** Migrazioni e deploy unici; onboarding di una nuova palestra = una `INSERT`, non un provisioning di DB; costi infrastrutturali piatti; query cross-tenant (le dashboard del pannello `/god`) banali.
**Il rischio e come lo disinnesco.** Il rischio è il **data leak da query che dimentica lo scope**. Mitigazioni obbligatorie: (a) il trait `BelongsToTenant` è l'unico modo ammesso di dichiarare un modello tenant-owned; (b) esiste un test automatico (`TenantIsolationTest`) che **enumera tutti i modelli** e fallisce se uno che ha la colonna `tenant_id` non usa il trait; (c) uscire dallo scope richiede la chiamata **esplicita** `TenantContext::runWithoutTenant()` — non esiste un flag silenzioso.

### ADR-02 — White-label: **app unica, tema applicato a runtime**
**Scelta.** Una sola app sugli store. All'onboarding l'utente inserisce il **codice palestra** (o apre un deep link d'invito); l'app chiama un endpoint pubblico di branding, scarica logo/colori/nome e li applica al `ThemeData`.
**Perché.** Un solo build, una sola release, una sola coppia di account store. Una nuova palestra è attiva in minuti senza toccare gli store.
**Come non chiudersi la porta.** La struttura degli asset e l'entrypoint sono organizzati da subito (`lib/main.dart` sottile, `lib/app/bootstrap.dart` con il `flavor`) così che generare un flavor dedicato per il cliente premium sia un'aggiunta, non un refactor. Vedi F9.2.

### ADR-03 — Pannelli: **Filament v5, due panel provider distinti**
**Scelta.** `/god` (super-admin) e `/admin` (palestra) sono due panel Filament separati, stesso guard `web`, autorizzazione via `User::canAccessPanel(Panel $panel): bool` + policy.
**Perché.** CRUD, tabelle, filtri, form, widget e grafici quasi gratis: risparmia settimane su una superficie che è tutta gestionale. Il prezzo è meno libertà sul design pixel-perfect — accettabile su un backoffice, non lo sarebbe sull'app.
**Nota di versione.** Al momento della stesura Filament è alla **v5.7.6** (non v4): richiede `illuminate/contracts ^13` ✔ e `livewire/livewire ^4.1`.

### ADR-04 — API: **REST versionata `/api/v1` + Sanctum personal access token**
**Scelta.** L'app Flutter parla solo con `/api/v1/*`, autenticata con token Sanctum (`Bearer`). Niente sessioni, niente CSRF su quel gruppo.
**Perché.** Stateless, funziona identico su iOS/Android/emulatore, i token si revocano per-device dal pannello. Il prefisso `v1` esiste dal primo giorno perché rompere un'app già installata sugli store è molto più costoso che rompere una web-app.
**Conseguenza.** Nessun endpoint `/api/v1` può fare assunzioni su una sessione. Il tenant si risolve **dall'utente autenticato**, mai da un header manipolabile dal client.

### ADR-05 — AI: **layer astratto, driver Anthropic di default**
**Scelta.** Tutte le chiamate AI passano da `App\Services\Ai\Contracts\AiProvider`. Due implementazioni: `AnthropicProvider` (default) e `OpenAiProvider` (parità con l'app storica, fallback). Driver selezionabile da config e **override per-tenant**.
**Perché l'astrazione.** In multi-tenant l'AI è un costo variabile che va misurato, limitato e potenzialmente rivenduto. Un layer unico è l'unico punto dove si possono contare i token, applicare quote e cambiare fornitore senza toccare i controller.
**Perché Anthropic di default.** L'app ha bisogno di tre capacità che vanno tenute insieme: **vision** (stima cibo da foto), **structured output garantito** (lo schema JSON del cibo non può sbagliare) e **PDF nativo** (import schede). Claude le copre tutte e tre nello stesso endpoint `messages`, con `outputConfig.format` che valida lo schema lato server invece di sperare in un JSON ben formato.
**Modelli e costi (verificati 2026-08-08).**

| Task | Modello | Motivo |
|---|---|---|
| Parsing PDF scheda (F7) | `claude-opus-5` | Una tantum per import, qualità massima, layout PDF arbitrari |
| Stima cibo da foto | `claude-sonnet-5` | Vision buona a costo medio, alto volume |
| Stima cibo da testo | `claude-haiku-4-5` | Task semplice, latenza bassa, altissimo volume |
| Consiglio del giorno | `claude-haiku-4-5` | Output breve, una volta al giorno per utente |
| kcal allenamento | `claude-haiku-4-5` | Output = un intero |

Listino (input/output per milione di token): Opus 5 `$5 / $25` · Sonnet 5 `$3 / $15` (promo `$2 / $10` fino al 2026-08-31) · Haiku 4.5 `$1 / $5`.
**I model id sono stringhe complete: mai aggiungere suffissi di data.**

### ADR-06 — Ruoli e permessi: **`spatie/laravel-permission` in modalità teams**
**Scelta.** `teams` attivo, con `team_foreign_key = tenant_id`. Quattro ruoli: `super_admin` (team `null`), `gym_admin`, `trainer`, `member`.
**Perché.** Lo stesso utente-email non deve poter essere `trainer` in una palestra e `gym_admin` in un'altra per errore; la modalità teams lega il ruolo al tenant a livello di tabella pivot. Il pacchetto richiede `php ^8.3` ✔ e supporta `illuminate ^13` ✔.

### ADR-07 — Chat: **Laravel Reverb (WebSocket self-hosted)**
**Scelta.** Reverb + broadcasting su canali privati `conversation.{id}`, autorizzati da `routes/channels.php`.
**Perché.** Niente dipendenza da Pusher a consumo, gira nello stesso stack, e in staging è un container in più. Il fallback di polling resta possibile lato app se il socket non si apre (vedi F8.4).

### ADR-08 — Storage media: **`spatie/laravel-medialibrary`**
**Scelta.** Foto progressi, foto allenamento, loghi palestra e PDF sorgente delle schede passano tutti da medialibrary, con conversion `thumb` (400px) e `preview` (1200px) per le immagini.
**Perché.** Le foto progressi in griglia lazy erano già un requisito; generare thumbnail a mano è lavoro sprecato. I file restano su disco privato e sono serviti da endpoint autenticati — **mai URL pubblici** (regola ereditata dall'app storica, §13).

### ADR-09 — Il codice storico è un **capitolato, non una base**
**Scelta.** Non si porta il codice di `training` (Blade + Alpine, single-user, Authelia). Si riscrive.
**Perché.** Ogni controller storico assume un utente unico e una sessione SSO. Portare quel codice significherebbe riscriverlo comunque, ma con addosso assunzioni sbagliate. **Le uniche cose che si portano quasi identiche** sono la logica pura, che è corretta e testabile: `FoodUnit`, `CalorieCalculator`, `WorkoutCalorieService` (formula MET), le soglie dell'ipnogramma.

### ADR-10 — Sviluppo locale prima, staging dopo
**Scelta.** F0→F10 si sviluppano interamente in locale su XAMPP/MariaDB. Il deploy su server di staging è la fase **F12**, non prima.
**Perché.** È la richiesta esplicita del committente. Conseguenza pratica: niente scorciatoie che funzionano solo in locale (es. `APP_URL=localhost` hardcodato, path Windows nei seeder, `storage:link` dato per scontato). Ogni fase deve restare deployabile.

---

## 3. Stack e versioni (verificate il 2026-08-08)

### 3.1 Backend — `trainingbe/`

| Componente | Vincolo | Verificato |
|---|---|---|
| PHP | `^8.3` | locale `8.3.21` (`E:\coding\php83\php.exe`) ✔ |
| laravel/framework | `^13.0` | ultima `13.24.0`, richiede `php ^8.3` ✔ |
| filament/filament | `^5.7` | ultima `5.7.6`, `illuminate/contracts ^11.28\|^12\|^13` ✔ |
| laravel/sanctum | `^4.3` | `illuminate/support ^11\|^12\|^13` ✔ |
| spatie/laravel-permission | `^8.3` | `php ^8.3`, `illuminate ^12\|^13` ✔ |
| spatie/laravel-medialibrary | `^11.23` | `illuminate ^10.2\|^11\|^12\|^13` ✔ |
| laravel/reverb | `^1.11` | `illuminate ^10.47\|^11\|^12\|^13` ✔ |
| laravel/horizon | `^5.48` | `illuminate ^9.21…^13` ✔ (solo staging) |
| anthropic-ai/sdk | `^0.41` | ultima `0.41.0`, `php ^8.1` ✔ |
| openai-php/laravel | `^0.20` | driver alternativo, `php ^8.2` ✔ |
| smalot/pdfparser | `^2.12` | estrazione testo PDF di riserva ✔ |
| pestphp/pest | `^4.0` | ⚠️ **Pest v5 richiede PHP ≥ 8.4**: restare su `^4` finché il locale è 8.3 |
| DB dev | MariaDB `10.4.32` (XAMPP) | funziona, ma è EOL |
| DB staging | MariaDB `11.4 LTS` o MySQL `8.4` | **target di F12** |

> ⚠️ **Trappola PHP.** Il `php` in PATH è `E:\coding\php83\php.exe` (8.3.21), **non** quello di XAMPP (`E:\coding\XAMPP\php\php.exe`, 8.2.12). Laravel 13 richiede `^8.3`: usare sempre il primo. Se Apache di XAMPP serve il progetto, punta al PHP 8.2 e **fallirà** — in dev si usa `php artisan serve`, non Apache.

### 3.2 Frontend — `trainingfe/`

| Componente | Vincolo | Verificato |
|---|---|---|
| Flutter | `>= 3.44` stable | locale **3.27.1** (dic 2024) ⚠️ **da aggiornare in F0.4** — ultima stable `3.44.9` (2026-08-06) |
| Dart | `>= 3.12` | locale `3.6.0` ⚠️, target `3.12.2` |
| Node (tooling) | `22.x` | locale `22.13.0` ✔ |

Pacchetti Dart fissati in **F9.1** (dopo l'upgrade di Flutter, per non pinnare versioni incompatibili).

### 3.3 Repository e remote

| Repo | `origin` (Gitea, push-to-create) | `github` |
|---|---|---|
| `trainingbe` | `https://git.home.varitest.ovh/smp-webmaster/trainingbe.git` | `https://github.com/Frostmoore/trainingbe.git` |
| `trainingfe` | `https://git.home.varitest.ovh/smp-webmaster/trainingfe.git` | `https://github.com/Frostmoore/trainingfe.git` |

Utente Gitea: **`smp-webmaster`** (letto dal credential manager). Gitea raggiungibile solo via **Tailscale** (`git.home.varitest.ovh`, v1.26.4) — se il push `origin` fallisce, verificare la connessione Tailscale prima di ogni altra cosa.

---

## 4. Convenzioni di progetto

Queste valgono ovunque, per sempre. Sono ciò che rende il codice greppabile.

### 4.1 Lingua
- **Codice, nomi di classe, nomi di colonna, chiavi di config, rotte API: inglese.**
- **UI (Filament e app), messaggi di validazione, documentazione: italiano.**
  *Perché:* l'app storica mescolava le due cose (`/diario`, `/allenamento`, `/schede` accanto a `FoodEntry`, `WorkoutPlan`) e il risultato è che non si sa mai come si chiama una cosa. Qui la regola è unica: **il codice è inglese, quello che legge l'utente è italiano**, e la traduzione vive nei file `lang/it/`.

### 4.2 Naming
- Tabelle: `snake_case` plurale (`workout_plans`).
- Pivot: nomi dei due modelli singolari in ordine alfabetico (`trainer_member` è l'eccezione motivata: entrambi sono `users`, quindi il nome descrive i ruoli).
- Modelli: `StudlyCase` singolare.
- Controller API: `App\Http\Controllers\Api\V1\<Risorsa>Controller`.
- Form Request: `App\Http\Requests\Api\V1\<Azione><Risorsa>Request`.
- API Resource: `App\Http\Resources\V1\<Risorsa>Resource`.
- Job: verbo all'infinito + nome (`ParseWorkoutPdf`).
- Enum PHP: `App\Enums\<Nome>` con backing `string`.

### 4.3 Regole di codice
- **Ogni modello tenant-owned usa il trait `BelongsToTenant`.** Nessuna eccezione senza una riga in §15.
- **Ogni endpoint API restituisce un `JsonResource`**, mai un array grezzo, mai un modello Eloquent.
- **Ogni scrittura passa da un Form Request.** Niente `$request->all()`.
- **Ogni classe con logica non banale ha un test.** Il catalogo test è §12 e va aggiornato nella stessa fase in cui nasce la classe.
- **Enum, non stringhe magiche**, per: ruolo, stato tenant, pasto, sorgente kcal, tipo foto, stato scheda.
- **Zero segreti in git.** `.env` è gitignorato; `.env.example` elenca tutte le chiavi con valori fittizi.

---

## 5. ⚠️ RITUALE DI FINE FASE — obbligatorio

**Alla fine di ciascuna fase** (non sottofase), eseguire **senza che venga chiesto**, in quest'ordine:

### 5.1 — Aggiorna `plan_training_companion.md`
Spunta le checkbox della fase e delle sue sottofasi in §7. Se durante la fase sono emerse decisioni nuove, aggiungile in §2 come ADR; se sono emerse trappole, aggiungile in §14; se qualcosa è stato rimandato, aggiungilo in §15. Aggiorna la riga «Stato» in testa al documento.

### 5.2 — Aggiorna `codebase_reference.md`
Vive in `trainingbe/codebase_reference.md`. **Va creato alla fine della prima fase che produce codice (F1)** e aggiornato a ogni fase successiva. Deve contenere, secondo i criteri già stabiliti: indice «dove sta cosa», albero dei file annotato, ogni classe con ogni metodo e **firma completa**, ogni tabella con ogni colonna/indice/vincolo, ogni endpoint con middleware/input/output/codici d'errore, ogni chiave di config, catalogo dei test, regole non negoziabili, trappole disinnescate, debito tecnico, e il *perché* delle scelte non ovvie.

**Verifica meccanica obbligatoria** prima di considerare il punto chiuso:
```bash
# Firme dei metodi pubblici realmente presenti nel codice
grep -rn --include=*.php -E '^\s+(public|protected)\s+(static\s+)?function ' app/ | sed 's/{$//'
# Rotte reali
php artisan route:list --json > storage/app/private/_routes.json
# Schema reale
php artisan db:show --counts && php artisan db:table <tabella>
```
Confronta l'output con quanto documentato. **Un atlante sbagliato è peggio di nessun atlante.**

### 5.3 — Messaggio di fine fase, ESTREMAMENTE DETTAGLIATO
Un solo messaggio contenente:
- **Stato dell'implementazione** complessiva.
- **Checkbox** della fase chiusa e di tutte le sue sottofasi, con lo stato reale.
- **Commento** sullo stato generale del progetto **e** sullo stato specifico della fase appena chiusa: cosa funziona, cosa è stato verificato e come, cosa è rimasto fuori e perché.
- Elenco dei file creati/modificati e dei test aggiunti, con l'esito reale dell'esecuzione (se i test falliscono, si dice e si riporta l'output).

### 5.4 — Commit e push su un branch con versione nuova
Vedi §6. Entrambi i repo, entrambe le remote.

---

## 6. Versionamento e branch

I branch si chiamano **come le versioni**, partendo da `v1.0.0`. La numerazione avanza **a ogni commit** secondo l'entità della modifica:

| Entità | Incremento | Esempio |
|---|---|---|
| Piccola (fix, doc, refactor locale) | `+0.0.1` | `v1.0.0` → `v1.0.1` |
| Media (nuova feature dentro una fase) | `+0.1.0` | `v1.0.1` → `v1.1.0` |
| Grande (fine fase, breaking change, nuova area) | `+1.0.0` | `v1.1.0` → `v2.0.0` |

**Perché:** tracciabilità totale — documenti sempre allineati al codice, storia git leggibile per versioni, e a colpo d'occhio si valuta cosa è fatto e cosa manca senza doverlo chiedere.

### 6.1 Procedura esatta

```bash
cd trainingbe            # (ripetere per trainingfe)
git checkout -b vX.Y.Z
git add -A
git commit -m "<tipo>: <descrizione>

<corpo: cosa cambia e perché>"
git push -u origin vX.Y.Z          # Gitea (push-to-create: crea il repo se non esiste)
git push github vX.Y.Z             # GitHub
```

`trainingbe` e `trainingfe` avanzano di versione **in modo indipendente**: una fase che tocca solo il backend fa avanzare solo `trainingbe`.

---

## 7. TRACKING DELLE FASI

Legenda: `[ ]` da fare · `[~]` in corso · `[x]` fatto e verificato.

### `[~]` F0 — Bootstrap dei repository e del toolchain
- `[x]` **F0.1** — Ricognizione ambiente (PHP, Composer, Flutter, DB, git, remote)
- `[x]` **F0.2** — Decisioni architetturali fissate (§2) e stack verificato (§3)
- `[x]` **F0.3** — Stesura di `plan_training_companion.md`
- `[ ]` **F0.4** — Aggiornamento Flutter `3.27.1` → `3.44.x` + Dart `3.12.x`
- `[ ]` **F0.5** — `git init`, `.gitignore`, README, remote (Gitea + GitHub) su entrambi i repo
- `[ ]` **F0.6** — Primo push `v1.0.0` su entrambe le remote di entrambi i repo

### `[ ]` F1 — Fondamenta multi-tenant e autenticazione
- `[ ]` **F1.1** — Installazione Laravel 13 e configurazione base (`.env`, timezone, locale, DB)
- `[ ]` **F1.2** — Modello `Tenant`, migration, enum di stato
- `[ ]` **F1.3** — `TenantContext`, `TenantScope`, trait `BelongsToTenant`
- `[ ]` **F1.4** — `User` esteso (tenant, ruoli, `canAccessPanel`), `spatie/laravel-permission` in modalità teams
- `[ ]` **F1.5** — Sanctum: registrazione, login, logout, refresh, revoca device
- `[ ]` **F1.6** — Middleware `ResolveTenant` + `EnsureTenantActive`
- `[ ]` **F1.7** — Seeder: super admin, palestra demo, ruoli, permessi
- `[ ]` **F1.8** — `TenantIsolationTest` + test auth (il gate qualitativo della fase)
- `[ ]` **F1.9** — Creazione di `codebase_reference.md`

### `[ ]` F2 — Pannello `/god` (super-admin)
- `[ ]` **F2.1** — `GodPanelProvider` + policy di accesso
- `[ ]` **F2.2** — Resource `Tenant` (CRUD, branding, piano, quota AI, stato)
- `[ ]` **F2.3** — Resource `User` globale + impersonazione
- `[ ]` **F2.4** — Resource `Exercise` (libreria globale)
- `[ ]` **F2.5** — Dashboard: tenant attivi, iscritti totali, consumo AI e costo stimato

### `[ ]` F3 — Pannello `/admin` (palestra)
- `[ ]` **F3.1** — `GymPanelProvider` + risoluzione tenant dall'utente + branding dinamico del pannello
- `[ ]` **F3.2** — Pagina Impostazioni palestra (branding: logo, colori, nome, codice d'invito)
- `[ ]` **F3.3** — Resource `Trainer` (CRUD utenti con ruolo `trainer`)
- `[ ]` **F3.4** — Resource `Member` (CRUD iscritti, invito via email/codice, stato)
- `[ ]` **F3.5** — Assegnazione iscritto ↔ trainer (pivot + UI) e scoping delle viste del trainer
- `[ ]` **F3.6** — Policy complete per i tre ruoli + test di autorizzazione

### `[ ]` F4 — Dominio allenamento
- `[ ]` **F4.1** — Migration e modelli: `exercises`, `workout_plans`, `plan_exercises`
- `[ ]` **F4.2** — Migration e modelli: `workout_sessions`, `session_sets`, `body_metrics`, `photos`
- `[ ]` **F4.3** — `WorkoutCalorieService` (MET + hook AI + override manuale)
- `[ ]` **F4.4** — Filament: editor schede + assegnazione a iscritto
- `[ ]` **F4.5** — API v1 allenamento (schede, sessioni, serie, foto)
- `[ ]` **F4.6** — Test dominio allenamento

### `[ ]` F5 — Dominio nutrizione
- `[ ]` **F5.1** — Migration e modelli: `food_entries`, `food_favorites`, `daily_burns`
- `[ ]` **F5.2** — Migration e modelli: `nutrition_plans`, `nutrition_plan_meals`, `nutrition_plan_items`
- `[ ]` **F5.3** — Port di `FoodUnit` e `CalorieCalculator` (+ test, sono logica pura)
- `[ ]` **F5.4** — Filament: editor piano alimentare + assegnazione
- `[ ]` **F5.5** — API v1 nutrizione (diario, preferiti, piano assegnato, target)
- `[ ]` **F5.6** — Test dominio nutrizione

### `[ ]` F6 — Layer AI
- `[ ]` **F6.1** — Contratto `AiProvider`, DTO, `AiManager`, config `config/ai.php`
- `[ ]` **F6.2** — `AnthropicProvider`: cibo da testo, cibo da foto, kcal, consiglio
- `[ ]` **F6.3** — `OpenAiProvider` (parità funzionale, driver alternativo)
- `[ ]` **F6.4** — `ai_usage_logs`, `AiUsageRecorder`, calcolo costo
- `[ ]` **F6.5** — Quote per tenant + `AiQuotaExceededException` + gestione a livello API
- `[ ]` **F6.6** — API v1 AI + test con provider fake

### `[ ]` F7 — Import schede da PDF (AI)
- `[ ]` **F7.1** — Upload PDF nel pannello palestra + `workout_plan_imports`
- `[ ]` **F7.2** — Job `ParseWorkoutPdf` (document block Claude + structured output)
- `[ ]` **F7.3** — Riconciliazione esercizi (matching su libreria + creazione custom)
- `[ ]` **F7.4** — UI di revisione della bozza prima della pubblicazione
- `[ ]` **F7.5** — Test con PDF campione

### `[ ]` F8 — Chat trainer ↔ iscritto
- `[ ]` **F8.1** — Migration e modelli: `conversations`, `messages`
- `[ ]` **F8.2** — Broadcasting Reverb + autorizzazione canali
- `[ ]` **F8.3** — Filament: interfaccia chat lato trainer
- `[ ]` **F8.4** — API v1 chat (lista, storico paginato, invio, letto, fallback polling)
- `[ ]` **F8.5** — Notifiche push (registrazione device token; invio in F12)

### `[ ]` F9 — App Flutter: fondamenta
- `[ ]` **F9.1** — Scaffolding progetto, struttura cartelle, pacchetti, linting
- `[ ]` **F9.2** — Bootstrap + flavor + configurazione ambiente
- `[ ]` **F9.3** — Client API generato/tipizzato + gestione errori + interceptor token
- `[ ]` **F9.4** — Onboarding: codice palestra → branding → tema a runtime
- `[ ]` **F9.5** — Auth: login, logout, refresh, storage sicuro del token
- `[ ]` **F9.6** — Shell di navigazione + design system derivato dal branding

### `[ ]` F10 — App Flutter: funzionalità
- `[ ]` **F10.1** — Home e dashboard (peso, calorie, medie, consiglio del giorno)
- `[ ]` **F10.2** — Diario cibo (AI testo, AI foto, manuale, preferiti, unità di misura)
- `[ ]` **F10.3** — Schede assegnate + player allenamento (serie, timer, kcal, foto)
- `[ ]` **F10.4** — Piano alimentare assegnato
- `[ ]` **F10.5** — Storico allenamenti, calendario, galleria progressi
- `[ ]` **F10.6** — Chat col trainer
- `[ ]` **F10.7** — Profilo, peso, obiettivi, impostazioni

### `[ ]` F11 — Sonno e ingest wearable (opzionale)
- `[ ]` **F11.1** — `health_samples` + endpoint ingest con token per-utente
- `[ ]` **F11.2** — Ipnogramma e valutazione salute del sonno (API + UI app)

### `[ ]` F12 — Hardening e staging
- `[ ]` **F12.1** — Copertura test end-to-end dei percorsi critici
- `[ ]` **F12.2** — Rate limiting, throttling AI, audit log
- `[ ]` **F12.3** — Security review (`/security-review`) e correzioni
- `[ ]` **F12.4** — Docker compose, migrazione a MariaDB 11.4, Horizon, Reverb
- `[ ]` **F12.5** — Deploy su server di staging + runbook
- `[ ]` **F12.6** — Build app di staging (TestFlight / internal testing) e notifiche push

---

## 8. GUIDA ALLO SVILUPPO

Ogni fase ha: **Obiettivo**, **Passi**, **Classi da creare (percorso + firma)**, **Definizione di fatto**.

---

### F0 — Bootstrap dei repository e del toolchain

**Obiettivo.** Due repository versionati, allineati alle due remote, con il toolchain aggiornato e questo piano committato.

#### F0.4 — Aggiornamento Flutter
```powershell
flutter upgrade                 # 3.27.1 -> 3.44.x
flutter --version               # atteso: >= 3.44.9, Dart >= 3.12.2
flutter doctor -v               # risolvere tutto ciò che non è "no issues"
```
**Perché ora:** pinnare i pacchetti Dart su Flutter 3.27 (dicembre 2024) e poi aggiornare significa rifare il `pubspec.lock` da zero. Si aggiorna **prima** di scrivere una riga di Dart.

#### F0.5 — Inizializzazione repository

Per **ciascuno** dei due repo:
```bash
cd <repo>
git init -b v1.0.0
git remote add origin https://git.home.varitest.ovh/smp-webmaster/<repo>.git
git remote add github https://github.com/Frostmoore/<repo>.git
```

File da creare subito:
- `trainingbe/.gitignore` — standard Laravel (`/vendor`, `/node_modules`, `.env`, `/storage/*.key`, `/public/build`, `/public/storage`, `.phpunit.result.cache`).
- `trainingfe/.gitignore` — standard Flutter (`.dart_tool/`, `build/`, `.flutter-plugins*`, `ios/Pods/`, `android/.gradle/`, `*.iml`, `.env`).
- `trainingbe/README.md` — cos'è, come si avvia in locale, dove sta il piano.
- `trainingfe/README.md` — cos'è + **puntatore esplicito** a `trainingbe/plan_training_companion.md` come fonte di verità.
- `trainingbe/plan_training_companion.md` — questo file.

#### F0.6 — Primo push
```bash
git add -A && git commit -m "chore: bootstrap repository + specsheet di sviluppo"
git push -u origin v1.0.0
git push github v1.0.0
```
> ⚠️ GitHub **non** supporta push-to-create: se i repo `Frostmoore/trainingbe` e `Frostmoore/trainingfe` non esistono ancora, il push `github` fallisce con `Repository not found`. Vanno creati a mano su github.com (o con `gh repo create`, non installato su questa macchina). **Il fallimento del push GitHub non blocca la fase**: Gitea è la remote primaria.

**Definizione di fatto F0.** `git log --oneline` mostra un commit su `v1.0.0` in entrambi i repo; `git remote -v` mostra due remote per repo; `flutter --version` ≥ 3.44.

---

### F1 — Fondamenta multi-tenant e autenticazione

**Obiettivo.** Uno scheletro Laravel 13 in cui *è impossibile* leggere i dati di un'altra palestra per distrazione, con autenticazione API funzionante e un test che lo dimostra.

#### F1.1 — Installazione
```bash
cd trainingbe
composer create-project laravel/laravel . "^13.0"     # in cartella non vuota: vedi nota
composer require laravel/sanctum:^4.3 spatie/laravel-permission:^8.3 \
                 spatie/laravel-medialibrary:^11.23 filament/filament:^5.7 \
                 anthropic-ai/sdk:^0.41 smalot/pdfparser:^2.12
composer require --dev pestphp/pest:^4.0 pestphp/pest-plugin-laravel
```
> **Nota cartella non vuota.** `create-project` esige una cartella vuota. Poiché `plan_training_companion.md`, `README.md` e `.gitignore` sono già presenti, installare in `trainingbe/_tmp/` e poi spostare il contenuto su, oppure spostare temporaneamente i tre file. **Non cancellarli.**

`.env` da configurare:
```
APP_NAME="Training Companion"
APP_TIMEZONE=Europe/Rome
APP_LOCALE=it
APP_FALLBACK_LOCALE=it
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=training_companion
DB_USERNAME=root
DB_PASSWORD=
```
> ⚠️ **Timezone.** L'app storica è stata morsa da questo: Eloquent **non** converte il fuso in scrittura. `APP_TIMEZONE=Europe/Rome` va impostato dal primo giorno, e ogni timestamp che arriva dall'esterno (ingest wearable, payload app) va normalizzato esplicitamente. Vedi §14.

#### F1.2 — `Tenant`

**`database/migrations/xxxx_create_tenants_table.php`**

| Colonna | Tipo | Note |
|---|---|---|
| `id` | `bigIncrements` | |
| `name` | `string(120)` | nome commerciale della palestra |
| `slug` | `string(60)` | UNIQUE, usato negli URL |
| `join_code` | `string(12)` | UNIQUE, il codice che l'iscritto digita nell'app |
| `status` | `string(20)` | enum `TenantStatus`, default `trial` |
| `plan` | `string(20)` | `starter` \| `pro` \| `enterprise` |
| `logo_path` | `string` null | gestito da medialibrary; colonna di comodo per il branding cache |
| `color_primary` | `string(7)` | default `#111827` |
| `color_secondary` | `string(7)` | default `#6B7280` |
| `color_accent` | `string(7)` | default `#F59E0B` |
| `contact_email` | `string` null | |
| `locale` | `string(5)` | default `it` |
| `timezone` | `string(64)` | default `Europe/Rome` |
| `ai_monthly_token_cap` | `unsignedBigInteger` null | `null` = illimitato |
| `ai_driver` | `string(20)` null | override di `config('ai.default')` |
| `settings` | `json` null | ripostiglio tipizzato, **non** per dati che si interrogano |
| `trial_ends_at` | `timestamp` null | |
| `timestamps`, `softDeletes` | | |

**`app/Enums/TenantStatus.php`**
```php
enum TenantStatus: string {
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    public function label(): string;
    public function allowsLogin(): bool;   // Trial|Active => true
}
```

**`app/Models/Tenant.php`**
| Metodo | Firma | Effetto |
|---|---|---|
| `users` | `users(): HasMany` | tutti gli utenti della palestra |
| `members` | `members(): HasMany` | utenti con ruolo `member` |
| `trainers` | `trainers(): HasMany` | utenti con ruolo `trainer` |
| `isActive` | `isActive(): bool` | `status->allowsLogin()` e trial non scaduto |
| `branding` | `branding(): array` | `{name, slug, logo_url, colors:{primary,secondary,accent}, locale}` — è il payload dell'endpoint pubblico |
| `aiTokensUsedThisMonth` | `aiTokensUsedThisMonth(): int` | somma su `ai_usage_logs` del mese corrente |
| `hasAiQuotaLeft` | `hasAiQuotaLeft(): bool` | `cap === null \|\| usati < cap` |

`casts`: `status => TenantStatus::class`, `settings => 'array'`, `trial_ends_at => 'datetime'`.

#### F1.3 — Il cuore della tenancy

**`app/Support/Tenancy/TenantContext.php`** — singleton registrato in `AppServiceProvider`.
| Metodo | Firma | Effetto |
|---|---|---|
| `set` | `set(?Tenant $tenant): void` | imposta il tenant corrente |
| `get` | `get(): ?Tenant` | |
| `id` | `id(): ?int` | |
| `has` | `has(): bool` | |
| `forget` | `forget(): void` | |
| `runAs` | `runAs(Tenant $tenant, \Closure $callback): mixed` | esegue con quel tenant e **ripristina il precedente** anche in caso di eccezione (`try/finally`) |
| `runWithoutTenant` | `runWithoutTenant(\Closure $callback): mixed` | **unica** via legittima per uscire dallo scope; ripristina in `finally` |

**`app/Models/Scopes/TenantScope.php implements \Illuminate\Database\Eloquent\Scope`**
```php
public function apply(Builder $builder, Model $model): void;
```
Comportamento: se `TenantContext::has()` è falso, **non applica alcun filtro** (contesto di console/god panel); altrimenti aggiunge `where {table}.tenant_id = :id`.
> **Perché non filtrare quando il contesto è vuoto, invece di negare tutto?** Perché migration, seeder, comandi artisan e il pannello `/god` girano legittimamente senza tenant. La sicurezza non sta qui: sta nel fatto che ogni richiesta HTTP autenticata **imposta sempre** il contesto (middleware `ResolveTenant`), e che il pannello `/god` è protetto da policy. Se lo scope negasse tutto a contesto vuoto, ogni comando di manutenzione richiederebbe un bypass, e i bypass diventerebbero rumore di fondo — il modo classico in cui un controllo di sicurezza smette di essere letto.

**`app/Models/Concerns/BelongsToTenant.php`** (trait)
| Metodo | Firma | Effetto |
|---|---|---|
| `bootBelongsToTenant` | `protected static function bootBelongsToTenant(): void` | registra `TenantScope`; su `creating` riempie `tenant_id` da `TenantContext::id()` se nullo |
| `tenant` | `tenant(): BelongsTo` | |
| `scopeForTenant` | `scopeForTenant(Builder $q, Tenant\|int $tenant): Builder` | filtro esplicito, usato dal pannello `/god` |

#### F1.4 — Utenti e ruoli

**`app/Enums/UserRole.php`**
```php
enum UserRole: string {
    case SuperAdmin = 'super_admin';
    case GymAdmin   = 'gym_admin';
    case Trainer    = 'trainer';
    case Member     = 'member';
    public function label(): string;
    public function isPlatformLevel(): bool;   // solo SuperAdmin
}
```

**`app/Models/User.php implements FilamentUser`** — aggiunge `tenant_id` (nullable, FK), `HasApiTokens`, `HasRoles`, `InteractsWithMedia`.
| Metodo | Firma | Effetto |
|---|---|---|
| `canAccessPanel` | `canAccessPanel(\Filament\Panel $panel): bool` | `god` → solo `super_admin`; `admin` → `gym_admin`\|`trainer` con tenant attivo; `member` → **sempre false** |
| `tenant` | `tenant(): BelongsTo` | |
| `profile` | `profile(): HasOne` | |
| `assignedMembers` | `assignedMembers(): BelongsToMany` | trainer → iscritti (pivot `trainer_member`) |
| `assignedTrainers` | `assignedTrainers(): BelongsToMany` | iscritto → trainer |
| `isSuperAdmin` | `isSuperAdmin(): bool` | |
| `isTrainer` / `isGymAdmin` / `isMember` | `(): bool` | |
| `latestWeight` | `latestWeight(): ?float` | ultimo `body_metrics.weight_kg` |

**`app/Models/Profile.php`** — `user_id` UNIQUE, `sex` enum(m,f), `birthdate`, `height_cm`, `activity_level`, `goal`, `target_weight_kg`, `meal_hours` json. `age(): ?int`.

**Pivot `trainer_member`**: `trainer_id`, `member_id`, `tenant_id`, `assigned_at`, `assigned_by`. UNIQUE(`trainer_id`,`member_id`).

Config `spatie/laravel-permission`: `teams => true`, `team_foreign_key => 'tenant_id'`.

#### F1.5 — Autenticazione API

**`app/Http/Controllers/Api/V1/AuthController.php`**
| Metodo | Firma | Effetto |
|---|---|---|
| `register` | `register(RegisterRequest $r): JsonResponse` | crea `member` dentro il tenant risolto dal `join_code`; ruolo `member`; ritorna token |
| `login` | `login(LoginRequest $r): JsonResponse` | valida credenziali **e** `tenant->isActive()`; ritorna token + user + branding |
| `logout` | `logout(Request $r): JsonResponse` | revoca il token corrente |
| `me` | `me(Request $r): UserResource` | |
| `devices` | `devices(Request $r): AnonymousResourceCollection` | token attivi dell'utente |
| `revokeDevice` | `revokeDevice(Request $r, int $tokenId): JsonResponse` | |

**`app/Http/Controllers/Api/V1/BrandingController.php`**
| `lookup` | `lookup(Request $r): JsonResponse` | **pubblico, non autenticato**, throttled `10,1`. Input `?code=ABC123`. Output `Tenant::branding()`. 404 se il codice non esiste o il tenant non è attivo. |

> ⚠️ Questo è l'unico endpoint pubblico che espone dati di tenant. Deve restituire **solo** il payload di branding e **404 indistinguibile** fra «codice inesistente» e «palestra sospesa» — altrimenti diventa un oracolo per enumerare i clienti.

#### F1.6 — Middleware

**`app/Http/Middleware/ResolveTenant.php`** — `handle(Request $request, \Closure $next): Response`
Se c'è un utente autenticato con `tenant_id`, chiama `TenantContext::set()`. **Non legge mai header o parametri forniti dal client** (ADR-04).

**`app/Http/Middleware/EnsureTenantActive.php`** — `handle(Request $request, \Closure $next): Response`
403 con codice `tenant_inactive` se `!$tenant->isActive()`.

Registrazione in `bootstrap/app.php`: alias `tenant` e `tenant.active`; il gruppo `api` diventa `['auth:sanctum', 'tenant', 'tenant.active']` per le rotte protette.

#### F1.7 — Seeder
`RoleSeeder` (4 ruoli + permessi), `SuperAdminSeeder` (dalle env `SEED_SUPERADMIN_EMAIL/PASSWORD`), `DemoTenantSeeder` (palestra «Palestra Demo», 1 gym_admin, 2 trainer, 6 member, assegnazioni).

#### F1.8 — Il gate qualitativo della fase

**`tests/Feature/Tenancy/TenantIsolationTest.php`** — deve contenere almeno:
1. `it_scopes_every_tenant_owned_model` — **riflessione su tutti i modelli in `app/Models`**: se il modello ha la colonna `tenant_id`, deve usare `BelongsToTenant`, altrimenti il test fallisce nominando la classe.
2. `it_hides_other_tenants_records` — due tenant, un record ciascuno; nel contesto A, `Model::count() === 1`.
3. `it_auto_fills_tenant_id_on_create`.
4. `it_restores_context_after_exception_in_runAs`.
5. `it_forbids_login_for_suspended_tenant`.
6. `it_returns_404_for_unknown_join_code`.

**Definizione di fatto F1.** `php artisan migrate:fresh --seed` va a buon fine; `php artisan test` verde; login via API restituisce token e branding; il test di isolamento passa **e fallirebbe** se si togliesse il trait da un modello (verificarlo togliendolo davvero una volta).

---

### F2 — Pannello `/god`

**Obiettivo.** Il pannello da cui gestisco la piattaforma.

- **F2.1** `app/Providers/Filament/GodPanelProvider.php` — `id('god')`, `path('god')`, guard `web`, colore neutro fisso (il pannello piattaforma **non** è brandizzato). Accesso via `canAccessPanel`.
- **F2.2** `app/Filament/God/Resources/TenantResource.php` — form: dati, branding (color picker + upload logo con preview), piano, `ai_monthly_token_cap`, `ai_driver`, stato. Tabella con contatore iscritti e consumo AI del mese. Azioni: sospendi/riattiva, rigenera `join_code`.
- **F2.3** `app/Filament/God/Resources/UserResource.php` — vista globale (`runWithoutTenant`), filtro per tenant e ruolo. Azione **Impersona**: genera un token temporaneo e registra l'evento in `audit_logs`. *Motivo:* il supporto è la ragione numero uno per cui servirà entrare in un account cliente, e farlo senza traccia è inaccettabile.
- **F2.4** `app/Filament/God/Resources/ExerciseResource.php` — libreria globale (`tenant_id` null = disponibile a tutti).
- **F2.5** `app/Filament/God/Widgets/*` — tenant attivi, iscritti totali, token AI del mese, costo stimato (da §ADR-05), top 5 tenant per consumo.

**Definizione di fatto F2.** Da `/god` si crea una palestra completa di branding, si vede il consumo AI e si impersona un utente lasciando traccia.

---

### F3 — Pannello `/admin` (palestra)

**Obiettivo.** Il pannello che il cliente usa ogni giorno, brandizzato con i suoi colori.

- **F3.1** `app/Providers/Filament/GymPanelProvider.php` — `id('admin')`, `path('admin')`. Nel `->bootUsing()` imposta `TenantContext` dall'utente e applica i colori del tenant al tema (`->colors([...])` calcolato a runtime). *Perché il branding anche qui:* il gym_admin vede il proprio marchio, non il mio — è metà del valore percepito del white-label.
- **F3.2** `app/Filament/Gym/Pages/GymSettings.php` — form su `Tenant` corrente, visibile **solo** a `gym_admin`.
- **F3.3** `app/Filament/Gym/Resources/TrainerResource.php` — CRUD di `User` con ruolo `trainer`, scoped al tenant.
- **F3.4** `app/Filament/Gym/Resources/MemberResource.php` — CRUD iscritti, invito (email con link + `join_code`), attivazione/disattivazione. Relation manager: schede assegnate, piani alimentari, ultimi allenamenti.
- **F3.5** Assegnazione: relation manager `Members` su `TrainerResource` e `Trainers` su `MemberResource`. **Lo scoping del trainer si implementa in `getEloquentQuery()` di ogni resource**, non nella vista: un trainer che apre `MemberResource` vede solo `auth()->user()->assignedMembers()`.
- **F3.6** Policy: `TenantPolicy`, `UserPolicy`, `WorkoutPlanPolicy`, `NutritionPlanPolicy`, `ConversationPolicy`. Test `tests/Feature/Authorization/RoleScopingTest.php`: un trainer che tenta l'accesso diretto all'id di un iscritto non assegnato riceve 403.

**Definizione di fatto F3.** Tre login (gym_admin, trainer, member) mostrano tre comportamenti corretti e distinti, con test che lo dimostrano.

---

### F4 — Dominio allenamento

**Obiettivo.** Schede create in palestra, eseguite in app.

#### F4.1 / F4.2 — Tabelle

- **`exercises`** — `tenant_id` **null** (globale) o valorizzato (custom di palestra), `name`, `muscle_group`, `equipment`, `is_custom`, `created_by`. INDEX(`tenant_id`,`name`).
  > Questa è l'unica tabella con `tenant_id` nullable per design: il global scope va quindi esteso a includere le righe globali. Si implementa con un trait dedicato `BelongsToTenantOrGlobal` — **non** riusare `BelongsToTenant`, che filtrerebbe via la libreria condivisa. Vedi §14.
- **`workout_plans`** — `tenant_id`, `member_id` null (null = template di palestra), `created_by`, `name`, `notes`, `status` (`draft`\|`published`\|`archived`), `source` (`manual`\|`pdf_import`), `published_at`.
- **`plan_exercises`** — `plan_id`, `exercise_id`, `position`, `sets`, `reps` (stringa: «8-12» è legittimo), `rest_sec`, `target_weight`, `notes`.
- **`workout_sessions`** — `tenant_id`, `user_id`, `plan_id` null, `started_at`, `ended_at`, `kcal_burned`, `kcal_source` (`manual`\|`ai`\|`formula`), `notes`.
- **`session_sets`** — `session_id`, `exercise_id`, `set_number`, `reps`, `weight`, `rest_sec`, `done_at`.
- **`body_metrics`** — `tenant_id`, `user_id`, `date`, `weight_kg`, `body_fat_pct`. UNIQUE(`user_id`,`date`).
- **`photos`** — gestite da medialibrary con collection `progress` / `workout`; la tabella `media` porta `tenant_id` via colonna custom.

#### F4.3 — `app/Services/Training/WorkoutCalorieService.php`
Porta dell'omonimo servizio storico, ripulito dal single-user.
| Metodo | Firma |
|---|---|
| costruttore | `__construct(private AiManager $ai)` |
| | `bodyweight(User $user): float` — ultimo peso, fallback `75.0` |
| | `manualDaily(User $user, CarbonInterface $date): ?int` |
| | `dailyBurned(User $user, CarbonInterface $date, float $kg): DailyBurnResult` |
| | `formulaKcal(WorkoutSession $s, float $kg): int` — MET, `const MET = 5.0` |
| | `kcalOf(WorkoutSession $s, float $kg): int` — salvato, altrimenti formula |
| | `estimateAndStore(WorkoutSession $s, User $user): void` — AI con fallback formula, **idempotente** |

> **Regola ereditata e confermata:** il valore **manuale batte sempre la stima**. Le aggregazioni (dashboard, calendario, diario) devono usare `kcalOf`/`dailyBurned` e mai ricalcolare, altrimenti i tre schermi mostrano tre numeri diversi — è successo nell'app storica ed è il motivo per cui questa regola è scritta qui.

#### F4.5 — API v1
```
GET    /api/v1/workout-plans                 schede assegnate all'utente
GET    /api/v1/workout-plans/{id}            dettaglio con esercizi
POST   /api/v1/workout-sessions              avvia sessione (opz. plan_id)
GET    /api/v1/workout-sessions              storico paginato
GET    /api/v1/workout-sessions/{id}         dettaglio + serie
POST   /api/v1/workout-sessions/{id}/sets    logga/aggiorna una serie
POST   /api/v1/workout-sessions/{id}/finish  chiude e stima kcal
PATCH  /api/v1/workout-sessions/{id}/kcal    override manuale (null = torna a stima)
DELETE /api/v1/workout-sessions/{id}
GET    /api/v1/exercises                     libreria (globale + tenant)
POST   /api/v1/body-metrics                  upsert peso per data
GET    /api/v1/body-metrics                  serie storica
POST   /api/v1/photos                        upload (multipart)
GET    /api/v1/photos                        griglia paginata
GET    /api/v1/photos/{id}/file              stream autenticato
DELETE /api/v1/photos/{id}
```

**Definizione di fatto F4.** Un trainer crea una scheda in `/admin`, la assegna, e l'endpoint `/api/v1/workout-plans` di quell'iscritto la restituisce; un altro iscritto non la vede.

---

### F5 — Dominio nutrizione

**Obiettivo.** Diario cibo dell'iscritto + piano alimentare assegnato dal trainer. Sono due cose diverse che convivono.

#### F5.1 / F5.2 — Tabelle
- **`food_entries`** — `tenant_id`, `user_id`, `eaten_at`, `meal` (enum 6 pasti), `description`, `grams`, `qty`, `unit`, `kcal/protein/carbs/fat`, `kcal100/protein100/carbs100/fat100`, `source` (`manual`\|`ai_text`\|`ai_photo`\|`favorite`\|`plan`), `ai_raw` json. INDEX(`user_id`,`eaten_at`).
- **`food_favorites`** — come sopra + `is_meal` bool e `items` json per i preferiti-pasto.
- **`daily_burns`** — `tenant_id`, `user_id`, `date`, `kcal`. UNIQUE(`user_id`,`date`).
- **`nutrition_plans`** — `tenant_id`, `member_id`, `created_by`, `name`, `target_kcal`, `target_protein_g`, `target_carbs_g`, `target_fat_g`, `notes`, `status`, `starts_at`, `ends_at`.
- **`nutrition_plan_meals`** — `plan_id`, `meal`, `position`, `title`, `notes`.
- **`nutrition_plan_items`** — `meal_id`, `position`, `description`, `qty`, `unit`, `grams`, `kcal`, macro.

> **Perché il piano alimentare è separato dal diario.** Il piano è *prescrizione* (cosa dovresti mangiare), il diario è *consuntivo* (cosa hai mangiato). Tenerli nella stessa tabella sembra economico e poi rende impossibile la domanda che vale davvero: «quanto ha aderito al piano?». Con due tabelle, `source = 'plan'` sulle `food_entries` collega il consuntivo alla prescrizione e l'aderenza è una join.

#### F5.3 — Logica pura portata (con test)

**`app/Services/Nutrition/FoodUnit.php`** — solo metodi statici.
```php
public const FACTORS = ['g'=>1,'mg'=>0.001,'hg'=>100,'kg'=>1000,'ml'=>1,'cl'=>10,'dl'=>100,'l'=>1000,
                        'bicchiere'=>200,'cucchiaio'=>15,'tazza'=>240,'cucchiaino'=>5,'scoop'=>30];
public const ORDER = [...];                       // ordine del menu a tendina
public static function valid(?string $unit): ?string;
public static function toGrams(?float $qty, ?string $unit): ?float;
```

**`app/Services/Nutrition/CalorieCalculator.php`**
```php
public const ACTIVITY   = [...];   // sedentary, light, moderate, active, athlete
public const GOAL_DELTA = [...];   // lose, cut, maintain, bulk
public const MACRO_SPLIT= [...];   // % per obiettivo
public function bmi(float $kg, float $cm): float;
public function bmr(string $sex, float $kg, float $cm, int $age): float;     // Mifflin-St Jeor
public function tdee(float $bmr, string $activity): float;
public function calorieTarget(float $tdee, string $goal): int;
public function macros(int $kcal, string $goal): array;   // {protein_g, carb_g, fat_g}
```
> **Nota di port.** Nella firma storica `macros()` aveva un parametro `$kg` non più usato, tenuto per compatibilità. **Qui si toglie**: non esiste codice da retrocompatibilizzare, e un parametro morto in una firma nuova è un bug che aspetta.
> **Motivazione della scelta % anziché g/kg** (confermata): resta plausibile anche in deficit su persone pesanti, e `lose`/`cut` alzano le proteine per la ritenzione muscolare.

#### F5.5 — API v1
```
GET    /api/v1/diary?date=YYYY-MM-DD        voci per pasto + totali + target del giorno
POST   /api/v1/food-entries                 inserimento manuale
PATCH  /api/v1/food-entries/{id}
DELETE /api/v1/food-entries/{id}
POST   /api/v1/food-entries/{id}/favorite   toggle preferito
GET    /api/v1/food-favorites
POST   /api/v1/food-favorites/meal          salva un intero pasto
POST   /api/v1/food-favorites/{id}/add
DELETE /api/v1/food-favorites/{id}
POST   /api/v1/daily-burn                   set/azzera bruciato manuale
GET    /api/v1/nutrition-plan               piano attivo dell'utente
GET    /api/v1/targets                      target kcal e macro correnti
```

**Regola calorica confermata:** il calcolo è **sempre in grammi**; `qty`/`unit` servono solo alla visualizzazione. `grams` è la fonte di verità. Target del giorno = target base + bruciate del giorno.

**Definizione di fatto F5.** Test unitari su `FoodUnit` e `CalorieCalculator` verdi; `/api/v1/diary` restituisce totali coerenti; un trainer crea un piano alimentare e l'iscritto lo vede via API.

---

### F6 — Layer AI

**Obiettivo.** Un solo punto attraverso cui passa ogni token, misurato e limitato.

#### F6.1 — Contratto e DTO

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
    public AiFeature $feature,      // enum: FoodText|FoodPhoto|WorkoutKcal|DailyAdvice|PdfImport
) {}
```

**DTO** in `app/Services/Ai/Data/`: `FoodEstimate` (`items: FoodItem[]`, `totals`, `confidence: float`), `FoodItem` (`name, qty, unit, grams, kcal, protein, carbs, fat`), `ParsedWorkoutPlan` (`name, notes, exercises: ParsedWorkoutExercise[]`, `confidence`), `ParsedWorkoutExercise` (`name, sets, reps, restSec, targetWeight, notes`), `WorkoutAiContext`.

**`app/Services/Ai/AiManager.php`**
```php
public function __construct(private Container $app, private TenantContext $tenants) {}
public function driver(?string $name = null): AiProvider;   // per-tenant > config('ai.default')
public function for(AiFeature $feature): AiProvider;
```

**`config/ai.php`**
```php
return [
    'default'   => env('AI_DRIVER', 'anthropic'),
    'providers' => [
        'anthropic' => ['key' => env('ANTHROPIC_API_KEY')],
        'openai'    => ['key' => env('OPENAI_API_KEY')],
    ],
    'models' => [
        'anthropic' => [
            'food_text'    => env('AI_MODEL_FOOD_TEXT',   'claude-haiku-4-5'),
            'food_photo'   => env('AI_MODEL_FOOD_PHOTO',  'claude-sonnet-5'),
            'workout_kcal' => env('AI_MODEL_WORKOUT_KCAL','claude-haiku-4-5'),
            'daily_advice' => env('AI_MODEL_ADVICE',      'claude-haiku-4-5'),
            'pdf_import'   => env('AI_MODEL_PDF_IMPORT',  'claude-opus-5'),
        ],
    ],
    // costo in millesimi di centesimo per token, per il calcolo in ai_usage_logs
    'pricing' => [
        'claude-opus-5'    => ['in' => 500,  'out' => 2500],
        'claude-sonnet-5'  => ['in' => 300,  'out' => 1500],
        'claude-haiku-4-5' => ['in' => 100,  'out' => 500],
    ],
    'quota' => ['default_monthly_tokens' => env('AI_DEFAULT_MONTHLY_TOKENS', 2_000_000)],
];
```

#### F6.2 — `app/Services/Ai/AnthropicProvider.php`

Client: `new \Anthropic\Client(apiKey: config('ai.providers.anthropic.key'))`.
⚠️ **L'SDK PHP usa named argument in camelCase** (`maxTokens`, non `max_tokens`); le chiavi *annidate* mantengono invece la forma documentata caso per caso — copiare la forma esatta dagli esempi, senza convertire in blocco.

**Cibo da testo** — structured output, niente parsing fragile:
```php
$message = $this->client->messages->create(
    model: config('ai.models.anthropic.food_text'),
    maxTokens: 2048,
    system: [['type' => 'text', 'text' => self::FOOD_SYSTEM_PROMPT,
              'cacheControl' => ['type' => 'ephemeral']]],
    messages: [['role' => 'user', 'content' => $text]],
    outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::FOOD_SCHEMA]],
);
```
> **Perché `cacheControl` sul system prompt.** Il prompt del cibo è lungo (regole di conversione food-aware) e identico per ogni chiamata di ogni utente di ogni palestra. È esattamente il caso d'uso del prompt caching: il prefisso stabile si paga una volta e poi si legge a ~0.1×. **Vincolo:** il prefisso deve superare il minimo cacheabile del modello — **1024 token** per Sonnet 5 e Haiku 4.5, **512** per Opus 5. Sotto quella soglia non viene cachato e non c'è alcun errore: si verifica leggendo `usage.cacheReadInputTokens` sulla seconda chiamata. Corollario: **niente data corrente, niente id utente, niente nome palestra nel system prompt** — invaliderebbero il prefisso a ogni richiesta.

**Cibo da foto** — content block immagine base64 + stesso schema. Ridimensionare lato server a max 1568px sul lato lungo prima dell'invio: oltre non serve per un piatto e i token immagine crescono.

**Consiglio del giorno** — `thinking: ['type' => 'adaptive']` **non** serve qui (output breve); usare Haiku senza thinking.

**Schema cibo (`self::FOOD_SCHEMA`)** — `items[]` con `{name, qty, unit, grams, kcal, protein, carbs, fat}` + `totals` + `confidence`, `additionalProperties: false`, tutti i campi in `required`.
> **`grams` è food-aware**: il prompt impone che 1 cucchiaio di olio = 14 g e non 15 (la tabella generica di `FoodUnit` resta il fallback deterministico solo per l'inserimento manuale). Il calcolo calorico è sempre sui grammi.

**Gestione errori:** catturare `Anthropic\Core\Exceptions\RateLimitException` (→ 503 con `Retry-After`), `APIStatusException` (→ 502 `ai_unavailable`), `APIConnectionException` (→ 502). **Mai far uscire un'eccezione grezza dell'SDK verso l'API pubblica.**
**Rifiuti:** controllare `$message->stopReason === 'refusal'` **prima** di leggere `$message->content` — leggere `content[0]` incondizionatamente si rompe su un rifiuto.

#### F6.4 — Metering

**`ai_usage_logs`** — `tenant_id`, `user_id`, `provider`, `model`, `feature`, `input_tokens`, `output_tokens`, `cache_read_tokens`, `cost_millicents`, `duration_ms`, `success` bool, `error_code` null, `created_at`. INDEX(`tenant_id`,`created_at`), INDEX(`tenant_id`,`feature`).

**`app/Services/Ai/AiUsageRecorder.php`**
```php
public function record(AiCallContext $ctx, string $provider, string $model,
                       int $inputTokens, int $outputTokens, int $cacheReadTokens,
                       int $durationMs, bool $success, ?string $errorCode = null): AiUsageLog;
public function costMillicents(string $model, int $in, int $out): int;
```

#### F6.5 — Quote
**`app/Services/Ai/Quota/TenantAiQuota.php`**
```php
public function assertWithinQuota(Tenant $tenant): void;   // throws AiQuotaExceededException
public function remaining(Tenant $tenant): ?int;           // null = illimitato
```
`AiQuotaExceededException` → HTTP **429** con `{"error":"ai_quota_exceeded","resets_at":"..."}`.
> **Perché 429 e non 402.** Dal punto di vista dell'app è un limite di frequenza sulla risorsa AI, non un problema di pagamento dell'utente finale: l'iscritto non ha una carta di credito nel nostro sistema. Il 402 sarebbe corretto verso la palestra, ma quel canale è il pannello, non l'API.

#### F6.6 — API v1 AI + test
```
POST /api/v1/ai/food/text     {text, meal?}   -> voci create
POST /api/v1/ai/food/photo    multipart       -> voci create
GET  /api/v1/ai/advice                        -> consiglio del giorno (cache + hash contesto)
```
> **Consiglio del giorno, meccanismo confermato:** cache in `ai_advices` per (user, data, kind) con `context_hash` = md5 del contesto. **La data fa parte del contesto**, quindi l'hash cambia a mezzanotte (rigenerazione garantita) e a ogni pasto/allenamento — senza alcun cron dedicato. È una soluzione elegante e va mantenuta.

**Test:** `tests/Feature/Ai/` con un `FakeAiProvider` registrato nel container. **Nessun test tocca la rete.** Il test di quota verifica il 429; il test di metering verifica che un log venga scritto anche in caso di errore.

**Definizione di fatto F6.** Con `AI_DRIVER=anthropic` e chiave valida, una foto di un piatto produce voci di diario sensate; `ai_usage_logs` si popola; superata la quota l'API risponde 429; l'intera suite gira offline col fake.

---

### F7 — Import schede da PDF

**Obiettivo.** Il gym_admin carica il PDF della scheda cartacea; ne esce una bozza editabile.

- **F7.1** `workout_plan_imports` — `tenant_id`, `uploaded_by`, `member_id` null, `media_id`, `status` (`queued`\|`processing`\|`review`\|`done`\|`failed`), `parsed_payload` json, `error`, `workout_plan_id` null.
- **F7.2** `app/Jobs/ParseWorkoutPdf.php` — `handle(AiManager $ai, ExerciseMatcher $matcher): void`. Invia il PDF come **document block base64** (`application/pdf`) con `outputConfig.format` sullo schema `ParsedWorkoutPlan`. Limiti da rispettare: **32 MB per richiesta, 600 pagine** (100 per i modelli a contesto 200K — quindi **non** usare Haiku 4.5 qui). Fallback: se il PDF è una scansione senza testo e il modello fallisce, `smalot/pdfparser` non aiuta (non fa OCR) → si passa comunque a Claude come **immagini** pagina per pagina.
- **F7.3** `app/Services/Training/ExerciseMatcher.php` — `match(string $name, ?int $tenantId): Exercise` — normalizza (lowercase, accenti, sinonimi), cerca in libreria globale poi tenant, altrimenti crea custom con `is_custom = true`. *Perché un servizio dedicato:* «Panca piana», «panca piana bilanciere» e «Bench press» devono finire sullo stesso esercizio, altrimenti la libreria di ogni palestra degenera in poche settimane.
- **F7.4** Pagina Filament di revisione: tabella editabile degli esercizi riconosciuti, con il PDF sorgente affiancato e il `confidence` per riga. **Pubblicazione solo esplicita** — nessun import va in produzione senza che un umano lo abbia guardato.
- **F7.5** Test con 3 PDF campione in `tests/Fixtures/pdf/` (uno testuale pulito, uno tabellare, uno scansionato).

**Definizione di fatto F7.** Un PDF reale di scheda produce una bozza con ≥80% degli esercizi correttamente riconosciuti, revisionabile e pubblicabile.

---

### F8 — Chat trainer ↔ iscritto

- **F8.1** `conversations` (`tenant_id`, `trainer_id`, `member_id`, `last_message_at`, UNIQUE(`trainer_id`,`member_id`)) e `messages` (`conversation_id`, `sender_id`, `body`, `read_at`, `media_id` null). INDEX(`conversation_id`,`created_at`).
- **F8.2** `app/Events/MessageSent.php implements ShouldBroadcast` → canale privato `conversation.{id}`. `routes/channels.php` autorizza **solo** i due partecipanti, verificando anche il tenant.
- **F8.3** `app/Filament/Gym/Pages/Chat.php` — lista conversazioni + thread, Livewire con polling di riserva.
- **F8.4** API: `GET /api/v1/conversations`, `GET /api/v1/conversations/{id}/messages?before=`, `POST /api/v1/conversations/{id}/messages`, `POST /api/v1/conversations/{id}/read`. Il client app usa il socket quando disponibile e **ricade sul polling a 15s** se il socket non si apre — su rete mobile capita e una chat che «non arriva» distrugge la fiducia nel prodotto.
- **F8.5** `device_tokens` (`user_id`, `token`, `platform`, `last_used_at`). L'invio vero delle push è in F12.6.

---

### F9 / F10 — App Flutter

**Struttura di cartelle (`trainingfe/lib/`)**
```
main.dart                    # solo runApp(bootstrap())
app/
  bootstrap.dart             # DI, config, error handling, flavor
  app_config.dart            # baseUrl, flavor, feature flag
  router.dart
core/
  api/                       # dio client, interceptor token, mappatura errori
  storage/                   # secure storage token, cache branding
  theme/                     # ThemeData costruito dal branding del tenant
  widgets/
features/
  onboarding/  auth/  dashboard/  diary/  workout/  nutrition_plan/
  progress/  chat/  profile/
```
Ogni feature: `data/` (dto + repository), `domain/` (entità + use case), `presentation/` (schermate + state).

- **F9.4 — Il pezzo che rende reale il white-label.** All'avvio: se esiste un branding in cache → applicalo subito; poi rinfrescalo in background. Se non esiste → schermata «Inserisci il codice della tua palestra» → `GET /api/v1/branding/lookup?code=` → salva → `ThemeData` costruito da `colors.primary/secondary/accent`, logo nell'app bar e nello splash. **Nessun colore hardcodato fuori da `core/theme/`**: è la regola che tiene in piedi ADR-02.
- **F9.5** Token in `flutter_secure_storage`; interceptor che su 401 pulisce e rimanda al login; su 403 `tenant_inactive` mostra un messaggio dedicato.
- **F10.2 — Diario.** È la schermata più usata: tre modi di inserire (AI testo, AI foto, manuale) + preferiti, select unità di misura, ricalcolo qty→grammi in modifica, totali in **rosso** se si sfora il target.
- **F10.3 — Player.** Log serie, timer di riposo, foto, e a fine sessione stima kcal correggibile a mano.

---

### F11 — Sonno (opzionale)

Porta di `HealthIngestController` con un cambiamento sostanziale: il token di ingest diventa **per-utente** (`users.health_ingest_token`), non globale. `POST /api/v1/health/ingest` fuori dal gruppo `auth:sanctum`, autenticato dal solo token, rate-limited.
**Regola confermata:** dall'orologio si prende **solo il sonno**. Le calorie bruciate restano manuali o stimate — la sincronizzazione del watch è troppo in ritardo per essere usata in un calcolo che l'utente guarda in tempo reale.
Timestamp UTC → `Carbon::parse($utc)->setTimezone(config('app.timezone'))` **esplicito** (vedi §14).

---

### F12 — Hardening e staging

- **F12.2** Rate limit: `auth` 5/min, `ai/*` 20/ora per utente **e** quota tenant, ingest 60/ora. `audit_logs` per: impersonazione, cambio ruolo, sospensione tenant, pubblicazione scheda, eliminazione iscritto.
- **F12.3** Eseguire `/security-review` sul branch e correggere tutto ciò che emerge prima del deploy.
- **F12.4** `docker-compose.yml`: php-fpm 8.3+, nginx, MariaDB 11.4, Redis, Horizon, Reverb. Migrazione dati dev → staging con `migrate --force`.
- **F12.5** Runbook di deploy scritto in `codebase_reference.md`.

---

## 9. Schema DB di destinazione (riepilogo)

**Tabelle di piattaforma (senza `tenant_id`):** `tenants`, `users` (con `tenant_id` nullable per il super admin), `roles`, `permissions`, `model_has_roles`, `audit_logs`, più le tabelle di framework (`migrations`, `sessions`, `cache`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`, `media`).

**Tabelle tenant-owned (con `tenant_id` NOT NULL + trait `BelongsToTenant`):**
`profiles`, `trainer_member`, `workout_plans`, `plan_exercises`, `workout_sessions`, `session_sets`, `body_metrics`, `daily_burns`, `food_entries`, `food_favorites`, `nutrition_plans`, `nutrition_plan_meals`, `nutrition_plan_items`, `conversations`, `messages`, `device_tokens`, `ai_usage_logs`, `ai_advices`, `workout_plan_imports`, `health_samples`.

**Eccezione motivata:** `exercises` ha `tenant_id` **nullable** (null = libreria globale) e usa il trait `BelongsToTenantOrGlobal`. È l'unica eccezione ammessa; ogni altra richiede una riga in §15 e l'aggiornamento di `TenantIsolationTest`.

---

## 10. Config / env

| Chiave | Default | Significato |
|---|---|---|
| `APP_TIMEZONE` | `Europe/Rome` | ⚠️ obbligatoria, vedi §14 |
| `APP_LOCALE` | `it` | |
| `AI_DRIVER` | `anthropic` | driver di default, override per-tenant su `tenants.ai_driver` |
| `ANTHROPIC_API_KEY` | — | **solo in `.env`** |
| `OPENAI_API_KEY` | — | driver alternativo |
| `AI_MODEL_FOOD_TEXT` | `claude-haiku-4-5` | |
| `AI_MODEL_FOOD_PHOTO` | `claude-sonnet-5` | |
| `AI_MODEL_WORKOUT_KCAL` | `claude-haiku-4-5` | |
| `AI_MODEL_ADVICE` | `claude-haiku-4-5` | |
| `AI_MODEL_PDF_IMPORT` | `claude-opus-5` | ⚠️ non usare un modello a contesto 200K (limite 100 pagine PDF) |
| `AI_DEFAULT_MONTHLY_TOKENS` | `2000000` | quota per i tenant senza cap esplicito |
| `REVERB_APP_KEY` / `_SECRET` / `_ID` | — | chat |
| `SEED_SUPERADMIN_EMAIL` / `_PASSWORD` | — | usate solo dal seeder |

---

## 11. Catalogo dei test (da mantenere aggiornato a ogni fase)

| Test | File | Cosa dimostra | Fase |
|---|---|---|---|
| `TenantIsolationTest` | `tests/Feature/Tenancy/` | Nessun modello tenant-owned può sfuggire allo scope; i dati di due palestre non si vedono | F1 |
| `AuthApiTest` | `tests/Feature/Api/V1/` | Registrazione con `join_code`, login, 403 su tenant sospeso, revoca device | F1 |
| `RoleScopingTest` | `tests/Feature/Authorization/` | Il trainer vede solo i propri assegnati; 403 sull'accesso diretto | F3 |
| `WorkoutCalorieServiceTest` | `tests/Unit/Training/` | Manuale > stima; idempotenza di `estimateAndStore` | F4 |
| `FoodUnitTest` | `tests/Unit/Nutrition/` | Ogni fattore di conversione; `null` su unità sconosciuta | F5 |
| `CalorieCalculatorTest` | `tests/Unit/Nutrition/` | BMR/TDEE/target/macro su casi noti, entrambi i sessi | F5 |
| `DiaryApiTest` | `tests/Feature/Api/V1/` | Totali coerenti; target = base + bruciate | F5 |
| `AiProviderContractTest` | `tests/Feature/Ai/` | Il fake e il driver reale rispettano lo stesso contratto | F6 |
| `AiQuotaTest` | `tests/Feature/Ai/` | 429 a quota superata; log scritto anche in errore | F6 |
| `PdfImportTest` | `tests/Feature/Import/` | I 3 PDF campione producono bozze plausibili | F7 |
| `ChatAuthorizationTest` | `tests/Feature/Chat/` | Un terzo utente non può ascoltare il canale privato | F8 |

---

## 12. Regole non negoziabili

1. **Segreti solo in `.env`**, mai committati. `.env.example` elenca ogni chiave con valore fittizio.
2. **Ogni modello tenant-owned usa il trait.** Uscire dallo scope richiede `TenantContext::runWithoutTenant()` esplicito.
3. **Il tenant non si legge mai da input del client.** Si deriva dall'utente autenticato. L'unica eccezione è l'endpoint pubblico di branding, che espone solo dati di branding e risponde 404 in modo indistinguibile.
4. **`grams` è la fonte di verità** del calcolo calorico. `qty`/`unit` sono presentazione.
5. **Dal wearable si prende solo il sonno.** Le kcal bruciate sono manuali o stimate.
6. **Il valore manuale batte sempre la stima**, ovunque, e le aggregazioni passano da `kcalOf`/`dailyBurned`.
7. **Nessun file media ha URL pubblico.** Si serve da endpoint autenticato.
8. **Nessun import PDF va in produzione senza revisione umana.**
9. **Nessun test tocca la rete.** L'AI si testa col fake provider.
10. **`/api/v1` non si rompe.** Un cambiamento incompatibile apre `/api/v2`.

---

## 13. Trappole già note (con causa tecnica)

Ereditate dall'app storica — sono già costate tempo una volta.

- **Timezone.** Eloquent **non** converte il fuso in scrittura. Ogni timestamp proveniente dall'esterno va convertito esplicitamente: `Carbon::parse($utc)->setTimezone(config('app.timezone'))`. `APP_TIMEZONE=Europe/Rome` va in `.env` dal primo giorno.
- **Permessi sui file di storage.** File e directory creati da un processo **root** (CLI, seeder, job in container) nascono `700` e php-fpm non li legge → foto in 404. Il disco `local` di Laravel 11+ è `storage/app/private`. In staging: `chmod -R a+rX` dopo ogni operazione da CLI che crea file.
- **Unità di misura.** La conversione volume/casalinghe→grammi **dipende dal cibo**: per l'AI è food-aware, per l'inserimento manuale è la tabella generica `FoodUnit` (grammi poi ritoccabili a mano).

Nuove, specifiche di questo progetto:

- **`exercises` con `tenant_id` nullable.** Usare `BelongsToTenant` su questa tabella nasconde la libreria globale a tutti. Serve `BelongsToTenantOrGlobal` (`where tenant_id = X or tenant_id is null`).
- **Prompt caching e prefisso stabile.** Qualsiasi valore variabile nel system prompt (data, nome palestra, id utente) invalida la cache a ogni richiesta e la fa pagare a prezzo pieno, **senza alcun errore**. Il contesto variabile va nel messaggio utente, dopo il breakpoint. Verificare con `usage.cacheReadInputTokens`.
- **Soglia minima di cache.** 1024 token per Sonnet 5 e Haiku 4.5, 512 per Opus 5. Sotto soglia non si cacha e non lo dice.
- **PDF e finestra di contesto.** Il limite di 600 pagine scende a **100** sui modelli con contesto 200K (Haiku 4.5). Per l'import schede usare Opus 5 o Sonnet 5.
- **Rifiuti dell'API.** `stopReason === 'refusal'` arriva con HTTP 200: leggere `content[0]` senza controllare `stopReason` si rompe.
- **PHP in PATH.** `E:\coding\php83\php.exe` (8.3.21), non quello di XAMPP (8.2.12). Laravel 13 richiede `^8.3`.
- **Filament è alla v5, non alla v4.** Documentazione e pacchetti di terze parti per v3/v4 non sono compatibili.

---

## 14. Debito tecnico previsto / cosa NON esiste

Elencato in anticipo per evitare che qualcuno lo cerchi invano.

- **Fatturazione e pagamenti**: non esistono. `tenants.plan` è un'etichetta, non un abbonamento attivo. Da affrontare quando ci sarà il primo cliente pagante.
- **Notifiche push**: i token si registrano in F8.5, l'invio arriva solo in F12.6.
- **OCR per PDF scansionati**: `smalot/pdfparser` non fa OCR. Il fallback è mandare le pagine come immagini a Claude; se anche quello fallisce l'import va in `failed` e si inserisce a mano.
- **App per il trainer**: non esiste. Il trainer usa il pannello web.
- **Ipnogramma e sonno**: F11 è opzionale e potrebbe non entrare nella v1.
- **`tenants.settings` (json)**: ripostiglio. Nulla di ciò che va interrogato in una `WHERE` deve finirci — se serve interrogarlo, diventa una colonna.
- **Test end-to-end dell'app Flutter**: solo unit e widget test fino a F12.1.
- **MariaDB 10.4 in dev**: è EOL. Funziona per lo sviluppo, ma lo staging va su 11.4 e le differenze (JSON, CTE, `utf8mb4` collation) vanno verificate in F12.4 **prima** del primo deploy, non dopo.

---

## 15. Il perché delle scelte non ovvie (indice rapido)

| Scelta | Dove è motivata |
|---|---|
| Single DB + `tenant_id` invece di DB-per-tenant | ADR-01 |
| App unica temizzata invece di build per palestra | ADR-02 |
| Filament invece di una SPA custom | ADR-03 |
| API versionata dal giorno uno | ADR-04 |
| Layer AI astratto invece di chiamate dirette | ADR-05 |
| Claude come default invece di OpenAI | ADR-05 |
| Modelli diversi per task diversi | ADR-05 |
| Ruoli con `teams` invece di ruoli globali | ADR-06 |
| Riscrittura invece di port del codice storico | ADR-09 |
| Scope permissivo a contesto vuoto | F1.3 |
| Piano alimentare separato dal diario | F5.2 |
| `macros()` senza il parametro `$kg` morto | F5.3 |
| Quota AI → 429 e non 402 | F6.5 |
| `ExerciseMatcher` come servizio dedicato | F7.3 |
| Polling di riserva nella chat | F8.4 |
| Token di ingest per-utente invece che globale | F11 |
