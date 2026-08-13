<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialProvider;
use App\Enums\TenantKind;
use App\Enums\TenantStatus;
use App\Services\Auth\Social\SocialTokenVerifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
        'name', 'slug', 'join_code', 'kind', 'status', 'plan',
        'logo_path', 'color_primary', 'color_secondary', 'color_accent',
        'contact_email', 'locale', 'timezone',
        'ai_monthly_tokens_per_member', 'ai_driver', 'settings', 'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'kind' => TenantKind::class,
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'ai_monthly_tokens_per_member' => 'integer',
        ];
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // ───────────────────────── natura ─────────────────────────

    /**
     * È il tenant di una persona sola, e non una palestra cliente?
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
            'name' => $this->name,
            'slug' => $this->slug,
            'logo_url' => $this->logo_path ? url($this->logo_path) : null,
            'colors' => [
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
     * ⚠️ **La quota non e' piu' della palestra** — C20.
     *
     * Era un pozzo comune: con 2 milioni di token a palestra e un consumo medio
     * di 551.000 a testa bastava per tre o quattro persone, e la quarta restava
     * senza AI per il consumo di qualcun altro. Adesso il tetto e' di ciascuno
     * (`MemberAiQuota`), e la palestra lo governa decidendo quanto dare a ognuno
     * dei suoi: `ai_monthly_tokens_per_member`.
     *
     * Il costo del mese resta visibile dal pannello (`aiCostThisMonth()`): e'
     * la palestra a pagare, e vederlo serve — ma non e' piu' cio' che blocca.
     */
    public function tokensPerMember(): ?int
    {
        $suo = $this->ai_monthly_tokens_per_member;

        if ($suo !== null) {
            return $suo > 0 ? $suo : null;
        }

        $default = (int) config('ai.quota.default_monthly_tokens_per_user');

        return $default > 0 ? $default : null;
    }

    // ───────────────────────── utilità ─────────────────────────

    /** Codice d'invito leggibile: niente 0/O/1/I, che al telefono si confondono. */
    public static function generateJoinCode(): string
    {
        do {
            $code = Str::upper(Str::password(8, letters: true, numbers: true, symbols: false));
            $code = str_replace(['0', 'O', '1', 'I', 'L'], ['2', '3', '4', '5', '6'], $code);
        } while (static::where('join_code', $code)->exists());

        return $code;
    }

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
}
