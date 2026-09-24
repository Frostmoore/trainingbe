<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni\Schemas;

use App\Enums\ComeSiAttiva;
use App\Enums\DurataDelloSconto;
use App\Enums\EventoDiPromozione;
use App\Enums\GenereDiPromozione;
use App\Enums\StatoPromozione;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiCondizione;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use App\Enums\UserRole;
use App\Models\PacchettoGettoni;
use App\Models\Plan;
use App\Models\Promozione;
use App\Models\Tenant;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard\Step;

/**
 * 🎁 **Il modulo di una promozione** — 24/09/2026, fase P1 di `plan_promozioni.md`.
 *
 * 📌 *«su promozioni->nuovo ci deve essere una scelta: a) Gettoni b) Abbonamenti
 * c) entrambe. Dopo di che, devo avere un form diverso per ciascuna scelta»*.
 *
 * ══ ⚠️ UNA DEFINIZIONE SOLA, DUE FORME ═══════════════════════════════════
 *
 * La creazione è una procedura a passi ([passi]), la modifica una pagina a
 * sezioni ([sezioni]): **gli stessi campi**, presi dagli stessi metodi. ⛔ Due
 * moduli scritti due volte divergono al primo campo aggiunto, e la modifica
 * salverebbe cose che la creazione non sa chiedere.
 *
 * ══ 🎀 «UN FORM DIVERSO PER CIASCUNA SCELTA» ═════════════════════════════
 *
 * Il genere scelto al passo 1 decide **quali premi** compaiono al passo 2
 * (`GenereDiPromozione::premiAmmessi()`), e il tipo di ogni premio decide
 * **quali campi** compaiono (`TipoDiPremio::campi()`). Un campo che non conta
 * per quel premio non si vede — e al salvataggio si svuota ([pulisci]), così
 * un premio cambiato di tipo non si porta dietro valori del tipo di prima.
 */
final class ModuloDellaPromozione
{
    /**
     * @return list<Step> la creazione, a passi
     *
     * 💡 Senza descrizioni sotto i titoli: con sei passi la barra in alto
     * scorreva di lato, e l'ultimo passo non si vedeva.
     */
    public static function passi(): array
    {
        return [
            Step::make('Cosa promuovi')
                ->schema([self::genere()]),

            Step::make('Cosa si dà')
                ->schema([self::premi()]),

            Step::make('A chi')
                ->schema([self::bersagli(), self::condizioni()]),

            Step::make('Come si attiva')
                ->schema(self::attivazione()),

            Step::make('Limiti')
                ->columns(2)
                ->schema(self::limiti()),

            Step::make('Il testo')
                ->columns(2)
                ->schema(self::testo()),
        ];
    }

    /** @return list<Section> la modifica, a sezioni */
    public static function sezioni(): array
    {
        return [
            Section::make('Cosa promuovi')->schema([self::genere()]),
            Section::make('Cosa si dà')->schema([self::premi()]),
            Section::make('A chi')->schema([self::bersagli(), self::condizioni()]),
            Section::make('Come si attiva')->schema(self::attivazione()),
            Section::make('Limiti')->columns(2)->schema(self::limiti()),
            Section::make('Il testo')->columns(2)->schema(self::testo()),
        ];
    }

    // ───────────────────────── i pezzi ─────────────────────────

    private static function genere(): Radio
    {
        return Radio::make('genere')
            ->hiddenLabel()
            ->options(GenereDiPromozione::options())
            ->descriptions(GenereDiPromozione::descrizioni())
            ->default(GenereDiPromozione::Gettoni->value)
            ->required()
            ->live()
            /*
             * ⚠️ **Si blocca dopo il primo uso**: cambiare il genere di una
             * promozione già data renderebbe inspiegabili le assegnazioni che
             * ci sono — «perché Tizio ha avuto uno sconto sul canone da una
             * promozione sui gettoni?».
             */
            ->disabled(fn (?Promozione $record): bool => $record?->assegnazioni()->exists() === true)
            ->helperText(fn (?Promozione $record): ?string => $record?->assegnazioni()->exists() === true
                ? 'Non si cambia più: questa promozione è già stata data a qualcuno.'
                : null);
    }

