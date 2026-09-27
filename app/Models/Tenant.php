<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialProvider;
use App\Enums\TenantKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Scopes\TenantScope;
use App\Services\Auth\Social\SocialTokenVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La palestra cliente. È il tenant della piattaforma.
 *
 * NON usa BelongsToTenant: è la tabella radice, non appartiene a nessuno.
 */
class Tenant extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Devono rispecchiare i `->default()` della migrazione.
     *
     * Non è una ridondanza: i default del DB si applicano all'INSERT, non
     * all'oggetto in memoria. Senza questi, subito dopo `Tenant::create()` lo
     * stato sarebbe `null` e `isActive()` andrebbe in fatale sull'enum, mentre
     * dopo un `fresh()` funzionerebbe — il tipo di bug che passa i test e cade
     * in produzione.
     */
    protected $attributes = [
        'status' => 'trial',
        /*
         * ⚠️ Come `status`: senza questo, subito dopo `Tenant::create()` il
         * campo sarebbe `null` in memoria e il cast su `TenantKind` andrebbe in
         * fatale — mentre dopo un `fresh()` funzionerebbe, perché il default del
         * DB si applica all'INSERT e non all'oggetto. È il tipo di guasto che
         * passa i test e cade in produzione, e su questa colonna in particolare
         * cadrebbe dentro `ePersonale()`, cioè dentro un controllo di sicurezza.
         */
        'kind' => 'gym',
        'plan' => 'starter',
        'color_primary' => '#111827',
        'color_secondary' => '#6B7280',
        'color_accent' => '#F59E0B',
        'locale' => 'it',
        'timezone' => 'Europe/Rome',
    ];

    protected $fillable = [
        'name', 'slug', 'kind', 'status', 'plan',
        'logo_path', 'color_primary', 'color_secondary', 'color_accent',
        'contact_email', 'locale', 'timezone',
        'ai_driver', 'settings', 'trial_ends_at',

        /*
         * 🆕 💶 23/09/2026, F5.1 — quanto fa pagare la palestra ai suoi.
         *
         * ✅ **D9**: *«lo scrive la palestra e noi non lo verifichiamo nemmeno.
         * Sono dati suoi e a noi non interessano»*. ⛔ Non entra in nessuna
         * fattura e non cambia nessun prezzo: serve solo al **loro** cruscotto.
         */
        'prezzo_per_allievo_cent',

        /*
         * 🆕 🧾 23/09/2026, F6.4 — i dati per le fatture, uno per uno i campi
         * del `CessionarioCommittente` della FatturaPA. ⚠️ Prefisso `fatt_`:
         * `name` è il nome **commerciale**, e senza prefisso prima o poi
         * qualcuno lo metterebbe in fattura al posto della ragione sociale.
         */
        'fatt_ragione_sociale', 'fatt_partita_iva', 'fatt_codice_fiscale',
        'fatt_indirizzo', 'fatt_cap', 'fatt_comune', 'fatt_provincia',
        'fatt_nazione', 'fatt_codice_sdi', 'fatt_pec',
        // G2 — la quota in chiamate (D6/D7).
        'ai_monthly_calls_per_member', 'ai_monthly_photo_calls_per_member',
        /*
         * 🆕 ⛔ **`ai_credits` non esiste piu' su questa tabella** — 16/09/2026.
         *
         * 📌 I gettoni sono della **persona** che li compra (3b-AH.8): il saldo
         * sta su `users.ai_credits` e lo muove solo `PortafoglioGettoni`. ⚠️ La
         * colonna e' stata **tolta**, non lasciata li' a zero: una colonna che
         * nessuno legge piu' e' la trappola gia' pagata con `plans.max_trainers`
         * — sembrava una regola e non lo era.
         */
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'kind' => TenantKind::class,
            'settings' => 'array',
            'trial_ends_at' => 'datetime',

            /*
             * 🤖 🆕 **Quando l'ha sospesa la macchina** — 27/09/2026.
             *
             * ⚠️ **Non è `fillable`, e non deve diventarlo**: dice *«questa
             * sospensione l'ha decisa il codice, non una persona»*, e la scrive
             * solo `LaSospensioneAutomatica` con `forceFill`. ⛔ Se un modulo
             * potesse impostarla, si potrebbe far annullare da un pagamento una
             * sospensione decisa da una persona.
             */
            'sospensione_automatica_dal' => 'datetime',
            'ai_monthly_calls_per_member' => 'integer',
            'ai_monthly_photo_calls_per_member' => 'integer',
            'prezzo_per_allievo_cent' => 'integer',

            // 🆕 🎀 P4 di plan_promozioni.md — i posti regalati da una promozione.
            'posti_extra' => 'integer',
            'posti_trainer_extra' => 'integer',
            'posti_extra_fino_al' => 'datetime',
        ];
    }

    /**
     * 🆕 🎀 **I posti in più regalati da una promozione** — P4 di `plan_promozioni.md`.
     *
     * ⚠️ Solo finché valgono (`posti_extra_fino_al` nel futuro): scaduti, tornano
     * zero da soli — nessun comando notturno da dimenticare. ⛔ Non sono
     * `fillable`: si scrivono solo da `ConsegnaDeiPremi`, con `forceFill`.
     *
     * @param  'iscritti'|'trainer'  $di
     */
    public function postiRegalati(string $di = 'iscritti'): int
    {
        if ($this->posti_extra_fino_al === null || $this->posti_extra_fino_al->isPast()) {
            return 0;
        }

        return (int) ($di === 'trainer' ? $this->posti_trainer_extra : $this->posti_extra);
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * 🆕 **Chi paga per questo tenant** — 3b-AH, Passo 0 (12/09/2026).
     *
     * 🚨 **`withoutGlobalScopes([TenantScope::class])`, e non è decorativo.**
     * È il punto per cui questa colonna esiste: dopo AH.1-quater il proprietario
     * di un tenant **può stare in un altro tenant** — un trainer che lavora in una
     * palestra e ha un portfolio suo. Senza, la relazione sarebbe filtrata dal
     * tenant corrente e risponderebbe `null` **proprio nel caso nuovo**, cioè
     * l'unico per cui la colonna è stata aggiunta.
     */
    public function proprietario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proprietario_id')
            ->withoutGlobalScopes([TenantScope::class]);
    }

    /**
     * 🔑 **A chi si scrive, per questa palestra** — 22/09/2026, fase F3.6.
     *
     * 📌 Serve al tasto *«manda il reset password»*: una palestra non ha una
     * casella sua, ha **una persona** che la amministra.
     *
     * ══ 🚨 TRE STRADE, IN QUEST'ORDINE, E IL PERCHÉ ══════════════════════
     *
     * 1. **`proprietario_id`**, se c'è: è chi paga, ed è la risposta giusta.
     * 2. **Il `gym_admin` più vecchio**: sulle palestre nate prima di 3b-AH
     *    quella colonna è vuota, e il titolare si riconosce solo dal ruolo.
     *    ⚠️ Il più vecchio e non uno a caso: è quello che ha creato tutto il
     *    resto, e mandare il reset a un collaboratore aggiunto la settimana
     *    scorsa significherebbe dare a lui le chiavi della palestra.
     * 3. **Niente.** ⛔ Non si ripiega su «un utente qualsiasi»: un reset
     *    mandato a un iscritto a caso è un incidente, non un ripiego.
     *
     * 💡 `runWithoutTenant` perché in modalità teams la pivot dei ruoli è
     * filtrata sul tenant corrente, e qui il contesto è vuoto.
     */
    public function aChiScrivere(): ?User
    {
        if ($this->proprietario !== null) {
            return $this->proprietario;
        }

        return app(TenantContext::class)->runWithoutTenant(
            fn (): ?User => User::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereIn('id', DB::table('model_has_roles as mhr')
                    ->join('roles as r', function ($join): void {
                        $join->on('r.id', '=', 'mhr.role_id')->on('r.tenant_id', '=', 'mhr.tenant_id');
                    })
                    ->where('mhr.tenant_id', $this->getKey())
                    ->where('r.name', UserRole::GymAdmin->value)
                    ->select('mhr.model_id'))
                ->orderBy('id')
                ->first(),
        );
    }

    /**
     * 🆕 **Tutti gli abbonamenti di questa palestra, anche quelli finiti** —
     * 21/09/2026, fase F1.
     *
     * 💡 La storia serve: *«perché a marzo questo cliente aveva l'AI e ad
     * aprile no?»* è la domanda per cui `plan_subscriptions` esiste invece di
     * una colonna su `tenants`.
     */
    public function abbonamenti(): HasMany
    {
        return $this->hasMany(PlanSubscription::class)
            ->withoutGlobalScopes([TenantScope::class]);
    }

    /**
     * 🆕 **L'abbonamento che vale adesso** — 21/09/2026, fase F1.
     *
     * ══ 🚨 PERCHÉ NASCE UNA RELAZIONE PER UNA COSA GIÀ SCRITTA IN TRE POSTI ══
     *
     * Perché era scritta **in tre posti**: `PianoAttivo::per()` (per persona),
     * `CosaPaghi::pianoCorrente()` (per la palestra che guarda il suo piano) e
     * `PianiInVendita::abbonamentoStripeAttivo()`. ⛔ Tre copie della stessa
     * domanda divergono, e qui divergere vuol dire mostrare un piano e
     * fatturarne un altro.
     *
     * 💡 E serve **eager-loadable**: il pannello della piattaforma elenca le
     * palestre con il piano accanto, e una query per riga su venti righe è una
     * pagina che si apre in due secondi invece che in uno.
     *
     * ── ⚠️ Perché `orderByDesc` e non `latestOfMany()` ────────────────────
     *
     * `latestOfMany()` costruisce una sottoquery che va d'accordo male con lo
     * scope `attivi()` (che ha dentro un `where` annidato e la pausa). 💡 Con
     * l'ordinamento, Laravel prende **la prima riga per ogni padre** — che è
     * esattamente la regola di `PianoAttivo`: l'abbonamento attivo **più
     * recente**.
     *
     * 🚨 `withoutGlobalScopes([TenantScope::class])`: una relazione su
     * `tenant_id` è **già** filtrata per definizione, e lo scope aggiungerebbe
     * solo la possibilità di non trovare niente quando la si legge dentro il
     * contesto di un'altra palestra — cioè sempre, dal pannello `/god`.
     */
    public function abbonamentoAttivo(): HasOne
    {
        return $this->hasOne(PlanSubscription::class)
            ->withoutGlobalScopes([TenantScope::class])
            ->attivi()
            ->orderByDesc('starts_at');
    }

    // ───────────────────────── natura ─────────────────────────

    /**
     * È lo spazio **di una persona**, e non una palestra cliente?
     *
     * ══ ⚠️ «DI UNA PERSONA», NON «CON UNA PERSONA SOLA» — 12/09/2026 ═══════
     *
     * 🚨 Questa distinzione era inutile finché le due cose coincidevano, e ha
     * smesso di esserlo con `3b-AH`: nello spazio di un trainer indipendente
     * ci stanno **anche i suoi allievi**, e resta comunque `personal`.
     *
     * 💡 Il `kind` risponde a *«chi è il cliente»* — una persona o
     * un'organizzazione — e da quello dipendono le due regole qui sotto, che
     * restano giuste parola per parola anche con venti allievi dentro.
     *
     * ⛔ **Chi è quella persona lo dice `proprietario_id`, non il conteggio
     * degli utenti.** E *«questo spazio è tuo?»* si chiede a [eDi], mai a
     * questo metodo: usarlo per decidere chi comanda vorrebbe dire che ogni
     * allievo comanda l'abbonamento del suo trainer.
     *
     * ── 🚨 Perché è un controllo di sicurezza ─────────────────────────────
     *
     * 🚨 **È un controllo di sicurezza, non una comodità di visualizzazione.**
     * Da questo dipende che il `join_code` di un tenant personale non apra
     * niente: senza, uno sconosciuto che indovinasse quel codice si
     * registrerebbe **dentro** lo spazio di un'altra persona, e da lì il
     * `TenantScope` gli mostrerebbe i dati di quella persona — che è
     * esattamente ciò che tutta la Parte B è costruita per impedire.
     */
    public function ePersonale(): bool
    {
        return $this->kind === TenantKind::Personal;
    }

    /**
     * 🆕 **Questo spazio è TUO?** — 3b-AH, Passo 0 (12/09/2026).
     *
     * 🚨 **Non è `ePersonale()`, e la differenza è tutta la fase AH.**
     *
     * | Domanda | Il metodo |
     * |---|---|
     * | «Questo spazio è di una persona sola o di un'organizzazione?» | `ePersonale()` |
     * | «Questo spazio è **tuo**?» | `eDi()` |
     *
     * ⛔ Le due domande si somigliavano finché in un tenant personale ci stava
     * una persona sola: «personale» voleva dire «tuo», e `loPuoGestire()` poteva
     * chiedere il tipo. ⚠️ Da AH.1 in avanti nel tenant di un trainer ci stanno
     * anche i suoi allievi: il tenant resta personale **e non è più loro**.
     *
     * 💡 Chi decide **chi può disdire, chi può uscire e chi può portarsi le
     * proprie cose altrove** deve chiedere questo, non il `kind`.
     */
    public function eDi(?User $utente): bool
    {
        if ($utente === null || $this->proprietario_id === null) {
            /*
             * 🚨 **Mai `null === null → true`.** Sarebbe «il tenant senza
             * proprietario appartiene all'utente senza account»: un cancello
             * aperto scritto per distrazione, in un metodo che decide chi può
             * disdire un abbonamento.
             *
             * ⚠️ La direzione dell'errore è quella giusta: un tenant a cui manca
             * il proprietario non è **di nessuno**, e chi ci sta dentro non
             * comanda niente. Il comando `abbonamenti:tenant-senza-proprietario`
             * esiste per accorgersene prima che sia qualcuno a farlo.
             */
            return false;
        }

        return (int) $this->proprietario_id === (int) $utente->getKey();
    }

    /**
     * Solo le palestre clienti.
     *
     * ⚠️ **Va usato in ogni listato e in ogni conteggio commerciale** (F1.4).
     * Dopo la Parte B `tenants` cresce come `users`, non come il numero di
     * clienti: un `Tenant::count()` nudo risponderebbe «abbiamo diecimila
     * palestre» il giorno in cui ne abbiamo dodici.
     */
    public function scopePalestre(Builder $query): Builder
    {
        return $query->where('kind', TenantKind::Gym->value);
    }

    /** Solo i tenant personali. Serve alle statistiche e alla manutenzione. */
    public function scopePersonali(Builder $query): Builder
    {
        return $query->where('kind', TenantKind::Personal->value);
    }

    // ───────────────────────── stato ─────────────────────────

    /**
     * Gli utenti di questa palestra possono entrare?
     *
     * Un trial scaduto blocca comunque, anche se lo stato è ancora `trial`:
     * altrimenti basterebbe non toccare il record per avere accesso illimitato.
     */
    public function isActive(): bool
    {
        if (! $this->status->allowsLogin()) {
            return false;
        }

        if ($this->status === TenantStatus::Trial && $this->trial_ends_at !== null) {
            return $this->trial_ends_at->isFuture();
        }

        return true;
    }

    // ───────────────────────── branding ─────────────────────────

    /**
     * Il payload dell'endpoint pubblico /api/v1/branding/lookup.
     *
     * È l'unica cosa che l'app scarica prima di autenticarsi, quindi qui NON
     * deve finire nulla oltre al branding: l'endpoint è pubblico e diventerebbe
     * un modo per enumerare i clienti.
     */
    public function branding(): array
    {
        return [
            /*
             * 🚨 **Un tenant personale non ha un nome da mostrare** — F3.
             *
             * Per una palestra `name` è l'insegna, e l'app la scrive in cima
             * alla schermata. Per un tenant personale `name` è **il nome e
             * cognome della persona**: mostrarlo lì vorrebbe dire scrivere
             * «Mario Rossi» nel posto dove ci si aspetta la propria palestra,
             * come se si fosse iscritti a sé stessi.
             *
             * ⚠️ E non è solo brutto: `branding()` è anche il corpo
             * dell'endpoint **pubblico** `/branding/lookup`. Quello ignora già i
             * tenant personali (F1.3), ma se un giorno qualcuno allentasse quel
             * filtro, questo `null` sarebbe la seconda serratura.
             *
             * 💡 `null` e non una stringa vuota: la stringa vuota si disegna
             * comunque, come una riga alta zero che sposta tutto. `null` è
             * un'assenza che l'app può decidere di non disegnare affatto.
             */
            'name' => $this->ePersonale() ? null : $this->name,
            'slug' => $this->slug,

            /*
             * ══ 🆕 ⛔ UN TRAINER NON VESTE L'APP — regola 8.1.3, 18/09/2026 ═══
             *
             * 📌 Il committente: *«Assicurati che un trainer non convogli le
             * personalizzazioni dell'app come le palestre. Cioè, un free
             * trainer non crea un "vestito" per l'app dei suoi allievi.»*
             *
             * 🚨 **Il difetto era a metà, ed è il tipo peggiore.** Qui sopra
             * si azzerava il **nome** di un tenant personale, con tanto di
             * commento che spiegava perché — e poi logo e tre colori passavano
             * lo stesso. Un free trainer che si metteva un logo vestiva l'app
             * di **tutti** i suoi allievi: da quando stanno nel suo spazio
             * (AH.1), `users.tenant_id` punta lì e `/auth/me` risponde con
             * questo metodo.
             *
             * 💡 **Un tenant personale non è un marchio**, e non deve poterlo
             * diventare: il vestito è della palestra, che è un'organizzazione.
             * ⚠️ L'app regge i `null` da sempre — ogni colore ha il suo
             * `?? fallback…` in `GymBranding.fromJson`, e il logo è nullable —
             * quindi ricade sul marchio di serie invece di disegnare un buco.
             */
            'logo_url' => ! $this->ePersonale() && $this->logo_path ? url($this->logo_path) : null,
            'colors' => $this->ePersonale()
                ? ['primary' => null, 'secondary' => null, 'accent' => null]
                : [
                    'primary' => $this->color_primary,
                    'secondary' => $this->color_secondary,
                    'accent' => $this->color_accent,
                ],
            'locale' => $this->locale,

            /*
             * Con quali fornitori esterni si puo' accedere — C17.
             *
             * 🚨 **Lo decide il server, non l'app.** Un pulsante «Accedi con
             * Apple» che risponde sempre errore fa sembrare rotta tutta
             * l'applicazione, non solo quel pulsante: finche' mancano le
             * credenziali in `services.social.*.client_ids`, l'elenco e' vuoto e
             * l'app non disegna niente.
             *
             * ⚠️ Sta nel branding e non in un endpoint suo perche' l'app lo
             * chiama gia' **prima** della schermata d'accesso, per vestirsi dei
             * colori: una seconda richiesta per sapere quali pulsanti mostrare
             * aggiungerebbe un'attesa a ogni avvio.
             */
            'social' => array_values(array_filter(
                array_map(
                    static fn (SocialProvider $p): ?string => app(SocialTokenVerifier::class)->configured($p)
                        ? $p->value
                        : null,
                    SocialProvider::cases(),
                ),
            )),
        ];
    }

    // ───────────────────────── quote AI ─────────────────────────

    /** Token AI consumati nel mese corrente (input + output). */
    public function aiTokensUsedThisMonth(): int
    {
        return AiUsageLog::tokensForTenant((int) $this->getKey());
    }

    /**
     * Il costo AI del mese in millesimi di centesimo.
     *
     * Congelato al momento di ogni chiamata, non ricalcolato dai listini di
     * oggi: vedi `AiUsageRecorder`.
     */
    public function aiCostThisMonth(): int
    {
        return AiUsageLog::costForTenant((int) $this->getKey());
    }

    /**
     * ⚠️ **La quota non e' piu' della palestra** — C20, e da G2 si conta in
     * **chiamate**.
     *
     * Era un pozzo comune: bastava per tre o quattro persone, e la quarta
     * restava senza AI per il consumo di qualcun altro. Adesso il tetto e' di
     * ciascuno (`MemberAiQuota`), e la palestra lo governa decidendo quanto dare
     * a ognuno dei suoi: `ai_monthly_calls_per_member`.
     *
     * Il costo del mese resta visibile dal pannello (`aiCostThisMonth()`): e'
     * la palestra a pagare, e vederlo serve — ma non e' piu' cio' che blocca.
     */
    public function chiamatePerMember(): ?int
    {
        $suo = $this->ai_monthly_calls_per_member;

        if ($suo !== null) {
            return $suo > 0 ? $suo : null;
        }

        $default = (int) config('ai.quota.default_monthly_calls_per_user');

        return $default > 0 ? $default : null;
    }

    // ───────────────────────── utilità ─────────────────────────

    /*
     * ⛔ Qui c'era `generateJoinCode()`: il codice palestra non esiste più (F11,
     * 24/09/2026). In una palestra si entra solo con il link d'invito.
     */

    /**
     * Lo `slug` di un tenant personale: unico, e **non indovinabile**.
     *
     * ⚠️ `slug` è `unique` e NOT NULL, quindi un valore ci deve essere; ma per
     * un tenant personale non c'è nessun nome sensato da usare. Derivarlo dal
     * nome della persona (`mario-rossi`, `mario-rossi-2`…) sarebbe la scelta
     * ovvia ed è quella sbagliata due volte:
     *
     * 1. 🚨 `slug` **esce dal server**: `branding()` lo pubblica, e
     *    `/api/v1/branding/lookup` è un endpoint **pubblico**. Uno slug derivato
     *    dal nome renderebbe l'endpoint un modo per verificare se una certa
     *    persona ha un account — con nome e cognome in chiaro nella richiesta.
     * 2. La coda `-2`, `-3` per gestire gli omonimi direbbe pure **quanti**
     *    Mario Rossi sono iscritti.
     *
     * 💡 ULID e non `Str::random()`: è ordinabile nel tempo, quindi un elenco di
     * tenant personali si legge in ordine di creazione senza una colonna in più,
     * e resta comunque privo di qualunque informazione sulla persona.
     */
    public static function generatePersonalSlug(): string
    {
        do {
            $slug = 'p-'.Str::lower((string) Str::ulid());
        } while (static::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * 🧾 **Ha i dati per ricevere una fattura?** — F6.4, F8.1.
     *
     * 🚨 È la domanda che si fa `LeFatture` prima di emettere: una fattura XML
     * senza ragione sociale o senza sede **non è valida**, e lo SDI la
     * scarterebbe. ⚠️ Mancando uno di questi, la fattura non si emette e il
     * pannello dice cosa manca — invece di produrre un documento che sembra
     * giusto e non lo è.
     *
     * 💡 Serve **la P.IVA o il codice fiscale**, non tutti e due; e serve **il
     * codice SDI o la PEC**, non tutti e due.
     *
     * @return list<string> i campi che mancano, in parole
     */
    public function datiDiFatturazioneMancanti(): array
    {
        $mancano = [];

        if (blank($this->fatt_ragione_sociale)) {
            $mancano[] = 'il nome legale';
        }

        if (blank($this->fatt_partita_iva) && blank($this->fatt_codice_fiscale)) {
            $mancano[] = 'la partita IVA o il codice fiscale';
        }

        if (blank($this->fatt_indirizzo) || blank($this->fatt_cap) || blank($this->fatt_comune)) {
            $mancano[] = 'la sede legale (via, CAP e comune)';
        }

        $sdi = (string) $this->fatt_codice_sdi;

        if ((blank($sdi) || $sdi === '0000000') && blank($this->fatt_pec)) {
            $mancano[] = 'il codice SDI o la PEC';
        }

        return $mancano;
    }
}
