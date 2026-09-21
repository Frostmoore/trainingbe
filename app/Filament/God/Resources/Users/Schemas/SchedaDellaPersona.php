<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Schemas;

use App\Enums\SuCosaVale;
use App\Enums\UserRole;
use App\Models\AiCreditMovement;
use App\Models\AiUsageLog;
use App\Models\PagamentoIncassato;
use App\Models\PlanSubscription;
use App\Models\Promozione;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Billing\LePromozioni;
use App\Services\Billing\RegistroDeiPagamenti;
use App\Support\Tenancy\TenantContext;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 👤 **La scheda di una persona** — 22/09/2026, fasi F4.2 e F4.4.
 *
 * ══ 📌 COM'È STATA CHIESTA ════════════════════════════════════════════════
 *
 * Quattro cose, e in quest'ordine: **identità**, **appartenenza** (rifatta),
 * **gettoni**, **promozioni** — più, da F4.4, *quanto ha speso dall'inizio* e
 * lo **stato dell'abbonamento** con la sua **prima attivazione**.
 *
 * ══ 🚨 «APPARTENENZA RIFATTA»: COSA C'ERA DI ROTTO ═══════════════════════
 *
 * Il pannello scriveva **«Palestra: Mario Rossi»**. ⛔ Non era un'etichetta
 * sbagliata: era una **domanda sbagliata**. `users.tenant_id` non dice in
 * quale palestra vai — dice **quale spazio paga per te**, e per chi non sta in
 * una palestra quello spazio è un tenant personale che porta il suo stesso
 * nome.
 *
 * ✅ Qui l'appartenenza si dichiara per quello che è: **personale**,
 * **palestra**, o **piattaforma** (il super admin, che non sta in nessuno
 * spazio). 💡 E i ruoli si leggono dalla pivot, non dalla relazione: in
 * modalità teams `$user->roles` è filtrata sul tenant corrente, e qui il
 * contesto è vuoto — sarebbe vuota per tutti.
 *
 * ══ ⚠️ DUE NUMERI CHE SEMBRANO UGUALI E NON LO SONO ══════════════════════
 *
 * | | |
 * |---|---|
 * | **Ha speso** | quanto **ci ha dato**, in euro: è un ricavo |
 * | **Costa d'AI** | quanto **ci costa** lui, in dollari: è un costo nostro, e non lo paga nessuno |
 *
 * ⛔ È la stessa trappola di `SchedaDellaPalestra`: affiancarli senza dirlo è
 * il modo più rapido di leggere un margine al contrario.
 *
 * ══ 🚨 L'ABBONAMENTO NON STA SULLA PERSONA ═══════════════════════════════
 *
 * `plan_subscriptions` ha `tenant_id`. 💡 Per un iscritto di palestra quello
 * che si vede qui è **l'abbonamento della palestra**, ed è corretto: è quello
 * che gli apre l'app. ⚠️ Ma va **detto**, o sembra suo — e qualcuno
 * proverebbe a disdirlo dalla sua scheda facendo un danno a tutta la palestra.
 */
