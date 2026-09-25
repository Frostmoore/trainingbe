<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Avvisi;

use App\Enums\AzioneDellAvviso;
use App\Filament\God\Resources\Avvisi\Pages\CreateAvviso;
use App\Filament\God\Resources\Avvisi\Pages\EditAvviso;
use App\Filament\God\Resources\Avvisi\Pages\ListAvvisi;
use App\Filament\God\Resources\Promozioni\Schemas\ModuloDellaPromozione;
use App\Filament\Support\VoceDiScheda;
use App\Models\Avviso;
use App\Models\AvvisoDecisione;
use App\Models\Promozione;
use App\Services\Promozioni\ChiLaRiceve;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 📣 **Gli avvisi in-app** — blocco B4, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«io (god) devo poter mandare notifiche a chi
 * voglio io — gruppi di pubblico o utenti individuali, usiamo gli stessi che
 * possono essere destinatari di una promozione — facendo apparire una modale
 * in-app con un tasto per approvare (testo che deciderò di volta in volta) e uno
 * per rifiutare»*.
 *
 * ══ 🚨 IL PUBBLICO NON SI RIPROGETTA: SI PUNTA ══════════════════════════════
 *
 * 💡 I due ripetitori dei destinatari sono **gli stessi** delle promozioni
 * (`ModuloDellaPromozione::bersagli()` e `::condizioni()`), resi pubblici il
 * 24/09. ⛔ Una copia divergerebbe in silenzio: chi aggiunge un tipo di
 * bersaglio lo aggiungerebbe di là, e questa schermata continuerebbe a non
 * saperlo.
 */
class AvvisoResource extends Resource
{
    protected static ?string $model = Avviso::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $modelLabel = 'avviso';

    protected static ?string $pluralModelLabel = 'avvisi';

    protected static ?string $slug = 'avvisi';

    protected static ?string $navigationLabel = 'Avvisi in-app';

    protected static string|\UnitEnum|null $navigationGroup = 'Offerte su misura';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Cosa dice')->schema([
                TextInput::make('titolo')->required()->maxLength(120),

