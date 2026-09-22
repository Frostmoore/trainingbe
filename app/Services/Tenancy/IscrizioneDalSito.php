<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\PlanKind;
use App\Enums\TenantKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Mail\BenvenutoMail;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Chi si abbona dal sito — 20/09/2026.
 *
 * ── 📌 Perché esiste ───────────────────────────────────────────────────────
 *
 * Il committente: *«ci deve essere un modo per permettere alle palestre e ai
 * trainers di abbonarsi direttamente dal sito»*.
 *
 * ⛔ **Prima non c'era nessuna strada.** Il listino mostrava i prezzi e poi
 * finiva lì: una palestra che voleva comprare doveva scrivere una mail e
 * aspettare che qualcuno le creasse l'account a mano dal pannello god. La
 * pagina dei prezzi aveva perfino un pulsante «Iscriviti ora» che puntava a
 * `/registrati`, **una rotta mai esistita** (404).
 *
 * ── 🚨 Cosa fa, e in che ordine ────────────────────────────────────────────
 *
 * | # | Passo | Se manca |
 * |---|---|---|
 * | 1 | Lo **spazio**: tenant personale per un trainer, palestra per una palestra | — |
 * | 2 | I **ruoli** spatie dentro quello spazio | 🚨 `assignRole()` lancia: in modalità teams il ruolo si cerca nel tenant corrente |
 * | 3 | L'**utente**, dentro il contesto | `tenant_id` vuoto = super admin di fatto |
 * | 4 | Il **ruolo** e il **proprietario** | Senza `proprietario_id` nessuno «possiede» lo spazio che sta per pagare |
 * | 5 | Le due **dichiarazioni** (maggiore età, condizioni) con la loro data | L'art. 7(1) chiede di poterle dimostrare, e senza data non si dimostra niente |
 *
 * ⚠️ **Tutto in una transazione.** A metà resterebbe uno spazio senza nessuno
 * dentro: invisibile e irraggiungibile, e nessun errore da nessuna parte.
 *
 * ── 💡 Il pagamento NON sta qui ────────────────────────────────────────────
 *
 * Questo servizio fa nascere l'account e basta; il checkout lo apre
 * `PianiInVendita::apri()`, che è il posto in cui Stripe vive già. 🚨 Se il
 * pagamento non arriva, resta un account **senza piano**: chi entra nel
 * pannello trova «nessun piano attivo» e può ripartire da lì. ⛔ L'alternativa
 * — creare l'account solo dopo il pagamento — vorrebbe dire tenere una password
 * in sessione mentre si esce verso Stripe, ed è peggio.
 */
final class IscrizioneDalSito
{
    public function __construct(
        private readonly CreaTenantPersonale $creaPersonale,
        private readonly TenantContext $context,
    ) {}

    /**
     * 🚨 **Quali piani si comprano da soli.**
     *
     * Solo i piani **a canone** di trainer e palestre. ⛔ Fuori restano:
     * - il piano **individuale** (`plus`), che si attiva dentro l'app da chi ce
     *   l'ha già installata — qui non ci sarebbe niente da creare;
     * - i piani **su misura**, che hanno un minimo e una fattura a consumo: si
     *   concordano, e il sito dice «scrivici» invece di far comprare al buio;
     * - i piani **ritirati** (`is_public = false`), che esistono solo per chi
     *   ci sta già sopra.
     */
    public function pianoComprabile(string $codice): ?Plan
    {
        $piano = Plan::query()->pubblici()->where('code', $codice)->first();

        if ($piano === null || $piano->aConsumo() || $piano->eGratuito()) {
            return null;
        }

        return in_array($piano->kind, [PlanKind::Trainer, PlanKind::Gym], true) ? $piano : null;
    }

    /**
     * 🚨 **L'indirizzo dev'essere libero su TUTTA la piattaforma.**
     *
     * ⚠️ L'unicità dell'email è **per palestra** (due persone diverse in due
     * palestre diverse possono avere lo stesso indirizzo: è il caso di chi
     * iscrive il figlio col proprio indirizzo). Ma chi entra da un **pannello**
     * si autentica con email *o* nome utente su tutta la piattaforma
     * (`User::findByIdentifier()`), e con due righe uguali l'accesso
     * diventerebbe un'ambiguità risolta da un `first()`.
     *
     * 💡 Quindi qui la regola è più stretta di quella del database, e lo è
     * apposta: chi si abbona dal sito deve poter entrare nel pannello senza
     * sorprese.
     */
    public function emailGiaUsata(string $email): bool
    {
        return User::withoutGlobalScopes()
            ->where('email', mb_strtolower(trim($email)))
            ->exists();
    }

    public function __invoke(
        Plan $piano,
        string $nome,
        string $email,
        string $password,
        ?string $attivita = null,
    ): User {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($piano, $nome, $email, $password, $attivita): User {
            $utente = $piano->kind === PlanKind::Gym
                ? $this->apriLaPalestra((string) $attivita, $nome, $email, $password)
                : $this->apriLoSpazioDelTrainer($nome, $email, $password);

            /*
             * 🚨 **Le due dichiarazioni, con la loro data** — come in
             * `AuthController::register()`.
             *
             * ⚠️ `forceFill` e non assegnazione di massa: sono campi che nessuna
             * richiesta deve poter scrivere da sé. Un `age_confirmed_at`
             * fillable vorrebbe dire dichiararsi maggiorenne mandando un campo
             * in più, senza mai passare da una casella.
             */
            $utente->forceFill([
                'age_confirmed_at' => now(),
                'terms_accepted_at' => now(),
            ])->save();

            return $utente;
        });