class SchedaDellaPersona
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Chi è')
                ->columns(3)
                ->schema([
                    TextEntry::make('name')->label('Nome')->weight('bold'),

                    TextEntry::make('email')
                        ->label('Email')
                        ->copyable()
                        ->copyMessage('Email copiata'),

                    TextEntry::make('username')
                        ->label('Nome utente')
                        ->placeholder('non ne ha')
                        ->formatStateUsing(fn (?string $state): string => $state === null ? '' : '@'.$state),

                    TextEntry::make('phone')->label('Telefono')->placeholder('non l\'ha dato'),

                    TextEntry::make('ruoli')
                        ->label('Ruoli')
                        ->badge()
                        ->state(fn (User $r): array => self::ruoliDi($r))
                        ->color(fn (string $state): string => match ($state) {
                            UserRole::SuperAdmin->value => 'danger',
                            UserRole::GymAdmin->value => 'warning',
                            UserRole::Trainer->value, UserRole::FreeTrainer->value => 'info',
                            default => 'gray',
                        }),

                    /*
                     * 🚨 **Tre stati, non due.** ⛔ Un badge «attivo/spento»
                     * direbbe «spento» anche a un account **cancellato**, e chi
                     * legge proverebbe a riattivarlo dal tasto sbagliato.
                     */
                    TextEntry::make('stato')
                        ->label('Stato')
                        ->badge()
                        ->state(fn (User $r): string => match (true) {
                            $r->deleted_at !== null => 'nel cestino',
                            ! $r->is_active => 'sospeso',
                            default => 'attivo',
                        })
                        ->color(fn (User $r): string => match (true) {
                            $r->deleted_at !== null => 'danger',
                            ! $r->is_active => 'warning',
                            default => 'success',
                        }),

                    TextEntry::make('created_at')
                        ->label('Iscritto il')
                        ->state(fn (User $r): string => $r->created_at?->format('d/m/Y').' · '
                            .($r->created_at?->diffForHumans(syntax: true) ?? '')),

                    TextEntry::make('last_login_at')
                        ->label('Ultimo accesso')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('non è mai entrato'),

                    /*
                     * 💡 **Sapere se la password esiste, senza vederla.**
                     * `password_is_set` è `false` per chi è nato da un invito e
                     * non l'ha mai scelta: è la risposta a *«non riesco a
                     * entrare»* più comune che ci sia, e sta qui per non
                     * doverla cercare nel database.
                     */
                    TextEntry::make('password_is_set')
                        ->label('Password')
                        ->badge()
                        ->state(fn (User $r): string => $r->password_is_set ? 'scelta da lui' : 'mai scelta')
                        ->color(fn (User $r): string => $r->password_is_set ? 'gray' : 'warning'),
                ]),

            Section::make('Dove sta')
                ->columns(3)
                ->description('⚠️ Il «tenant» è lo spazio che paga, non la palestra in cui va ad allenarsi.')
                ->schema([
                    TextEntry::make('dove')
                        ->label('Spazio')
                        ->badge()
                        ->state(fn (User $r): string => match (true) {
                            $r->tenant === null => 'piattaforma',
                            $r->tenant->ePersonale() => 'personale',
                            default => 'palestra',
                        })
                        ->color(fn (User $r): string => match (true) {
                            $r->tenant === null => 'danger',
                            $r->tenant->ePersonale() => 'info',
                            default => 'gray',
                        }),

                    TextEntry::make('tenant.name')
                        ->label('Si chiama')
                        ->placeholder('nessuno spazio (super admin)'),

                    TextEntry::make('tenant_da')
                        ->label('Lo spazio esiste da')
                        ->state(fn (User $r): string => $r->tenant?->created_at?->format('d/m/Y') ?? '—'),

                    /*
                     * ⚠️ **I ruoli si contano nella palestra, non sull'utente.**
                     * Un trainer con un portfolio suo lavora in palestra senza
                     * avere lì la propria colonna `tenant_id`: se si guardasse
                     * solo quella, risulterebbe fuori da un posto in cui entra
                     * tutti i giorni.
                     */
                    TextEntry::make('dove_lavora')
                        ->label('Lavora anche in')
                        ->state(fn (User $r): string => self::doveLavora($r))
                        ->columnSpanFull(),
                ]),

            Section::make('L\'abbonamento')
                ->columns(3)
                ->schema([
                    TextEntry::make('piano')
                        ->label('Piano')
                        ->badge()
                        ->state(fn (User $r): string => self::abbonamento($r)?->plan?->name ?? 'nessun abbonamento')
                        ->color(fn (User $r): string => self::abbonamento($r) === null ? 'danger' : 'success'),

                    TextEntry::make('di_chi')
                        ->label('Di chi è')
                        ->state(fn (User $r): string => match (true) {
                            self::abbonamento($r) === null => '—',
                            $r->tenant?->ePersonale() ?? false => 'suo',
                            default => 'della palestra: lo condivide con tutti gli iscritti',
                        }),

                    /*
                     * ⚠️ `stripe_subscription_id` a `null` è **l'unico modo**
                     * di distinguere un abbonamento regalato da uno pagato.
                     * 💡 Dirlo qui evita che qualcuno cerchi su Stripe una
                     * riga che su Stripe non è mai esistita.
                     */
                    TextEntry::make('come_paga')
                        ->label('Come')
                        ->badge()
                        ->state(fn (User $r): string => match (true) {
                            self::abbonamento($r) === null => '—',
                            self::abbonamento($r)?->stripe_subscription_id === null => 'regalato da noi',
                            default => 'pagato su Stripe',
                        })
                        ->color(fn (User $r): string => self::abbonamento($r)?->stripe_subscription_id === null
                            ? 'warning'
                            : 'gray'),

                    TextEntry::make('scade')
                        ->label('Scade')
                        ->state(fn (User $r): string => match (true) {
                            self::abbonamento($r) === null => '—',
                            self::abbonamento($r)?->ends_at === null => 'non scade',
                            default => self::abbonamento($r)->ends_at->format('d/m/Y')
                                .(self::abbonamento($r)->rinnova ? ' (si rinnova)' : ' (non si rinnova)'),
                        }),

                    /*
                     * 📌 *«prima attivazione»*: la data del **primo**
                     * abbonamento, non di quello in corso. 💡 È la risposta a
                     * «da quanto è cliente», che non è «da quando ha questo
                     * piano» — chi cambia piano ricomincerebbe da zero.
                     */
                    TextEntry::make('prima_volta')
                        ->label('Abbonato dalla prima volta il')
                        ->state(fn (User $r): string => self::primaAttivazione($r)),

                    TextEntry::make('quanti_abbonamenti')
                        ->label('Abbonamenti in tutto')
                        ->state(fn (User $r): string => (string) self::quantiAbbonamenti($r)),
                ]),

            Section::make('I soldi')
                ->columns(3)
                ->description('💶 In euro: è quello che ci ha dato. ⚠️ Il costo dell\'AI, più sotto, è in dollari ed è una spesa nostra.')
                ->schema([
                    /*
                     * 🚨 **Conta da quando il registro esiste** (22/09/2026):
                     * prima di `pagamenti_incassati` i pagamenti a canone non
                     * li scriveva nessuno. ⛔ Un totale gonfiato a occhio
                     * sarebbe peggio di un totale parziale dichiarato.
                     */
                    TextEntry::make('speso')
                        ->label('Ha speso, dall\'inizio')
                        ->state(fn (User $r): string => self::euro(app(RegistroDeiPagamenti::class)->totaleDellaPersona($r)))
                        ->weight('bold')
                        ->helperText('Dal 22/09/2026, da quando il registro degli incassi esiste.'),

                    TextEntry::make('speso_gettoni')
                        ->label('… di cui in gettoni')
                        ->state(fn (User $r): string => self::euro(self::spesoDelTipo($r, PagamentoIncassato::GETTONI))),

                    TextEntry::make('speso_canone')
                        ->label('… di cui di abbonamento')
                        ->state(fn (User $r): string => self::euro(self::spesoDelTipo($r, PagamentoIncassato::CANONE))),
                ]),

            Section::make('Gettoni e intelligenza artificiale')
                ->columns(4)
                ->schema([
                    TextEntry::make('ai_credits')
                        ->label('Ne ha adesso')
                        ->badge()
                        ->state(fn (User $r): string => number_format((int) $r->ai_credits, 0, ',', '.'))
                        ->color(fn (User $r): string => ((int) $r->ai_credits) > 0 ? 'success' : 'gray'),

                    TextEntry::make('gettoni_entrati')
                        ->label('Ne ha ricevuti')
                        ->state(fn (User $r): string => number_format(self::gettoni($r, entrati: true), 0, ',', '.'))
                        ->helperText('Comprati, regalati e rettifiche.'),

                    TextEntry::make('gettoni_spesi')
                        ->label('Ne ha spesi')
                        ->state(fn (User $r): string => number_format(self::gettoni($r, entrati: false), 0, ',', '.')),

                    TextEntry::make('chiamate')
                        ->label('Chiamate automatiche')
                        ->state(fn (User $r): string => number_format(self::chiamate($r), 0, ',', '.')),

                    /*
                     * ⚠️ **In dollari, e con l'avviso.** È il listino del
                     * modello: un costo nostro, che non paga nessuno.
                     */
                    TextEntry::make('costo_ai')
                        ->label('Quanto ci costa la sua AI')
                        ->state(fn (User $r): string => '$'.number_format(
                            AiUsageLog::millicentsToCurrency(self::costoAi($r)), 2, ',', '.'
                        ))
                        ->helperText('⚠️ È un costo nostro, non un ricavo.')
                        ->columnSpan(2),

                    /*
                     * 💡 `0` = **illimitato**, e non è un refuso: è la
                     * convenzione di `MemberAiQuota`. ⛔ Scrivere «0 chiamate»
                     * su chi ne ha infinite sarebbe il contrario del vero.
                     */
                    TextEntry::make('tetto')
                        ->label('Tetto mensile')
                        ->badge()
                        ->state(fn (User $r): string => match (true) {
                            $r->ai_monthly_call_cap === 0 => 'illimitato (concessione)',
                            $r->ai_monthly_call_cap === null => 'quello del suo piano',
                            default => $r->ai_monthly_call_cap.' al mese',
                        })
                        ->color(fn (User $r): string => $r->ai_monthly_call_cap === 0 ? 'warning' : 'gray')
                        ->columnSpan(2),
                ]),

            /*
             * ══ 🎟️ LE PROMOZIONI CHE LO RIGUARDANO — F4.2 ══════════════════
             *
             * ⚠️ Sono quelle **automatiche**: si applicano da sole al prossimo
             * acquisto. ⛔ Quelle a codice non si possono elencare qui — un
             * codice vale per chi ce l'ha, e mostrarlo nel pannello di una
             * persona che non l'ha ricevuto direbbe una cosa falsa.
             *
             * 💡 Si chiede a `LePromozioni::automaticheDi()`, la **stessa**
             * funzione che decide lo sconto alla cassa: un elenco calcolato in
             * un altro modo prometterebbe sconti che Stripe poi non fa.
             */
            Section::make('Promozioni attive per lui')
                ->schema([
                    TextEntry::make('promo_canone')
                        ->label('Sull\'abbonamento')
                        ->state(fn (User $r): string => self::promozioni($r, SuCosaVale::Canone))
                        ->listWithLineBreaks(),

                    TextEntry::make('promo_gettoni')
                        ->label('Sui gettoni')
                        ->state(fn (User $r): string => self::promozioni($r, SuCosaVale::Gettoni))
                        ->listWithLineBreaks(),
                ])
                ->columns(2),
        ]);
    }

    // ───────────────────────── i conti ─────────────────────────

    private static function contesto(): TenantContext
    {
        return app(TenantContext::class);
    }

    private static function euro(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '.').' €';
    }

    /** @return list<string> */
    private static function ruoliDi(User $utente): array
    {
        $ruoli = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $utente->getMorphClass())
            ->where('model_has_roles.model_id', $utente->getKey())
            ->pluck('roles.name')
            ->all();

        if ($utente->isSuperAdmin()) {
            array_unshift($ruoli, UserRole::SuperAdmin->value);
        }

        return array_values(array_unique($ruoli));
    }

    /**
     * Gli spazi in cui ha un ruolo, **diversi dal suo**.
     *
     * 💡 È il caso del trainer indipendente che lavora in palestra: il suo
     * `tenant_id` resta il portfolio personale, ma il ruolo ce l'ha là.
     */
    private static function doveLavora(User $utente): string
    {
        $nomi = self::contesto()->runWithoutTenant(
            fn (): array => DB::table('model_has_roles as mhr')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'mhr.role_id')->on('r.tenant_id', '=', 'mhr.tenant_id');
                })
                ->join('tenants as t', 't.id', '=', 'mhr.tenant_id')
                ->where('mhr.model_type', $utente->getMorphClass())
                ->where('mhr.model_id', $utente->getKey())
                ->when(
                    $utente->tenant_id !== null,
                    fn ($q) => $q->where('mhr.tenant_id', '!=', $utente->tenant_id),
                )
                ->distinct()
                ->pluck('t.name')
                ->all(),
        );

        return $nomi === [] ? 'solo nel suo spazio' : implode(' · ', $nomi);
    }

    /**
     * ⚠️ **Sta sul tenant, non sulla persona.** Vedi il blocco in testa.
     */
    private static function abbonamento(User $utente): ?PlanSubscription
    {
        return $utente->tenant?->abbonamentoAttivo;
    }

    private static function primaAttivazione(User $utente): string
    {
        $tenantId = $utente->tenant_id;

        if ($tenantId === null) {
            return '—';
        }

        $quando = self::contesto()->runWithoutTenant(
            fn () => PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->orderBy('starts_at')
                ->value('starts_at'),
        );

        return $quando === null ? 'mai' : $quando->format('d/m/Y');
    }

    private static function quantiAbbonamenti(User $utente): int
    {
        if ($utente->tenant_id === null) {
            return 0;
        }

        return (int) self::contesto()->runWithoutTenant(
            fn (): int => PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $utente->tenant_id)
                ->count(),
        );
    }

    private static function spesoDelTipo(User $utente, string $tipo): int
    {
        return (int) self::contesto()->runWithoutTenant(
            fn (): int => (int) PagamentoIncassato::query()
                ->where('user_id', $utente->getKey())
                ->delTipo($tipo)
                ->sum('importo_cent'),
        );
    }

    /**
     * I gettoni entrati o usciti dal suo portafoglio, in valore assoluto.
     *
     * 💡 `delta` è **firmato**: positivo quando entrano, negativo quando si
     * consumano. ⛔ Sommarlo tutto insieme darebbe il saldo, che è già scritto
     * in `users.ai_credits` — e non risponderebbe a nessuna delle due domande.
     */
    private static function gettoni(User $utente, bool $entrati): int
    {
        $somma = (int) AiCreditMovement::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('user_id', $utente->getKey())
            ->when($entrati, fn ($q) => $q->where('delta', '>', 0))
            ->when(! $entrati, fn ($q) => $q->where('delta', '<', 0))
            ->sum('delta');

        return abs($somma);
    }

    private static function chiamate(User $utente): int
    {
        return (int) AiUsageLog::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('user_id', $utente->getKey())
            ->count();
    }

    private static function costoAi(User $utente): int
    {
        return (int) AiUsageLog::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('user_id', $utente->getKey())
            ->sum('cost_millicents');
    }

    private static function promozioni(User $utente, SuCosaVale $cosa): string
    {
        $promozioni = app(LePromozioni::class)->automaticheDi($utente, $cosa);

        if ($promozioni->isEmpty()) {
            return 'nessuna';
        }

        return $promozioni
            ->map(fn (Promozione $p): string => $p->nome.' — '.$p->scrittoBene())
            ->implode("\n");
    }
}