                Textarea::make('testo')
                    ->required()
                    ->rows(4)
                    ->helperText('Va letto in tre secondi: è una modale, non una pagina.'),
            ]),

            Section::make('I due tasti')
                ->description('Le etichette le scrivi tu ogni volta: «Va bene» e «No, grazie» sono solo un punto di partenza.')
                ->columns(2)
                ->schema([
                    TextInput::make('tasto_approva')->required()->maxLength(40)->default('Va bene'),
                    TextInput::make('tasto_rifiuta')->required()->maxLength(40)->default('No, grazie'),
                ]),

            Section::make('Cosa fa «approva»')
                ->description('Rifiutare chiude sempre la modale, e in tutti e due i casi si registra chi ha risposto e quando.')
                ->columns(2)
                ->schema([
                    Select::make('azione')
                        ->label('Azione')
                        ->options(collect(AzioneDellAvviso::cases())
                            ->mapWithKeys(fn (AzioneDellAvviso $a): array => [$a->value => $a->etichetta()]))
                        ->default(AzioneDellAvviso::Dismiss->value)
                        ->required()
                        ->live()
                        ->native(false),

                    TextInput::make('url')
                        ->label('Indirizzo da aprire')
                        ->url()
                        ->maxLength(500)
                        ->visible(fn (callable $get): bool => $get('azione') === AzioneDellAvviso::Link->value)
                        ->required(fn (callable $get): bool => $get('azione') === AzioneDellAvviso::Link->value),

                    Select::make('promozione_id')
                        ->label('Promozione da attivare')
                        ->options(fn (): array => Promozione::query()->orderByDesc('id')->pluck('nome', 'id')->all())
                        ->searchable()
                        /*
                         * ⛔ **L'attivazione avviene sul server**, dentro la
                         * risposta alla decisione: farla eseguire all'app
                         * vorrebbe dire lasciare che sia il telefono a dire
                         * «assegnami questa promozione».
                         */
                        ->helperText('La promozione viene attivata dal server quando la persona approva.')
                        ->visible(fn (callable $get): bool => $get('azione') === AzioneDellAvviso::Promo->value)
                        ->required(fn (callable $get): bool => $get('azione') === AzioneDellAvviso::Promo->value),
                ]),

            Section::make('A chi')
                ->description('Gli stessi destinatari di una promozione: si sommano fra loro, e le condizioni restringono.')
                ->schema([
                    ModuloDellaPromozione::bersagli(),
                    ModuloDellaPromozione::condizioni(),
                ]),

            Section::make('Quando, e come')
                ->columns(2)
                ->schema([
                    DateTimePicker::make('inizia_il')->label('Da')->seconds(false)->helperText('Vuoto: da subito.'),
                    DateTimePicker::make('scade_il')->label('Fino a')->seconds(false)->helperText('Vuoto: per sempre.'),

                    Toggle::make('anche_push')
                        ->label('Manda anche la notifica push')
                        ->default(true)
                        ->helperText('⏳ Parte davvero quando Firebase è configurato (DA-FARE 20).'),

                    Toggle::make('urgente')
                        ->label('Urgente: scavalca il tetto di frequenza')
                        ->default(false)
                        ->helperText('Di serie una persona riceve al massimo 4 avvisi al mese, uno ogni 24 ore. Un avviso di servizio non può aspettare.'),

                    Toggle::make('commerciale')
                        ->label('È pubblicità')
                        ->default(false)
                        ->disabled(fn (Get $get): bool => $get('azione') === 'promo')
                        ->dehydrated()
                        ->helperText(
                            'Chi ha spento le offerte dal profilo non lo vedrà. '
                            .'⚠️ Con azione «attiva una promozione» è acceso per forza: '
                            .'una offerta è pubblicità, e non si può dichiarare il contrario. '
                            .'⛔ E se l’avviso è anche «urgente» passa lo stesso: usare '
                            .'l’urgente per la pubblicità vuol dire scavalcare un rifiuto.'
                        ),

                    Toggle::make('attivo')
                        ->label('Acceso')
                        ->default(false)
                        ->helperText('Finché è spento non lo vede nessuno.'),
                ]),
        ]);
    }

    /**
     * 🆕 **A schede**, come il resto del pannello — 📌 *«le tabelle mi fanno
     * cagare»*.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    TextColumn::make('titolo')->weight('bold')->searchable(),

                    VoceDiScheda::make('Stato', TextColumn::make('stato')
                        ->label('Stato')
                        ->badge()
                        ->state(fn (Avviso $record): string => self::stato($record))
                        ->color(fn (Avviso $record): string => match (self::stato($record)) {
                            'Acceso' => 'success',
                            'Urgente' => 'danger',
                            'Scaduto' => 'gray',
                            default => 'warning',
                        })),

                    VoceDiScheda::make('Approva', TextColumn::make('azione')
                        ->label('Approva')
                        ->state(fn (Avviso $record): string => $record->azione->etichetta())),

                    /*
                     * 🚨 **I numeri stanno sulla scheda, non in una pagina
                     * separata**: *«il numero di chi dice di no è metà del
                     * motivo per cui si manda un avviso»*, e metà motivo non
                     * si va a cercare in un'altra schermata.
                     */
                    VoceDiScheda::make('Risposte', TextColumn::make('risposte')
                        ->label('Risposte')
                        ->state(fn (Avviso $record): string => self::risposte($record))),
                ])->space(1),
            ])
            ->actions([EditAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessun avviso')
            ->emptyStateDescription('Gli avvisi compaiono nell\'app come una modale, all\'apertura.');
    }

    /** Lo stato in una parola. ⚠️ L'ordine conta: uno spento non è «scaduto». */
    public static function stato(Avviso $avviso): string
    {
        return match (true) {
            ! $avviso->attivo => 'Spento',
            $avviso->scade_il !== null && $avviso->scade_il->isPast() => 'Scaduto',
            $avviso->urgente => 'Urgente',
            default => 'Acceso',
        };
    }

    /**
     * 👀 Visti, approvati, rifiutati.
     *
     * 💡 E **quanti lo prenderanno**, finché non l'ha visto nessuno: è il
     * numero che serve **prima** di accendere, e `ChiLaRiceve` lo sa già fare.
     */
    public static function risposte(Avviso $avviso): string
    {
        $visti = AvvisoDecisione::query()->where('avviso_id', $avviso->getKey())->whereNotNull('mostrato_il')->count();

        if ($visti === 0) {
            $quanti = app(ChiLaRiceve::class)->destinatari($avviso)->count();

            return "Non ancora visto · lo prenderebbero {$quanti}";
        }

        $si = AvvisoDecisione::query()->where('avviso_id', $avviso->getKey())->where('decisione', AvvisoDecisione::APPROVATO)->count();
        $no = AvvisoDecisione::query()->where('avviso_id', $avviso->getKey())->where('decisione', AvvisoDecisione::RIFIUTATO)->count();

        return "Visto da {$visti} · {$si} sì · {$no} no";
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAvvisi::route('/'),
            'create' => CreateAvviso::route('/nuovo'),
            'edit' => EditAvviso::route('/{record}/modifica'),
        ];
    }
}
