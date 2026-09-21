<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni;

use App\Enums\StatoPromozione;
use App\Enums\SuCosaVale;
use App\Enums\TipoBersaglio;
use App\Enums\TipoDiSconto;
use App\Enums\UserRole;
use App\Filament\God\Resources\Promozioni\Pages\CreatePromozione;
use App\Filament\God\Resources\Promozioni\Pages\EditPromozione;
use App\Filament\God\Resources\Promozioni\Pages\ListPromozioni;
use App\Models\Promozione;
use App\Models\Tenant;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 🎁 **Le promozioni** — 22/09/2026, fase F2 di `plan_UX_pannello.md`.
 *
 * 📌 *«promozioni e/o sconti attivabili con link o in automatico (per uno o più
 * utenti, per uno o più ruoli, per una o più palestre), e se deve essere a
 * termine o per sempre. Ovviamente, mi servirà anche una lista di promozioni
 * attive, sospese, disattivate, scadute e tutto quanto»*.
 *
 * ══ 🚨 QUI DENTRO SI DECIDE QUANTO PAGA LA GENTE ═════════════════════════
 *
 * Una riga sbagliata qui non dà errore: fa pagare meno (o niente) a qualcuno,
 * e lo si scopre dall'estratto conto. ⛔ Per questo la promozione **non si
 * cancella** — si disattiva: chi l'ha già usata ha una ricevuta che la nomina,
 * e quella ricevuta deve restare spiegabile.
 *
 * 💡 Il conto vero — cumulabilità, migliore fra le esclusive, mai sotto zero —
 * sta in `LePromozioni`, non qui: questa è solo la pagina che lo configura.
 */
class PromozioneResource extends Resource
{
    protected static ?string $model = Promozione::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $modelLabel = 'promozione';

    protected static ?string $pluralModelLabel = 'promozioni';

    // 🆕 F9.4 — 📌 *«qui andranno anche le promozioni»*: nel gruppo «Offerte su misura».
    protected static ?string $navigationLabel = 'Promozioni';

    protected static string|\UnitEnum|null $navigationGroup = 'Offerte su misura';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    /** ⛔ Si disattivano: le ricevute che le nominano devono restare spiegabili. */
    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $quante = Promozione::query()->spendibili()->count();

        return $quante === 0 ? null : (string) $quante;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('L\'offerta')
                ->columns(2)
                ->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(80)
                        ->helperText('Come la chiami tu, e come compare in ricevuta.'),

                    TextInput::make('codice')
                        ->label('Codice')
                        ->required()
                        ->maxLength(40)
                        ->default(fn (): string => Promozione::nuovoCodice())
                        ->unique(Promozione::class, 'codice', ignoreRecord: true)
                        ->helperText('È il link. Chi ce l\'ha può aprire l\'offerta: trattalo come una password.'),