    private static function premi(): Repeater
    {
        $usa = static fn (?string $tipo, string $campo): bool => TipoDiPremio::tryFrom((string) $tipo)?->usa($campo) ?? false;
        $pretende = static fn (?string $tipo, string $campo): bool => TipoDiPremio::tryFrom((string) $tipo)?->pretende($campo) ?? false;
        $inEuro = static fn (callable $get): bool => $get('tipo_sconto') === TipoDiSconto::Importo->value;

        return Repeater::make('premi')
            ->hiddenLabel()
            ->relationship()
            ->addActionLabel('Aggiungi un premio')
            ->defaultItems(1)
            ->minItems(1)
            ->columns(3)
            ->itemLabel(fn (array $state): ?string => TipoDiPremio::tryFrom((string) ($state['tipo'] ?? ''))?->label())
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::pulisci($data))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::pulisci($data))
            ->schema([
                Select::make('tipo')
                    ->label('Premio')
                    ->options(fn (callable $get, ?string $state): array => TipoDiPremio::optionsPer(
                        GenereDiPromozione::tryFrom((string) $get('../../genere')),
                        $state,
                    ))
                    ->helperText(fn (?string $state): ?string => TipoDiPremio::tryFrom((string) $state)?->descrizione())
                    ->required()
                    ->live()
                    ->native(false)
                    ->columnSpanFull(),

                // 🆕 🤝 P6 — porta un amico: il premio va all'amico o a chi l'ha invitato.
                Select::make('a_chi')
                    ->label('A chi')
                    ->options([
                        'destinatario' => 'All\'amico che arriva',
                        'chi_ha_invitato' => 'A chi l\'ha invitato',
                    ])
                    ->default('destinatario')
                    ->native(false)
                    ->visible(fn (callable $get): bool => $get('../../evento') === EventoDiPromozione::AmicoArrivato->value),

                Select::make('tipo_sconto')
                    ->label('Sconto in')
                    ->options([TipoDiSconto::Percentuale->value => 'Percentuale', TipoDiSconto::Importo->value => 'Euro'])
                    ->default(TipoDiSconto::Percentuale->value)
                    ->live()
                    ->native(false)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'tipo_sconto'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'tipo_sconto')),

                /*
                 * 🚨 Percentuale da 1 a 100, oppure **euro** a schermo e
                 * centesimi nel database: il modulo di F2 chiedeva i centesimi
                 * («500 = 5,00 €»), che è il modo più semplice per regalare
                 * cento volte tanto.
                 */
                TextInput::make('valore')
                    ->label(fn (callable $get): string => match (true) {
                        $get('tipo') === TipoDiPremio::GettoniBonus->value => 'Gettoni in più (%)',
                        $inEuro($get) => 'Quanto toglie (€)',
                        default => 'Percentuale',
                    })
                    ->numeric()
                    ->minValue(fn (callable $get): float => $inEuro($get) ? 0.01 : 1)
                    ->maxValue(fn (callable $get): int => $inEuro($get) ? 10_000 : 100)
                    ->step(fn (callable $get): string => $inEuro($get) ? '0.01' : '1')
                    ->formatStateUsing(fn ($state, callable $get) => $state !== null && $inEuro($get) ? $state / 100 : $state)
                    ->dehydrateStateUsing(fn ($state, callable $get) => $state === null || $state === ''
                        ? null
                        : ($inEuro($get) ? (int) round((float) $state * 100) : (int) $state))
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'valore'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'valore')),

                TextInput::make('gettoni')
                    ->label(fn (callable $get): string => match ($get('tipo')) {
                        TipoDiPremio::GettoniBonus->value => 'Gettoni in più (fissi)',
                        TipoDiPremio::GettoniRicorrenti->value => 'Gettoni al mese',
                        default => 'Gettoni',
                    })
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'gettoni'))
                    /*
                     * 🆕 P2 — il bonus è «+N fissi» **o** «+N%»: uno dei due
                     * serve, e il modulo lo pretende sul primo se manca il secondo.
                     */
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'gettoni')
                        || ($get('tipo') === TipoDiPremio::GettoniBonus->value && blank($get('valore'))))
                    ->helperText(fn (callable $get): ?string => $get('tipo') === TipoDiPremio::GettoniBonus->value
                        ? 'Oppure lascia vuoto e metti la percentuale.'
                        : null),

                Select::make('pacchetto_id')
                    ->label('Pacchetto')
                    ->placeholder('Tutti i pacchetti')
                    ->options(fn (): array => PacchettoGettoni::query()->orderBy('ordine')->pluck('nome', 'id')->all())
                    ->native(false)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'pacchetto_id'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'pacchetto_id')),

                TextInput::make('prezzo_cent')
                    ->label('Prezzo speciale (€)')
                    ->numeric()
                    ->minValue(0)
                    ->step('0.01')
                    ->formatStateUsing(fn ($state) => $state !== null ? $state / 100 : null)
                    ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (int) round((float) $state * 100))
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'prezzo_cent'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'prezzo_cent')),

                Select::make('da_plan_id')
                    ->label('Da quale piano')
                    ->placeholder('Qualunque piano')
                    ->options(fn (): array => self::piani())
                    ->native(false)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'da_plan_id')),

                Select::make('plan_id')
                    ->label(fn (callable $get): string => $get('tipo') === TipoDiPremio::Upgrade->value ? 'Verso quale piano' : 'Piano')
                    ->placeholder('Tutti i piani')
                    ->options(fn (): array => self::piani())
                    ->native(false)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'plan_id'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'plan_id')),

                Select::make('durata')
                    ->label('Per quanto')
                    ->options(fn (?string $state): array => DurataDelloSconto::options()
                        + ($state !== null && DurataDelloSconto::tryFrom($state) !== null && ! DurataDelloSconto::from($state)->costruito()
                            ? [$state => DurataDelloSconto::from($state)->label().' (in arrivo)']
                            : []))
                    ->default(DurataDelloSconto::UnaVolta->value)
                    ->live()
                    ->native(false)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'durata'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'durata')),

                TextInput::make('volte')
                    ->label(fn (callable $get): string => match ($get('tipo')) {
                        TipoDiPremio::ScontoAbbonamento->value => 'Quanti rinnovi',
                        TipoDiPremio::AbbonamentoRegalato->value => 'Quanti periodi',
                        default => 'Quanti mesi',
                    })
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'volte')
                        && ($get('tipo') !== TipoDiPremio::ScontoAbbonamento->value || $get('durata') === DurataDelloSconto::NVolte->value))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'volte')
                        || ($get('tipo') === TipoDiPremio::ScontoAbbonamento->value && $get('durata') === DurataDelloSconto::NVolte->value)),

                TextInput::make('giorni')
                    ->label(fn (callable $get): string => match ($get('tipo')) {
                        TipoDiPremio::ProvaGratuita->value => 'Giorni di prova',
                        TipoDiPremio::SbloccaSconto->value => 'Valido per (giorni)',
                        default => 'Scadono dopo (giorni)',
                    })
                    ->helperText(fn (callable $get): ?string => match ($get('tipo')) {
                        TipoDiPremio::ProvaGratuita->value => null,
                        TipoDiPremio::SbloccaSconto->value => 'Vuoto: lo sconto sbloccato non scade.',
                        default => 'Vuoto: non scadono mai.',
                    })
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'giorni'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'giorni')),

                TextInput::make('posti')
                    ->label('Posti in più')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'posti'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'posti')),

                // 🆕 🎀 P4 — i posti di chi: iscritti (o allievi di un trainer) o trainer di una palestra.
                Select::make('posti_di')
                    ->label('Posti per')
                    ->options([
                        'iscritti' => 'Iscritti (o allievi del trainer)',
                        'trainer' => 'Trainer (solo palestre)',
                    ])
                    ->default('iscritti')
                    ->native(false)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'posti_di'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'posti_di')),

                Select::make('sblocca_genere')
                    ->label('Sconto su')
                    ->options([
                        GenereDiPromozione::Gettoni->value => 'Gettoni',
                        GenereDiPromozione::Abbonamenti->value => 'Abbonamenti',
                    ])
                    ->native(false)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'sblocca_genere'))
                    ->required(fn (callable $get): bool => $pretende($get('tipo'), 'sblocca_genere')),
            ]);
    }

    /**
     * 🎯 I bersagli: più di uno, e in **OR**. «Per i trainer e per Tizio» vuol
     * dire chi è trainer **oppure** è Tizio.
     */
    /**
     * 🆕 ⚠️ **Pubblica dal 24/09/2026**, e non per comodità.
     *
     * 📌 Il committente, sugli avvisi in-app: *«usiamo gli stessi che possono
     * essere destinatari di una promozione»*. 💡 Questo modulo è già scritto e
     * già provato: riusarlo tale e quale è il modo di non avere **due schermate
     * che chiedono la stessa cosa in due modi diversi**.
     *
     * ⛔ E una copia divergerebbe in silenzio: chi aggiunge un tipo di
     * bersaglio lo aggiungerebbe di qua, e l'altra schermata continuerebbe a
     * non saperlo.
     */
    public static function bersagli(): Repeater
    {
        return Repeater::make('bersagli')
            ->hiddenLabel()
            ->relationship()
            ->addActionLabel('Aggiungi destinatari')
            ->defaultItems(1)
            ->minItems(1)
            ->columns(2)
            ->helperText('Si sommano: basta rientrare in uno. Una riga «togli» esclude, e vince su tutte. Solo righe «togli» = tutti tranne loro.')
            ->schema([
                Select::make('tipo')
                    ->label('Chi')
                    ->options(TipoBersaglio::options())
                    ->default(TipoBersaglio::Tutti->value)
                    ->required()
                    ->live()
                    ->native(false),

                // 🆕 P5 — opzione 26: «tutti tranne…».
                Toggle::make('escludi')
                    ->label('Togli invece di aggiungere')
                    ->inline(false)
                    ->default(false),

                Select::make('ruolo')
                    ->label('Ruolo')
                    ->options(collect(UserRole::cases())
                        ->mapWithKeys(fn (UserRole $r) => [$r->value => $r->label()]))
                    ->visible(fn (callable $get): bool => $get('tipo') === TipoBersaglio::Ruolo->value)
                    ->required(fn (callable $get): bool => $get('tipo') === TipoBersaglio::Ruolo->value)
                    ->native(false),

                Select::make('tenant_id')
                    ->label('Palestra')
                    ->options(fn (): array => Tenant::query()->palestre()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (callable $get): bool => $get('tipo') === TipoBersaglio::Palestra->value)
                    ->required(fn (callable $get): bool => $get('tipo') === TipoBersaglio::Palestra->value),

                /*
                 * ⚠️ Si cerca, non si elenca: con qualche migliaio di persone
                 * un elenco intero sarebbe inservibile, e quello di F2 si
                 * fermava a 200 nomi senza dirlo.
                 */
                Select::make('user_id')
                    ->label(fn (callable $get): string => $get('tipo') === TipoBersaglio::AllieviDiTrainer->value ? 'Trainer' : 'Persona')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->withoutGlobalScopes()
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (User $u): array => [$u->getKey() => "{$u->name} ({$u->email})"])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($u = User::withoutGlobalScopes()->find($value)) !== null
                        ? "{$u->name} ({$u->email})"
                        : null)
                    ->visible(fn (callable $get): bool => in_array(
                        $get('tipo'),
                        [TipoBersaglio::Utente->value, TipoBersaglio::AllieviDiTrainer->value],
                        true,
                    ))
                    ->required(fn (callable $get): bool => in_array(
                        $get('tipo'),
                        [TipoBersaglio::Utente->value, TipoBersaglio::AllieviDiTrainer->value],
                        true,
                    )),
            ]);
    }

    /**
     * 🆕 🔎 **Le condizioni** — P5 (opzioni 20-24). In **AND**: restringono i
     * destinatari. I parametri stanno nella colonna json `parametri`, e i campi
     * si chiamano `parametri.<chiave>` perché Filament li scriva lì dentro.
     */
    public static function condizioni(): Repeater
    {
        $usa = static fn (?string $tipo, string $parametro): bool => TipoDiCondizione::tryFrom((string) $tipo)?->usa($parametro) ?? false;

        return Repeater::make('condizioni')
            ->label('Solo se')
            ->relationship()
            ->addActionLabel('Aggiungi una condizione')
            ->defaultItems(0)
            ->columns(2)
            ->helperText('Devono essere vere tutte. Nessuna condizione: valgono solo i destinatari qui sopra.')
            ->schema([
                Select::make('tipo')
                    ->label('Condizione')
                    ->options(TipoDiCondizione::options())
                    ->required()
                    ->live()
                    ->native(false),

                Select::make('parametri.stato')
                    ->label('Chi')
                    ->options(TipoDiCondizione::STATI_ABBONAMENTO)
                    ->native(false)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'stato'))
                    ->required(fn (callable $get): bool => $usa($get('tipo'), 'stato')),

                TextInput::make('parametri.giorni')
                    ->label('Giorni')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'giorni'))
                    ->required(fn (callable $get): bool => $usa($get('tipo'), 'giorni')),

                DatePicker::make('parametri.data')
                    ->label('Dal giorno')
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'data'))
                    ->required(fn (callable $get): bool => $usa($get('tipo'), 'data')),

                TextInput::make('parametri.mesi')
                    ->label('Mesi')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'mesi'))
                    ->required(fn (callable $get): bool => $usa($get('tipo'), 'mesi')),

                Select::make('parametri.plan_ids')
                    ->label('Piani')
                    ->multiple()
                    ->options(fn (): array => Plan::query()->orderBy('price_cents')->pluck('name', 'id')->all())
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'plan_ids'))
                    ->required(fn (callable $get): bool => $usa($get('tipo'), 'plan_ids')),

                TextInput::make('parametri.gettoni')
                    ->label('Gettoni')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (callable $get): bool => $usa($get('tipo'), 'gettoni'))
                    ->required(fn (callable $get): bool => $usa($get('tipo'), 'gettoni')),
            ]);
    }

    /**
     * @return list<Field> l'attivazione e, se è «a evento», quale evento
     */
    private static function attivazione(): array
    {
        $aEvento = static fn (callable $get): bool => $get('attivazione') === ComeSiAttiva::Evento->value;

        return [
            self::comeSiAttiva(),

            // 🆕 ⚡ P6 — quale evento, e i suoi parametri.
            Select::make('evento')
                ->label('Quando')
                ->options(EventoDiPromozione::options())
                ->live()
                ->native(false)
                ->visible($aEvento)
                ->required($aEvento),

            TextInput::make('evento_parametri.numero')
                ->label('Quale rinnovo')
                ->helperText('Vuoto: a ogni rinnovo. 1 = il primo rinnovo (il secondo pagamento).')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->visible(fn (callable $get): bool => $aEvento($get) && $get('evento') === EventoDiPromozione::Rinnovo->value),

            Select::make('evento_parametri.quando')
                ->label('Quando si premia')
                ->options([
                    'registrazione' => 'Appena l\'amico si iscrive',
                    'primo_acquisto' => 'Al primo acquisto dell\'amico',
                ])
                ->default('registrazione')
                ->native(false)
                ->visible(fn (callable $get): bool => $aEvento($get) && $get('evento') === EventoDiPromozione::AmicoArrivato->value)
                ->helperText('Nei premi scegli «a chi»: all\'amico che arriva, o a chi l\'ha invitato.'),
        ];
    }

    private static function comeSiAttiva(): Radio
    {
        return Radio::make('attivazione')
            ->hiddenLabel()
            ->options(fn (?string $state): array => collect(ComeSiAttiva::cases())
                ->filter(fn (ComeSiAttiva $c): bool => $c->costruito() || $c->value === $state)
                ->mapWithKeys(fn (ComeSiAttiva $c): array => [$c->value => $c->label()])
                ->all())
            ->descriptions(collect(ComeSiAttiva::cases())
                ->mapWithKeys(fn (ComeSiAttiva $c): array => [$c->value => $c->descrizione()])
                ->all())
            ->default(ComeSiAttiva::Automatica->value)
            ->required()
            ->live()
            /*
             * 🆕 P2 — ⛔ **«Esegui» senza regali non consegna niente**: sconti e
             * bonus si usano pagando, e un accredito di massa di sconti sarebbe
             * un tasto che non fa nulla.
             */
            ->rules([
                fn (callable $get): \Closure => function (string $attributo, mixed $valore, \Closure $fallisci) use ($get): void {
                    $tipi = collect((array) $get('premi'))
                        ->map(fn (array $premio): ?TipoDiPremio => TipoDiPremio::tryFrom((string) ($premio['tipo'] ?? '')));

                    $regali = $tipi->contains(fn (?TipoDiPremio $t): bool => $t?->eUnRegalo() === true);

                    if ($valore === ComeSiAttiva::Massa->value && ! $regali) {
                        $fallisci('L\'accredito di massa consegna solo regali (gettoni, mesi, posti): aggiungine uno, o scegli un\'altra attivazione.');
                    }

                    /*
                     * 🆕 P6 — ⛔ Un evento consegna regali o **sblocca** uno sconto:
                     * uno sconto normale scatterebbe senza dare niente.
                     */
                    if ($valore === ComeSiAttiva::Evento->value && ! $regali
                        && ! $tipi->contains(TipoDiPremio::SbloccaSconto)) {
                        $fallisci('Una promozione a evento deve regalare qualcosa o sbloccare uno sconto.');
                    }
                },
            ]);
    }

    /** @return list<Component|Field> */
    private static function limiti(): array
    {
        return [
            DateTimePicker::make('inizia_il')
                ->label('Comincia il')
                ->seconds(false)
                ->helperText('Vuoto: da subito. Si può mettere anche l\'ora, per le offerte lampo.'),

            DateTimePicker::make('scade_il')
                ->label('Finisce il')
                ->seconds(false)
                ->after('inizia_il')
                ->helperText('Vuoto: per sempre. ⚠️ Un\'offerta senza fine è un prezzo nuovo.'),

            TextInput::make('massimo_usi')
                ->label('Quante volte in tutto')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->helperText('Vuoto: senza limite. Si conta quando il pagamento riesce, non ai clic.'),

            TextInput::make('massimo_per_persona')
                ->label('Quante volte a persona')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->default(1)
                ->helperText('Vuoto: senza limite, si riapplica a ogni acquisto.'),

            TextInput::make('budget_cent')
                ->label('Budget in euro scontati')
                ->numeric()
                ->minValue(0.01)
                ->step('0.01')
                ->formatStateUsing(fn ($state) => $state !== null ? $state / 100 : null)
                ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (int) round((float) $state * 100))
                ->helperText('Raggiunto questo tetto, la promozione si ferma. Vuoto: nessun tetto.'),

            TextInput::make('budget_gettoni')
                ->label('Budget in gettoni regalati')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->visible(fn (callable $get): bool => $get('genere') !== GenereDiPromozione::Abbonamenti->value)
                ->helperText('Vuoto: nessun tetto.'),

            Toggle::make('cumulabile')
                ->label('Cumulabile')
                ->helperText(
                    'Si somma alle altre cumulabili, una dopo l\'altra: 30% + 20% fa 44%, non 50%. '
                    .'Spenta, vale da sola — e se conviene lei, esclude anche le altre.'
                ),

            TextInput::make('priorita')
                ->label('Priorità')
                ->numeric()
                ->integer()
                ->default(0)
                ->helperText('Fra le non cumulabili vince la più alta; a parità, quella che conviene al cliente.'),
        ];
    }

    /** @return list<Field> */
    private static function testo(): array
    {
        return [
            TextInput::make('nome')
                ->label('Nome')
                ->required()
                ->maxLength(80)
                ->helperText('Come la chiami tu, e come compare in ricevuta.'),

            TextInput::make('codice')
                // ⛔ Non è un codice sconto da scrivere (24/09/2026): è quello che il link di attivazione porta dentro.
                ->label('Codice del link')
                ->required()
                ->maxLength(40)
                // ⚠️ Solo lettere e numeri: finisce in un indirizzo (`/promo/{codice}`).
                ->alphaNum()
                ->default(fn (): string => Promozione::nuovoCodice())
                ->unique(Promozione::class, 'codice', ignoreRecord: true)
                ->dehydrateStateUsing(fn (?string $state): string => mb_strtoupper(trim((string) $state)))
                ->helperText('Sta dentro il link di attivazione. Chi ha il link può attivare l\'offerta: trattalo come una password.'),

            Textarea::make('descrizione')
                ->label('Cosa dice al cliente')
                ->rows(3)
                ->columnSpanFull()
                ->helperText('Il testo della pagina che spiega l\'offerta, nel suo pannello.'),

            Select::make('stato')
                ->label('Stato')
                ->options(collect(StatoPromozione::cases())
                    ->mapWithKeys(fn (StatoPromozione $s) => [$s->value => $s->label()]))
                ->default(StatoPromozione::Attiva->value)
                ->required()
                ->native(false),

            // 🆕 📣 P8 — opzione 37: come si avvisa chi la riceve. Niente spuntato = in silenzio.
            CheckboxList::make('avviso')
                ->label('Avvisa chi la riceve')
                ->options([
                    'app' => 'Notifica nell\'app',
                    'email' => 'Email',
                ])
                ->helperText('Nessuno: arriva in silenzio. I regali avvisano quando arrivano; per gli sconti c\'è il tasto «Avvisa i destinatari».'),

            // 🆕 👀 P8 — opzione 38: dove si vede. Niente spuntato = solo col link, nel pannello.
            CheckboxList::make('visibile_in')
                ->label('Dove si vede')
                ->options([
                    'app' => 'Nell\'app, fra le offerte',
                    'pannello' => 'Nel pannello, fra le offerte',
                    'sito' => 'Sul sito (solo se è per tutti, senza condizioni)',
                ])
                ->helperText('Nessuno: la vede solo chi apre il link.'),
        ];
    }

    // ───────────────────────── di servizio ─────────────────────────

    /**
     * 🧹 **Svuota i campi che il tipo del premio non usa.**
     *
     * ⛔ Senza, un premio nato «sconto del 20%» e cambiato in «gettoni in
     * regalo» si porterebbe dietro il 20 in `valore`: nessuno lo vede, e il
     * giorno che qualcuno legge la riga non capisce più cosa dà.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function pulisci(array $data): array
    {
        $tipo = TipoDiPremio::tryFrom((string) ($data['tipo'] ?? ''));

        if ($tipo === null) {
            return $data;
        }

        $colonne = ['gettoni', 'tipo_sconto', 'valore', 'prezzo_cent', 'pacchetto_id', 'plan_id',
            'da_plan_id', 'durata', 'volte', 'giorni', 'posti', 'posti_di', 'sblocca_genere'];

        foreach ($colonne as $colonna) {
            if (! $tipo->usa($colonna)) {
                $data[$colonna] = null;
            }
        }

        // Il numero di rinnovi conta solo per lo sconto «per N rinnovi».
        if ($tipo === TipoDiPremio::ScontoAbbonamento && ($data['durata'] ?? null) !== DurataDelloSconto::NVolte->value) {
            $data['volte'] = null;
        }

        return $data;
    }

    /** @return array<int, string> i piani che si pagano, per le tendine */
    private static function piani(): array
    {
        return Plan::query()
            ->where('price_cents', '>', 0)
            ->orderBy('price_cents')
            ->pluck('name', 'id')
            ->all();
    }
}