        /*
         * 🆕 👋 **Il benvenuto** — 22/09/2026. Fuori dalla transazione: una
         * mail mandata dentro, e poi un rollback, saluterebbe qualcuno che non
         * esiste. E dentro un `try`, perche' l'iscrizione e' gia' andata bene.
         */
        try {
            Mail::to($utente->email)->send(new BenvenutoMail(
                $utente,
                $piano->kind === PlanKind::Gym ? BenvenutoMail::PALESTRA : BenvenutoMail::TRAINER,
            ));
        } catch (Throwable $e) {
            Log::error('Benvenuto: mail non partita.', [
                'utente' => $utente->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }

        return $utente;
    }

    /**
     * Un trainer indipendente: è lo **stesso** nascere di chi si registra
     * dall'app senza codice palestra, con un altro ruolo.
     *
     * 💡 Si riusa `CreaTenantPersonale` invece di rifare i quattro passi: è il
     * servizio che li conosce, e averne due copie vorrebbe dire che un giorno
     * una delle due dimenticherà un passo.
     */
    private function apriLoSpazioDelTrainer(string $nome, string $email, string $password): User
    {
        return ($this->creaPersonale)(
            $nome,
            $email,
            [
                'username' => $this->nomeUtenteLibero($email),
                'password' => $password,
                'locale' => 'it',
            ],
            UserRole::FreeTrainer,
        );
    }

    /**
     * Una palestra: tenant `gym`, i ruoli dentro, e chi si è iscritto ne è
     * l'amministratore **e** il proprietario.
     *
     * ⚠️ `status = Active` e non `Trial`: non esiste nessun periodo di prova, e
     * uno stato che dice una cosa falsa diventa pericoloso il giorno in cui
     * qualcuno scrive «chiudi le prove scadute».
     */
    private function apriLaPalestra(string $attivita, string $nome, string $email, string $password): User
    {
        $palestra = Tenant::create([
            'name' => $attivita,
            'slug' => $this->slugLibero($attivita),
            'kind' => TenantKind::Gym,
            'status' => TenantStatus::Active,
            'contact_email' => $email,
            'locale' => 'it',
        ]);

        return $this->context->runAs($palestra, function () use ($palestra, $nome, $email, $password): User {
            // ⚠️ Dentro il contesto: spatie gira in modalità teams e prende il
            // team da qui. Creati fuori, finirebbero nel team sbagliato.
            foreach (UserRole::tenantScoped() as $ruolo) {
                Role::firstOrCreate([
                    'name' => $ruolo->value,
                    'guard_name' => 'web',
                    'tenant_id' => $palestra->getKey(),
                ]);
            }

            // 🚨 Senza questo, `assignRole()` due righe più sotto fallisce con
            // «There is no role named …» pur essendo la riga già a database: la
            // cache dei permessi non sa dei ruoli appena creati.
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $utente = User::create([
                'tenant_id' => $palestra->getKey(),
                'name' => $nome,
                'email' => $email,
                'username' => $this->nomeUtenteLibero($email),
                'password' => $password,
                'locale' => 'it',
            ]);

            $utente->assignRole(UserRole::GymAdmin->value);

            // 🚨 `forceFill`: `proprietario_id` non è fillable apposta — una
            // colonna che dice chi paga non cambia per assegnazione di massa.
            $palestra->forceFill(['proprietario_id' => $utente->getKey()])->save();

            return $utente;
        });
    }

    /**
     * Uno slug libero a partire dal nome dell'attività.
     *
     * ⚠️ Due palestre possono chiamarsi uguale — «Fitness Club» ce n'è uno per
     * provincia — e lo slug è **unico**: senza il contatore in coda, la seconda
     * iscrizione morirebbe su un errore di database che chi la sta facendo non
     * può capire né risolvere.
     */
    private function slugLibero(string $attivita): string
    {
        $base = Str::slug($attivita) ?: 'palestra';
        $slug = $base;
        $n = 1;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    /**
     * Il nome utente, ricavato dall'indirizzo.
     *
     * ── 💡 Perché non lo si chiede ─────────────────────────────────────────
     *
     * Perché un campo in più in un modulo di iscrizione è gente che se ne va, e
     * perché qui il nome utente non serve a nessuno: chi si abbona dal sito
     * entra nel pannello **con l'email**. Esiste perché la colonna è
     * obbligatoria e unica su tutta la piattaforma.
     *
     * 🚨 Il formato deve rispettare la stessa regola della registrazione
     * dall'app (`^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$`): minuscole, niente `@`,
     * niente spazi. ⛔ Un nome utente con dentro una chiocciola verrebbe
     * scambiato per un'email al momento dell'accesso.
     */
    private function nomeUtenteLibero(string $email): string
    {
        $base = Str::of($email)
            ->before('@')
            ->lower()
            ->replaceMatches('/[^a-z0-9._-]/', '')
            ->trim('._-')
            ->toString();

        if (mb_strlen($base) < 3) {
            $base = 'utente'.Str::lower(Str::random(4));
        }

        $base = mb_substr($base, 0, 24);
        $nome = $base;
        $n = 1;

        while (User::withoutGlobalScopes()->where('username', $nome)->exists()) {
            $nome = $base.(++$n);
        }

        return $nome;
    }
}
