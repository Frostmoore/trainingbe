<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Models\Tenant;
use App\Support\Fatturazione\ControlliFiscali;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Le impostazioni della palestra — B3.2.
 *
 * 🚨 **Cosa NON c'e', ed e' il punto della pagina.**
 * Stato dell'abbonamento, piano commerciale e tetto mensile di token AI **non**
 * compaiono: sono i termini del contratto con noi, e si toccano solo dal
 * pannello di piattaforma. Un cliente che si toglie da solo la sospensione o si
 * alza il tetto di consumo non e' un cliente, e' un problema di fatturazione.
 *
 * Il controllo non e' solo l'assenza dei campi — `TenantPolicy::update()` dice
 * la stessa cosa, e un campo aggiunto per distrazione qui non basterebbe a
 * scavalcarla.
 */
class GymSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Impostazioni';

    protected static ?string $title = 'Impostazioni della palestra';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.gym.pages.gym-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isGymAdmin() === true;
    }

    public function mount(): void
    {
        $this->form->fill($this->palestra()?->only([
            'name', 'contact_email', 'logo_path',
            'color_primary', 'color_secondary', 'color_accent',

            // 💶 F5.1 — la quota che la palestra fa ai suoi (D9).
            'prezzo_per_allievo_cent',

            // 🧾 F6.4 — i dati per le fatture.
            ...self::CAMPI_FISCALI,
        ]) ?? []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([

                Section::make('La palestra')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nome')->required()->maxLength(120),
                        TextInput::make('contact_email')->label('Email di contatto')->email()->maxLength(255),
                    ]),

                /*
                 * ══ 💶 LA QUOTA CHE FATE PAGARE VOI — 23/09/2026, F5.1 ══════
                 *
                 * ✅ **D9**: *«lo scrive la palestra e noi non lo verifichiamo
                 * nemmeno. Sono dati suoi e a noi non interessano»*.
                 *
                 * 💡 Serve **solo a loro**: è l'unico numero che manca per
                 * rispondere a *«questo iscritto mi conviene?»*. ⛔ Senza, il
                 * cruscotto può mostrare solo il costo — metà del conto, quella
                 * che fa sembrare il servizio una spesa e basta.
                 *
                 * 🚨 **In centesimi in tabella, in euro a schermo.** ⚠️ La
                 * conversione sta qui e non nel modello: l'euro è il modo in cui
                 * la gente scrive i soldi, il centesimo è il modo in cui si
                 * contano senza perderne uno per strada.
                 */
                Section::make('La tua quota')
                    ->description(
                        'Quanto fai pagare al mese a un iscritto. Lo usi solo tu, nel tuo cruscotto: '
                        .'noi non lo vediamo, non lo verifichiamo e non lo usiamo per fatturare niente.'
                    )
                    ->schema([
                        TextInput::make('prezzo_per_allievo_cent')
                            ->label('Quota mensile per iscritto')
                            ->numeric()
                            ->prefix('€')
                            ->minValue(0)
                            ->maxValue(9999)
                            ->step('0.01')
                            ->helperText('Puoi lasciarlo vuoto: il cruscotto mostrera\' solo il costo.')
                            /*
                             * ⚠️ `null` resta `null`, e non diventa zero:
                             * «non l'ho scritto» e «li alleno gratis» sono due
                             * cose diverse, e il cruscotto le dice in due modi
                             * diversi.
                             */
                            ->formatStateUsing(fn (?int $state): ?string => $state === null
                                ? null
                                : number_format($state / 100, 2, '.', ''))
                            ->dehydrateStateUsing(fn (?string $state): ?int => filled($state)
                                ? (int) round(((float) $state) * 100)
                                : null),
                    ]),

                /*
                 * ══ 🧾 I DATI PER LE FATTURE — 23/09/2026, F6.4 ═════════════
                 *
                 * 📌 Il committente: *«servono tutte le info per la
                 * fatturazione (p.iva, pec, codice SDI, nome legale, sede
                 * legale)»*.
                 *
                 * 🚨 **Sono i campi del `CessionarioCommittente` della
                 * FatturaPA**, uno per uno: senza, la fattura XML (F8) non è
                 * valida. ⚠️ E si controllano **qui**, mentre la persona sta
                 * scrivendo: noi non trasmettiamo allo SDI (D2), quindi una
                 * partita IVA sbagliata si scoprirebbe settimane dopo, allo
                 * scarto — vedi `ControlliFiscali`.
                 *
                 * 💡 **«Nome legale» è diverso dal nome della palestra**: «Palestra
                 * Olimpia» è il nome che vedono gli iscritti, «Olimpia Sport SSD
                 * a r.l.» è quello che va in fattura.
                 */
                Section::make('Dati per le fatture')
                    ->description('Vanno sulle fatture che ti mandiamo. Il nome e la sede sono quelli legali, non quelli della palestra.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('fatt_ragione_sociale')
                            ->label('Nome legale (ragione sociale)')
                            ->maxLength(160)
                            ->columnSpanFull(),

                        TextInput::make('fatt_partita_iva')
                            ->label('Partita IVA')
                            ->maxLength(20)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                                ? ControlliFiscali::pulisci($state)
                                : null)
                            ->rule(fn (Get $get): \Closure => function (string $attributo, mixed $valore, \Closure $fallisci) use ($get): void {
                                if (filled($valore) && ! ControlliFiscali::partitaIva((string) $valore, (string) ($get('fatt_nazione') ?: 'IT'))) {
                                    $fallisci('Questa partita IVA non torna: controlla di non aver invertito due cifre.');
                                }
                            }),

                        TextInput::make('fatt_codice_fiscale')
                            ->label('Codice fiscale')
                            ->maxLength(20)
                            ->helperText('Solo se è diverso dalla partita IVA (per esempio in una ditta individuale).')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                                ? ControlliFiscali::pulisci($state)
                                : null)
                            ->rule(fn (): \Closure => function (string $attributo, mixed $valore, \Closure $fallisci): void {
                                if (filled($valore) && ! ControlliFiscali::codiceFiscale((string) $valore)) {
                                    $fallisci('Un codice fiscale ha 11 cifre (un\'impresa) o 16 caratteri (una persona).');
                                }
                            }),

                        TextInput::make('fatt_indirizzo')
                            ->label('Sede legale: via e numero')
                            ->maxLength(200)
                            ->columnSpanFull(),

                        TextInput::make('fatt_cap')->label('CAP')->maxLength(12),

                        TextInput::make('fatt_comune')->label('Comune')->maxLength(120),

                        TextInput::make('fatt_provincia')
                            ->label('Provincia (sigla)')
                            ->maxLength(2)
                            ->helperText('Due lettere, solo in Italia: RM, MI, VT.')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null),

                        TextInput::make('fatt_nazione')
                            ->label('Nazione')
                            ->default('IT')
                            ->maxLength(2)
                            ->helperText('Il codice di due lettere: IT, CH, DE.')
                            ->dehydrateStateUsing(fn (?string $state): string => strtoupper($state ?: 'IT')),

                        /*
                         * 📬 **Codice SDI oppure PEC, e il modulo lo sa.**
                         *
                         * 💡 `0000000` vuol dire «non ho un codice: mandatela
                         * alla PEC». ⛔ Chi lo usa senza dare la PEC produce
                         * una fattura che non può arrivare da nessuna parte —
                         * la regola sta qui sotto, dove si vedono tutti e due i
                         * campi insieme.
                         */
                        TextInput::make('fatt_codice_sdi')
                            ->label('Codice destinatario (SDI)')
                            ->maxLength(7)
                            ->helperText('Sette caratteri. Se non ce l\'hai scrivi 0000000 e compila la PEC.')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                                ? ControlliFiscali::pulisci($state)
                                : null)
                            ->rule(fn (): \Closure => function (string $attributo, mixed $valore, \Closure $fallisci): void {
                                if (filled($valore) && ! ControlliFiscali::codiceSdi((string) $valore)) {
                                    $fallisci('Il codice SDI ha sette caratteri, lettere e numeri.');
                                }
                            }),

                        TextInput::make('fatt_pec')
                            ->label('PEC')
                            ->email()
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => ControlliFiscali::pulisci((string) $get('fatt_codice_sdi')) === '0000000')
                            ->validationMessages(['required' => 'Con il codice 0000000 serve la PEC: è l\'unico posto dove può arrivare la fattura.']),
                    ]),

                Section::make('Aspetto nell\'app')
                    ->description('E\' quello che vedono i tuoi iscritti quando aprono l\'app.')
                    ->columns(3)
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->directory('loghi')
                            ->columnSpanFull(),

                        ColorPicker::make('color_primary')->label('Primario')->required(),
                        ColorPicker::make('color_secondary')->label('Secondario')->required(),
                        ColorPicker::make('color_accent')->label('Accento')->required(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $palestra = $this->palestra();

        if ($palestra === null) {
            return;
        }

        $this->authorize('update', $palestra);

        $palestra->update($this->form->getState());

        Notification::make()
            ->title('Impostazioni salvate')
            ->body('I nuovi colori arrivano subito nell\'app degli iscritti.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('salva')
                ->label('Salva')
                ->submit('save'),

            /*
             * ⛔ **Via «rigenera codice d'invito»** — 23/09/2026, F6.4.
             *
             * 📌 *«rigenera codice d'invito toglilo, non esiste più il codice
             * d'invito»*. L'ingresso in palestra è solo il link d'invito, che è
             * monouso e si revoca uno per uno; il codice, che entrava infinite
             * volte, sparisce del tutto in F11.
             */
        ];
    }

    /**
     * ⛔ **Il sottotitolo col codice d'invito non c'è più** — F6.4.
     *
     * Mostrava il `join_code`: una chiave che entrava infinite volte, e che il
     * committente ha tolto da tutto (F11).
     */
    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * 🧾 I campi della FatturaPA che la palestra compila da sé — F6.4.
     *
     * 💡 Elencati una volta, e usati per riempire e per salvare: ⛔ due elenchi
     * scritti a mano divergono, e il campo dimenticato nel secondo si
     * scoprirebbe su una fattura senza partita IVA.
     */
    private const CAMPI_FISCALI = [
        'fatt_ragione_sociale', 'fatt_partita_iva', 'fatt_codice_fiscale',
        'fatt_indirizzo', 'fatt_cap', 'fatt_comune', 'fatt_provincia',
        'fatt_nazione', 'fatt_codice_sdi', 'fatt_pec',
    ];

    private function palestra(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }
}
