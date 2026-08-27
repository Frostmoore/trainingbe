<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use App\Support\Tempo\GiornoLocale;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * Un utente appartiene a una palestra — tranne il super admin, che ha
 * `tenant_id = null` ed e' l'unico caso legittimo.
 *
 * Usa BelongsToTenant come tutti gli altri modelli: il global scope non filtra
 * a contesto vuoto, quindi il pannello /god continua a vedere tutti. Escluderlo
 * qui avrebbe significato una regola in meno da rispettare, e le regole con
 * un'eccezione sono quelle che si dimenticano.
 */
#[Fillable([
    'tenant_id', 'name', 'email', 'username', 'password', 'phone',
    'avatar_path', 'locale', 'timezone', 'comune_id', 'is_active',
])]
// `is_super_admin` NON è fillable di proposito: si concede solo da seeder o da
// console, mai per assegnazione di massa da una richiesta HTTP. Un campo del
// genere in `$fillable` è una scalata di privilegi in attesa di un controller
// distratto che passi `$request->all()`.
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasMedia
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant;

    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use InteractsWithMedia;
    use Notifiable;
    use SoftDeletes;

    /**
     * Devono rispecchiare i `->default()` delle migration.
     *
     * 🚨 Non è ridondanza. I default del database si applicano all'`INSERT`,
     * **non all'oggetto in memoria**: senza questi, subito dopo `User::create()`
     * `is_active` vale `null`, e `canAccessPanel()` — che comincia proprio con
     * `if (! $this->is_active)` — respinge l'utente dal suo stesso pannello.
     *
     * Il guasto è invisibile a chi rilegge l'utente con `find()` o `fresh()`
     * prima di usarlo, ed è per questo che era passato inosservato: si
     * manifesta solo nel percorso reale, dove si usa l'oggetto appena creato.
     * Stessa trappola già pagata su `Tenant`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'it',
        'is_active' => true,
        'is_super_admin' => false,

        // 🆕 16/08 — stessa ragione delle tre righe qui sopra: senza, subito
        // dopo `User::create()` vale `null` e il consiglio automatico
        // risulterebbe spento per chi si e' appena iscritto.
        'consiglio_automatico' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // N22.2 — quando ha dichiarato l'albo, non se.
            'albo_dichiarato_il' => 'datetime',
            'last_login_at' => 'datetime',

            /*
             * FASE 11.3 — quando ha portato i suoi allenamenti sul telefono.
             * 🚨 Volutamente **fuori da `$fillable`**: lo scrive il server dopo
             * aver verificato i conteggi, e una richiesta che se lo scrivesse da
             * sola sarebbe un modo per farsi cancellare i propri dati.
             */
            'workouts_migrated_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',

            /*
             * G8 — se questa persona ha una password che conosce.
             *
             * Chi entra con Google o Apple ne riceve comunque una, casuale:
             * guardare la colonna `password` non dice niente. Serve a non
             * proporgli un modulo che chiede «la password attuale» — che non
             * ha mai saputo.
             */
            'password_is_set' => 'boolean',

            /*
             * 🆕 G2 — lo stesso tetto, in **chiamate** (D6/D7).
             *
             * ⚠️ Anche questi fuori da `$fillable`, e per la stessa ragione: una
             * concessione non si assegna in massa da una richiesta HTTP.
             *
             * 🚨 `ai_monthly_photo_call_cap` e' un **sotto-limite** di
             * `ai_monthly_call_cap`, non un budget a parte: una foto consuma
             * entrambi.
             */
            'ai_monthly_call_cap' => 'integer',
            'ai_monthly_photo_call_cap' => 'integer',

            /*
             * 🆕 15/08/2026 — l'AI accesa o spenta per **una persona sola**.
             *
             * ⚠️ **Tre valori, non due**: `null` = decide il piano, `true` =
             * accesa, `false` = spenta anche se il piano ce l'ha. Il cast a
             * `boolean` lascia passare `null` — ed e' esattamente quello che
             * serve, perche' `PianoAttivo::haLaAi()` lo legge con `=== true` e
             * `=== false`.
             *
             * 🚨 Fuori da `$fillable` come i due tetti qui sopra: e' una
             * concessione, e una concessione non si assegna in massa da una
             * richiesta HTTP.
             */
            'ai_enabled_override' => 'boolean',

            /*
             * S9 — i consensi.
             *
             * 🚨 **Sono date, non booleani.** L'art. 7(1) chiede di poter
             * *dimostrare* che il consenso e' stato dato, e un `true` senza data
             * non dimostra niente: non dice quando, quindi non dice nemmeno a
             * quale versione dell'informativa si riferisse.
             *
             * ⚠️ `null` significa **non dato**, che sul piano degli effetti e'
             * la stessa cosa di «negato». Un booleano `false` di serie avrebbe
             * confuso «non ha ancora scelto» con «ha detto di no».
             */
            'age_confirmed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'consensi_chiesti_il' => 'datetime',
            'health_consent_at' => 'datetime',
            'ai_consent_at' => 'datetime',
            'ai_disclaimer_at' => 'datetime',

            /*
             * 🆕 16/08/2026 — il sonno nel contesto del consiglio del giorno.
             *
             * 🚨 **Separato da `ai_consent_at` di proposito.** Chi accetta che
             * una frase sul pranzo vada a un modello non ha con cio' accettato
             * che ci vada quanto e come dorme: sono due cose di intimita'
             * diversa, e l'art. 7 vieta il consenso a pacchetto.
             *
             * 💡 E' una **data** e non un booleano perche' l'art. 7(1) chiede di
             * poter *dimostrare* il consenso: un `true` non dice quando, quindi
             * non dice sotto quale informativa.
             */
            'sleep_ai_consent_at' => 'datetime',

            // 💡 Preferenza, non consenso: `true` di serie. Vedi la migrazione
            // `2026_08_16_100000`.
            'consiglio_automatico' => 'boolean',
        ];
    }

    // ───────────────────────── il giorno di chi guarda (A3) ─────────────────────────

    /**
     * Il fuso orario di questa persona.
     *
     * 🚨 **La catena e' `users.timezone` → `tenants.timezone` → `Europe/Rome`**, e
     * ogni anello ha un senso proprio: il primo serve a chi viaggia o vive
     * altrove, il secondo e' il caso normale di una palestra, il terzo evita
     * che un dato mancante faccia ricadere tutto su UTC — che e' l'unico valore
     * **sicuramente sbagliato** per un prodotto italiano.
     *
     * ⚠️ `null` non e' un buco: significa «usa quello della palestra».
     */
    public function fusoOrario(): string
    {
        return $this->timezone
            ?? $this->tenant?->timezone
            ?? config('app.display_timezone', 'Europe/Rome');
    }

    /**
     * **Oggi** per questa persona — il punto di partenza di ogni lettura datata.
     *
     * 🚨 **E' il metodo che chiude il difetto A3.** `Carbon::today()` dava la
     * mezzanotte **UTC**: alle 00:22 di Roma rispondeva ancora con il giorno
     * prima, e il diario si apriva sul giorno sbagliato — la cena registrata
     * tardi finiva in ieri.
     *
     * 💡 Restituisce un {@see GiornoLocale} e non un `Carbon` proprio perche' un
     * giorno e' **etichetta e finestra insieme**: chiedere l'una o l'altra sono
     * due metodi diversi, e confonderle non e' piu' possibile.
     */
    public function giornoDiOggi(?Carbon $adesso = null): GiornoLocale
    {
        return GiornoLocale::oggi($this, $adesso);
    }

    /** Il giorno chiesto da questa persona, da un'etichetta `Y-m-d` o da un istante. */
    public function giorno(string|Carbon $quando): GiornoLocale
    {
        return GiornoLocale::perUtente($this, $quando);
    }

    /**
     * L'inizio di **oggi** per questa persona, espresso in UTC.
     *
     * ⚠️ **Il valore torna in UTC**, ed e' voluto: serve a confrontarlo con i
     * timestamp del database, che restano in UTC e devono restarci. Quello che
     * cambia e' **dove si taglia la giornata**, non come si conservano gli
     * istanti.
     */
    public function inizioDiOggi(?Carbon $adesso = null): Carbon
    {
        return $this->giornoDiOggi($adesso)->inizio();
    }

    /**
     * La data di oggi per questa persona, come `Y-m-d`.
     *
     * 💡 Serve dove il giorno e' una **etichetta** e non un istante: la chiave
     * della cache del consiglio, la colonna `date` del diario, il
     * raggruppamento dello storico.
     */
    public function dataDiOggi(?Carbon $adesso = null): string
    {
        return $this->giornoDiOggi($adesso)->etichetta;
    }

    // ───────────────────────── consensi (S9) ─────────────────────────

    /**
     * Ha dichiarato di essere maggiorenne.
     *
     * ⚠️ **Dichiarato, non verificato**, e la differenza e' scritta apposta nel
     * nome del metodo: dopo S5 il server non sa quanti anni ha la persona,
     * perche' la data di nascita sta sul telefono.
     */
    public function haDichiaratoMaggioreEta(): bool
    {
        return $this->age_confirmed_at !== null;
    }

    /**
     * Puo' mandare il proprio diario a un modello AI.
     *
     * 🚨 **Serve il consenso specifico, non quello ai dati sanitari.** Sono due
     * decisioni diverse: chi accetta che i propri dati stiano **da noi** non ha
     * per questo accettato che vadano **ad Anthropic**, negli Stati Uniti.
     */
    public function puoUsareAi(): bool
    {
        return $this->ai_consent_at !== null;
    }

    /**
     * Registra o revoca un consenso.
     *
     * 🚨 **Revocabile con la stessa facilita' con cui e' stato dato**
     * (art. 7(3)): passare `false` azzera la data, e non c'e' nessun percorso
     * piu' lungo o piu' difficile per togliere che per mettere. Un consenso che
     * si da' con un tocco e si toglie scrivendo un'email **non e' liberamente
     * revocabile**, e quindi non e' consenso valido.
     */
    public function registraConsenso(string $colonna, bool $dato): void
    {
        $this->forceFill([$colonna => $dato ? now() : null])->save();
    }

    // ───────────────────────── identificativi ─────────────────────────

    /**
     * Nome utente sempre in minuscolo.
     *
     * Chi lo digita non pensa alle maiuscole, e un vincolo `UNIQUE` che
     * distingue `Marco` da `marco` lascerebbe registrare due account che
     * all'occhio sono lo stesso — il modo classico per farsi passare per un
     * altro. Normalizzare qui vale per ogni percorso: pannello, API, seeder.
     */
    protected function username(): Attribute
    {
        return Attribute::set(
            fn (?string $value): ?string => $value === null || trim($value) === ''
                ? null
                : mb_strtolower(trim($value)),
        );
    }

    protected function email(): Attribute
    {
        return Attribute::set(
            fn (string $value): string => mb_strtolower(trim($value)),
        );
    }

    /**
     * Trova l'utente da un identificativo, email o nome utente che sia.
     *
     * 🚨 Risolve **noi**, invece di lasciar fare a `attempt()`, per un motivo
     * preciso: l'email è unica **per palestra**, quindi lo stesso indirizzo può
     * appartenere a due persone in due palestre. Al login dei pannelli non c'è
     * un `join_code` che disambigui, e il provider di Laravel prenderebbe la
     * prima riga che capita — in silenzio, e magari quella di un iscritto, che
     * nel pannello non entra: l'amministratore vedrebbe «credenziali non
     * valide» pur avendole giuste.
     *
     * Fra i candidati si preferisce quindi chi può davvero entrare in un
     * pannello. Il nome utente non ha questo problema, essendo unico ovunque.
     *
     * @param  bool  $perPannello  se `true`, preferisce chi ha accesso a un pannello
     */
    public static function findByIdentifier(string $identifier, bool $perPannello = false): ?self
    {
        $identifier = mb_strtolower(trim($identifier));

        $candidati = static::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('email', $identifier)->orWhere('username', $identifier))
            ->get();

        if ($candidati->count() <= 1) {
            return $candidati->first();
        }

        if ($perPannello) {
            $conPannello = $candidati->first(
                fn (self $u): bool => $u->isSuperAdmin() || $u->isGymAdmin() || $u->isTrainer(),
            );

            if ($conPannello !== null) {
                return $conPannello;
            }
        }

        return $candidati->first();
    }

    // ───────────────────────── pannelli ─────────────────────────

    /**
     * Chi entra in quale pannello Filament.
     *
     * 🚨 **E' il cancello vero**: lo chiama Filament a ogni richiesta.
     * `UserRole::canAccessAnyPanel()` e' un riepilogo leggibile e **non** viene
     * invocato da nessuno — i due sono tenuti allineati da `PanelAccessTest`,
     * che li confronta ruolo per ruolo.
     *
     * Le regole, in ordine di severita':
     *  - `god`   → solo il super admin;
     *  - `admin` → gym_admin, trainer e **trainer indipendente** (F2.3), ma solo
     *              se l'utente e' attivo E il suo tenant lo e' (un abbonamento
     *              scaduto chiude anche il pannello, non solo l'app);
     *  - gli ISCRITTI non entrano MAI in un pannello: usano solo l'app. E' un
     *    `false` esplicito e non una conseguenza dei ruoli, cosi' aggiungere un
     *    permesso per sbaglio non gli apre una porta.
     *
     * ── ⚠️ Il trainer indipendente entra, e per ora non vede NIENTE ────────
     *
     * E' voluto, non un lavoro lasciato a meta'. Ogni scoping del pannello
     * palestra (`ScopedToTrainer`, `WorkoutPlanResource`, `NutritionPlanResource`,
     * `GymOverview`) e' costruito sulla coppia gym_admin / trainer e ha, per
     * chiunque non sia nessuno dei due, il ramo `return $query->whereRaw('1 = 0')`.
     * Un ruolo che il pannello non conosce **fallisce chiuso**.
     *
     * 💡 Quindi la porta si apre qui in F2, e **F5.3 arreda la stanza** — i
     * modelli di scheda e piano che un trainer indipendente compone per i suoi.
     * L'ordine e' questo e non il contrario perche' aprire la porta e' una
     * decisione sui ruoli, mentre cosa ci sia dentro e' una decisione di
     * prodotto: mescolarle vorrebbe dire riaprire `canAccessPanel()` in F5 per
     * una ragione che non c'entra con i permessi.
     *
     * ⚠️ **E nessuno resta chiuso fuori nel frattempo**: al 13/08/2026 non
     * esiste ancora nessuna strada che crei un `FreeTrainer` (arriva in F6).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'god' => $this->isSuperAdmin(),
            'admin' => ($this->isGymAdmin() || $this->isTrainer() || $this->isFreeTrainer())
                && $this->tenant?->isActive() === true,
            default => false,
        };
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * La citta' dichiarata — M1.2. `null` finche' non la si sceglie, e resta
     * legittimamente `null` per sempre se non la si vuole dire.
     *
     * ⚠️ **Non e' `BelongsToTenant`**: i comuni sono un dato pubblico condiviso
     * da tutte le palestre, non una tabella per tenant.
     */
    public function comune(): BelongsTo
    {
        return $this->belongsTo(Comune::class);
    }

    /**
     * Gli iscritti seguiti da questo trainer.
     *
     * ── 🚨 Perché toglie il `TenantScope` — F6 della Parte B ────────────────
     *
     * Dentro una palestra trainer e iscritto stanno nello **stesso** tenant, e
     * lo scope non dava fastidio. Con i trainer indipendenti non è più vero: il
     * trainer ha il **suo** tenant personale e ogni suo utente ha il **proprio**
     * (F1). Lo scope confrontava `users.tenant_id` con il tenant del contesto —
     * cioè quello del trainer — e la relazione tornava **vuota**.
     *
     * ⚠️ Il difetto si vedeva così: un trainer indipendente invitava una
     * persona, la persona si iscriveva davvero, il legame veniva scritto, e
     * «i miei utenti» restava **vuoto**. Nessun errore da nessuna parte.
     *
     * ── 💡 Perché toglierlo non allarga niente ─────────────────────────────
     *
     * 🚨 **L'autorizzazione qui non è il contesto: è la riga di legame.**
     * `trainer_member` esiste solo perché qualcuno l'ha creata passando dai
     * flussi di assegnazione, che sono scopati; e `wherePivot('tenant_id')`
     * rimette lo stesso vincolo in modo **esplicito** invece che ambientale.
     *
     * ⚠️ Per una palestra il risultato è identico a prima — il pivot ha il
     * `tenant_id` della palestra, che è anche quello dei suoi utenti. Cambia
     * solo il **perché** la riga è visibile: non più «siamo nello stesso
     * tenant», ma «esiste un legame che ti appartiene».
     */
    public function assignedMembers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'trainer_member', 'trainer_id', 'member_id')
            ->withoutGlobalScopes([TenantScope::class])
            ->wherePivot('tenant_id', $this->tenant_id)
            ->withPivot(['tenant_id', 'assigned_at', 'assigned_by', 'disattivato_il'])
            ->withTimestamps();
    }

    /** Gli account esterni collegati — C17/G8. */
    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    /** Le schede assegnate a questa persona (non i modelli della palestra). */
    public function workoutPlans(): HasMany
    {
        return $this->hasMany(WorkoutPlan::class, 'member_id');
    }

    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    public function nutritionPlans(): HasMany
    {
        return $this->hasMany(NutritionPlan::class, 'member_id');
    }

    /**
     * I trainer che seguono questo iscritto.
     *
     * 🚨 **Qui NON si filtra sul `tenant_id` del pivot**, al contrario di
     * `assignedMembers()`, e la differenza è la stessa asimmetria di F6.1: il
     * legame appartiene al **trainer**, quindi il pivot porta il tenant *suo*.
     * Confrontarlo con quello di chi guarda — l'utente — non troverebbe mai
     * niente, ed è esattamente il caso del trainer indipendente.
     *
     * ⚠️ La chiave resta stretta: `member_id = io`. Una riga con il mio id ci
     * può essere solo se qualcuno mi ha assegnato a un trainer, e quelle strade
     * sono tutte scopate.
     */
    public function assignedTrainers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'trainer_member', 'member_id', 'trainer_id')
            ->withoutGlobalScopes([TenantScope::class])
            ->withPivot(['tenant_id', 'assigned_at', 'assigned_by', 'disattivato_il'])
            ->withTimestamps();
    }

    // ───────────────────────── ruoli ─────────────────────────

    /**
     * I controlli di ruolo di palestra passano tutti da qui.
     *
     * 🚨 **Valuta SEMPRE nella palestra dell'utente**, non in quella del
     * contesto corrente.
     *
     * In modalità teams `hasRole()` guarda il tenant impostato in quel momento.
     * Ma i ruoli di una persona esistono solo dentro la sua palestra, quindi
     * «è un trainer?» ha una risposta sola e non deve dipendere da uno stato
     * ambientale. Lasciandolo al contesto, la stessa chiamata rispondeva `true`
     * o `false` a seconda di dove veniva fatta — e il punto peggiore era
     * proprio il login, dove `ResolveTenant` non è ancora passato: un
     * amministratore veniva scambiato per un iscritto e rispedito fuori.
     *
     * ⚠️ NON accetta `UserRole::SuperAdmin`: quello non è un ruolo spatie ma
     * la colonna `is_super_admin`. Passarlo qui darebbe sempre `false` in
     * silenzio, quindi lancia invece di mentire.
     */
    public function hasAppRole(UserRole $role): bool
    {
        if ($role->isPlatformLevel()) {
            throw new InvalidArgumentException(
                "{$role->value} non e' un ruolo spatie: usare isSuperAdmin()."
            );
        }

        $tenant = $this->tenant;

        if ($tenant === null) {
            // Nessuna palestra: nessun ruolo di palestra. È il caso del super
            // admin, che non ne ha e non deve averne.
            return false;
        }

        return app(TenantContext::class)->runAs(
            $tenant,
            fn (): bool => $this->hasRole($role->value),
        );
    }

    /**
     * Vale su TUTTA la piattaforma, dentro e fuori da ogni palestra.
     *
     * Legge una colonna, non un ruolo spatie: i ruoli sono limitati al tenant
     * corrente e tornerebbero `false` appena il super admin entra in una
     * palestra — cioe' proprio quando gli serve (impersonazione, B2.3).
     * La motivazione completa e' nella migration `..._add_is_super_admin_...`.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function isGymAdmin(): bool
    {
        return $this->hasAppRole(UserRole::GymAdmin);
    }

    public function isTrainer(): bool
    {
        return $this->hasAppRole(UserRole::Trainer);
    }

    public function isMember(): bool
    {
        return $this->hasAppRole(UserRole::Member);
    }

    /**
     * Un trainer indipendente — F2.1.
     *
     * 🚨 **Non e' un `isTrainer()`, e non deve diventarlo.** La tentazione e'
     * far rispondere `true` anche a `isTrainer()` «perche' in fondo e' un
     * trainer»: sarebbe un errore serio. Tutto il pannello palestra e' costruito
     * sulla coppia `isGymAdmin()` / `isTrainer()`, e `isTrainer()` significa lì
     * una cosa precisa — *«membro dello staff di questa palestra, che vede gli
     * iscritti a lui assegnati»*. Un trainer indipendente non ha uno staff a cui
     * appartenere e non ha assegnati **di una palestra**.
     *
     * 💡 Tenendoli separati, ogni scoping esistente lo tratta come «nessuno dei
     * due» — e quel ramo, verificato in F2, restituisce sempre `1 = 0`. Cioe'
     * il pannello **fallisce chiuso** su un ruolo che non conosce, che e' il
     * comportamento che si vuole finche' F5.3 non gli dara' qualcosa da vedere.
     */
    public function isFreeTrainer(): bool
    {
        return $this->hasAppRole(UserRole::FreeTrainer);
    }

    /**
     * Chi puo' comporre un piano alimentare vero — N22.1.
     *
     * 🚨 **Non e' un `isTrainer()`, e non deve diventarlo** — stessa regola
     * di `isFreeTrainer()`, e per una ragione ancora piu' netta: sono due
     * mestieri diversi. Un trainer che rispondesse `true` qui potrebbe comporre
     * diete, che e' esattamente cio' che N19 ha tolto.
     *
     * ⚠️ Il ruolo e' **predisposto e non attivo**: nessun percorso reale lo
     * assegna. Serve perche' la struttura a grammi resti provata.
     */
    /**
     * Ha dichiarato l'iscrizione a un albo? — N22.2.
     *
     * 🚨 **E' un'autocertificazione, non una verifica.** Gli albi hanno
     * ricerche pubbliche e il giorno che servisse si potrebbe fare — ma **non
     * e' stata fatta**, e questo commento sta qui per non farlo dimenticare.
     *
     * 💡 La data conta piu' del booleano: se un giorno qualcuno
     * contestasse, la domanda sara' «cosa aveva dichiarato, e a che data».
     */
    public function haDichiaratoLAlbo(): bool
    {
        return $this->albo !== null
            && $this->albo_numero !== null
            && $this->albo_dichiarato_il !== null;
    }

    public function isNutrizionista(): bool
    {
        return $this->hasAppRole(UserRole::Nutrizionista)
            || $this->hasAppRole(UserRole::FreeNutrizionista);
    }

    /** Una persona iscritta da sola, senza codice palestra — F2.1. */
    public function isFreeUser(): bool
    {
        return $this->hasAppRole(UserRole::FreeUser);
    }

    // ───────────────────────── utilita' ─────────────────────────

    /*
     * ⚠️ Qui vivevano `bodyMetrics()` e `latestWeight()`. Cancellati in S5.4.
     *
     * 🚨 **Il server non sa piu' quanto pesa nessuno** (decisione D9-bis).
     * Chi ha bisogno del peso per una stima deve **riceverlo nella richiesta**:
     * lo fa `POST /workout-sessions/{id}/finish` con il campo `weight_kg`, che
     * transita e non viene conservato.
     */

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? url($this->avatar_path) : null;
    }
}