                    Textarea::make('descrizione')
                        ->label('Cosa dice al cliente')
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Il testo della pagina che spiega l\'offerta, nel suo pannello.'),
                ]),

            Section::make('Lo sconto')
                ->columns(2)
                ->schema([
                    /*
                     * 🚨 Percentuale e importo non si mescolano: 20 è un quinto
                     * o venti centesimi, e indovinare vorrebbe dire sbagliare
                     * su un prezzo.
                     */
                    Select::make('tipo_sconto')
                        ->label('Tipo')
                        ->options(collect(TipoDiSconto::cases())
                            ->mapWithKeys(fn (TipoDiSconto $t) => [$t->value => $t->label()]))
                        ->default(TipoDiSconto::Percentuale->value)
                        ->required()
                        ->live()
                        ->native(false),

                    TextInput::make('valore')
                        ->label(fn (callable $get): string => $get('tipo_sconto') === TipoDiSconto::Importo->value
                            ? 'Quanto toglie (€)'
                            : 'Percentuale')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        /*
                         * ⚠️ Una percentuale sopra 100 non è uno sconto: è un
                         * rimborso. `LePromozioni` si ferma comunque a zero, ma
                         * il posto giusto per dirlo è qui.
                         */
                        ->maxValue(fn (callable $get): int => $get('tipo_sconto') === TipoDiSconto::Importo->value
                            ? 1_000_000
                            : 100)
                        ->helperText(fn (callable $get): string => $get('tipo_sconto') === TipoDiSconto::Importo->value
                            ? 'In centesimi: 500 = 5,00 €.'
                            : 'Da 1 a 100.'),

                    Select::make('su_cosa')
                        ->label('Vale su')
                        ->multiple()
                        ->options(SuCosaVale::options())
                        ->default([SuCosaVale::Canone->value, SuCosaVale::Gettoni->value, SuCosaVale::Consumo->value])
                        ->required()
                        ->helperText('Una promozione sui gettoni non deve scontare per sbaglio un abbonamento.'),

                    Toggle::make('cumulabile')
                        ->label('Cumulabile')
                        ->helperText(
                            'Si somma alle altre cumulabili, una dopo l\'altra: 30% + 20% fa 44%, non 50%. '
                            .'Spenta, vale da sola — e se conviene lei, esclude anche le altre.'
                        ),
                ]),

            Section::make('Quando e a chi')
                ->columns(2)
                ->schema([
                    Select::make('attivazione')
                        ->label('Come si attiva')
                        ->options([
                            'automatica' => 'Da sola, a chi rientra nel bersaglio',
                            'link' => 'Solo con il link',
                        ])
                        ->default('automatica')
                        ->required()
                        ->native(false),

                    Select::make('stato')
                        ->label('Stato')
                        ->options(collect(StatoPromozione::cases())
                            ->mapWithKeys(fn (StatoPromozione $s) => [$s->value => $s->label()]))
                        ->default(StatoPromozione::Attiva->value)
                        ->required()
                        ->native(false),

                    DateTimePicker::make('inizia_il')
                        ->label('Comincia il')
                        ->helperText('Vuoto: da subito.'),

                    DateTimePicker::make('scade_il')
                        ->label('Finisce il')
                        ->helperText('Vuoto: per sempre. ⚠️ Un\'offerta senza fine è un prezzo nuovo.'),

                    TextInput::make('massimo_usi')
                        ->label('Quante volte in tutto')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Vuoto: senza limite. Si conta quando il pagamento riesce, non ai clic.'),

                    /*
                     * 🎯 I bersagli: più di uno, e in **OR**. «Per i trainer e
                     * per Tizio» vuol dire chi è trainer **oppure** è Tizio.
                     */
                    Repeater::make('bersagli')
                        ->label('A chi')
                        ->relationship()
                        ->columnSpanFull()
                        ->defaultItems(1)
                        ->columns(3)
                        ->schema([
                            Select::make('tipo')
                                ->label('Tipo')
                                ->options(TipoBersaglio::options())
                                ->default(TipoBersaglio::Tutti->value)
                                ->required()
                                ->live()
                                ->native(false),

                            Select::make('ruolo')
                                ->label('Ruolo')
                                ->options(collect(UserRole::cases())
                                    ->mapWithKeys(fn (UserRole $r) => [$r->value => $r->label()]))
                                ->visible(fn (callable $get): bool => $get('tipo') === TipoBersaglio::Ruolo->value)
                                ->native(false),

                            Select::make('tenant_id')
                                ->label('Palestra')
                                ->options(fn (): array => Tenant::query()->palestre()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->visible(fn (callable $get): bool => $get('tipo') === TipoBersaglio::Palestra->value),

                            Select::make('user_id')
                                ->label('Persona')
                                ->options(fn (): array => User::query()
                                    ->withoutGlobalScopes()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->limit(200)
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->visible(fn (callable $get): bool => in_array(
                                    $get('tipo'),
                                    [TipoBersaglio::Utente->value, TipoBersaglio::AllieviDiTrainer->value],
                                    true,
                                )),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')
                    ->label('Offerta')
                    ->weight('bold')
                    ->description(fn (Promozione $p): string => $p->codice)
                    ->searchable(),

                TextColumn::make('valore')
                    ->label('Sconto')
                    ->state(fn (Promozione $p): string => $p->scrittoBene())
                    ->badge()
                    ->color('success'),

                TextColumn::make('su_cosa')
                    ->label('Vale su')
                    ->state(fn (Promozione $p): string => implode(', ', array_map(
                        static fn (string $v): string => SuCosaVale::from($v)->label(),
                        (array) $p->su_cosa,
                    )))
                    ->wrap(),

                /*
                 * 🚨 **Lo stato che si legge non è solo la colonna**: una
                 * promozione «attiva» ma scaduta o esaurita non si spende, e
                 * mostrarla verde sarebbe la cosa più fuorviante di questa
                 * pagina.
                 */
                TextColumn::make('stato')
                    ->label('Stato')
                    ->badge()
                    ->state(fn (Promozione $p): string => match (true) {
                        $p->stato !== StatoPromozione::Attiva => $p->stato->label(),
                        $p->eEsaurita() => 'Esaurita',
                        $p->eScaduta() => 'Scaduta',
                        ! $p->eNelPeriodo() => 'Non ancora',
                        default => 'Attiva',
                    })
                    ->color(fn (Promozione $p): string => $p->eSpendibile() ? 'success' : 'gray'),

                TextColumn::make('attivazione')
                    ->label('Come')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'link' ? 'Con link' : 'Automatica')
                    ->color(fn (string $state): string => $state === 'link' ? 'info' : 'gray'),

                TextColumn::make('usata_volte')
                    ->label('Usata')
                    ->state(fn (Promozione $p): string => $p->massimo_usi === null
                        ? (string) $p->usata_volte
                        : $p->usata_volte.' / '.$p->massimo_usi)
                    ->alignEnd(),

                TextColumn::make('scade_il')
                    ->label('Fino al')
                    ->dateTime('d/m/Y')
                    ->placeholder('per sempre')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stato')
                    ->label('Stato')
                    ->options(collect(StatoPromozione::cases())
                        ->mapWithKeys(fn (StatoPromozione $s) => [$s->value => $s->label()])),

                Filter::make('spendibili')
                    ->label('Solo quelle spendibili adesso')
                    ->query(fn (Builder $q): Builder => $q->spendibili()),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * 🔗 Il link da mandare. 📌 *«il link deve portare a una pagina
                 * che spiega l'offerta, ma nel pannello non sul sito»*.
                 */
                Action::make('link')
                    ->label('Copia il link')
                    ->icon('heroicon-m-link')
                    ->color('primary')
                    ->action(fn (Promozione $p) => Notification::make()
                        ->title('Il link dell\'offerta')
                        ->body(rtrim((string) config('app.url'), '/').'/admin/offerta/'.$p->codice)
                        ->success()
                        ->persistent()
                        ->send()),

                Action::make('sospendi')
                    ->label(fn (Promozione $p): string => $p->stato === StatoPromozione::Attiva ? 'Sospendi' : 'Riattiva')
                    ->icon(fn (Promozione $p): string => $p->stato === StatoPromozione::Attiva
                        ? 'heroicon-m-pause'
                        : 'heroicon-m-play')
                    ->color(fn (Promozione $p): string => $p->stato === StatoPromozione::Attiva ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (Promozione $p) => $p->forceFill([
                        'stato' => $p->stato === StatoPromozione::Attiva
                            ? StatoPromozione::Sospesa
                            : StatoPromozione::Attiva,
                    ])->save()),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessuna promozione')
            ->emptyStateDescription('Uno sconto qui cambia quello che paga la gente: si crea con calma.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromozioni::route('/'),
            'create' => CreatePromozione::route('/create'),
            'edit' => EditPromozione::route('/{record}/edit'),
        ];
    }
}
