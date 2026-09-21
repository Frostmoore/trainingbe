<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\PacchettiGettoni;

use App\Filament\God\Resources\PacchettiGettoni\Pages\ListPacchettiGettoni;
use App\Models\PacchettoGettoni;
use BackedEnum;
use Database\Seeders\PacchettiGettoniSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * 🎟️ **I tagli di gettoni, e quanto costano** — 22/09/2026, fase F2.
 *
 * 📌 Il committente: *«mi serve una sezione per impostare i prezzi dei
 * pacchetti di token»*.
 *
 * ══ 🚨 CAMBIARE UN PREZZO QUI CAMBIA QUELLO CHE SI PAGA ═══════════════════
 *
 * Non è una tabella di configurazione: è **il listino**. Da qui leggono il
 * sito (`/prezzi/individuale`), la modale degli acquisti dell'app e `Cassa`,
 * che è quella che apre il pagamento su Stripe.
 *
 * 💡 E il prezzo lo decide **sempre il server**: il client dice *quale* taglio
 * vuole, mai quanto costa. ⛔ Altrimenti chiunque comprerebbe 5.000 gettoni per
 * un centesimo cambiando una riga della richiesta.
 *
 * ══ ⚠️ PERCHÉ UN TAGLIO NON SI CANCELLA ══════════════════════════════════
 *
 * Perché `ai_credit_movements` racconta acquisti fatti con quel codice.
 * Cancellarlo renderebbe illeggibile la storia di chi l'ha comprato: si
 * **sospende**, e sparisce dal listino restando in tabella.
 */
class PacchettoGettoniResource extends Resource
{
    protected static ?string $model = PacchettoGettoni::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $modelLabel = 'taglio di gettoni';

    protected static ?string $pluralModelLabel = 'gettoni';

    protected static ?string $navigationLabel = 'Prezzi dei gettoni';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    /** ⛔ Si sospendono, non si cancellano: ci punta la storia degli acquisti. */
    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Il taglio')
                ->description('Quello che il cliente vede e quello che paga.')
                ->columns(2)
                ->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(60)
                        ->helperText('Come lo chiama chi lo compra: «Prova», «Serie», «Blocco».'),

                    TextInput::make('nota')
                        ->label('Nota')
                        ->maxLength(120)
                        ->helperText('La riga sotto il nome: «per chi la usa ogni settimana».'),

                    /*
                     * 🚨 **I gettoni non si cambiano dopo la vendita**, e il
                     * campo lo impedisce: chi ha comprato «500» ha comprato
                     * 500, e riscriverlo a 400 riscriverebbe la storia di un
                     * acquisto già avvenuto.
                     */
                    TextInput::make('gettoni')
                        ->label('Quanti gettoni')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->disabled(fn (?PacchettoGettoni $record): bool => $record !== null)
                        ->dehydrated(fn (?PacchettoGettoni $record): bool => $record === null)
                        ->helperText('Non si cambia dopo: chi ha comprato questo taglio li ha già ricevuti.'),

                    /*
                     * 💰 In euro, e convertito con `round()`: `(int) (3.50 *
                     * 100)` in virgola mobile può fare 349.
                     */
                    TextInput::make('prezzo_cent')
                        ->label('Prezzo')
                        ->prefix('€')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->rules([Rule::numeric()])
                        ->formatStateUsing(fn (?int $state): ?string => $state === null
                            ? null
                            : number_format($state / 100, 2, '.', ''))
                        ->dehydrateStateUsing(fn (?string $state): int => (int) round(((float) ($state ?? 0)) * 100))
                        ->helperText('IVA inclusa, come tutto il listino.'),

                    Toggle::make('attivo')
                        ->label('In vendita')
                        ->helperText('Spento: sparisce dal sito e dall\'app, ma resta qui.'),

                    TextInput::make('ordine')
                        ->label('Ordine')
                        ->numeric()
                        ->default(0)
                        ->helperText('In che ordine si mostrano, dal più piccolo.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')
                    ->label('Taglio')
                    ->weight('bold')
                    ->description(fn (PacchettoGettoni $p): ?string => $p->nota),

                TextColumn::make('gettoni')
                    ->label('Gettoni')
                    ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('prezzo_cent')
                    ->label('Prezzo')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 100, 2, ',', '.').' €')
                    ->weight('bold')
                    ->sortable()
                    ->alignEnd(),

                /*
                 * 💡 **Il prezzo per gettone è il numero che fa scegliere**, e
                 * qui serve a noi per vedere se la scala ha ancora senso: se un
                 * taglio grande costa più di uno piccolo, l'ha rotta qualcuno
                 * cambiando un prezzo.
                 */
                TextColumn::make('per_gettone')
                    ->label('A gettone')
                    ->state(fn (PacchettoGettoni $p): string => number_format($p->centPerGettone(), 2, ',', '.').' cent')
                    ->badge()
                    ->color('gray')
                    ->alignEnd(),

                IconColumn::make('attivo')
                    ->label('In vendita')
                    ->boolean(),

                /*
                 * 🚨 Dice chi comanda su questa riga: se è stata toccata a
                 * mano, il seeder del deploy non la riscrive più.
                 */
                IconColumn::make('modificato_a_mano')
                    ->label('Fuori dal seme')
                    ->boolean()
                    ->tooltip('Toccato dal pannello: il deploy non lo riporta più indietro'),
            ])
            ->defaultSort('ordine')
            ->headerActions([
                CreateAction::make()
                    ->label('Aggiungi un taglio')
                    ->mutateDataUsing(function (array $data): array {
                        $data['codice'] = PacchettiGettoniSeeder::codicePerNuovo((int) $data['gettoni']);
                        $data['modificato_a_mano'] = true;

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    /*
                     * 🚨 **Chi tocca un prezzo lo toglie dal seme.** Da quel
                     * momento il seeder del deploy lo lascia stare: senza
                     * questa riga, il primo deploy riporterebbe indietro il
                     * prezzo appena cambiato, in silenzio.
                     */
                    ->mutateDataUsing(function (array $data): array {
                        $data['modificato_a_mano'] = true;

                        return $data;
                    })
                    ->after(fn () => Notification::make()
                        ->title('Prezzo aggiornato')
                        ->body('Vale da adesso, sul sito e nell\'app. Chi ha già comprato non è toccato.')
                        ->success()
                        ->send()),

                Action::make('sospendi')
                    ->label(fn (PacchettoGettoni $p): string => $p->attivo ? 'Sospendi' : 'Rimetti in vendita')
                    ->icon(fn (PacchettoGettoni $p): string => $p->attivo ? 'heroicon-m-pause' : 'heroicon-m-play')
                    ->color(fn (PacchettoGettoni $p): string => $p->attivo ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (PacchettoGettoni $p): string => $p->attivo
                        ? 'Sparisce dal sito e dall\'app. Chi l\'ha già comprato tiene i suoi gettoni.'
                        : 'Torna in vendita sul sito e nell\'app.')
                    ->action(fn (PacchettoGettoni $p) => $p->forceFill([
                        'attivo' => ! $p->attivo,
                        'modificato_a_mano' => true,
                    ])->save()),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nessun taglio')
            ->emptyStateDescription('Il seme sta in config/listino.php: `php artisan db:seed --class=PacchettiGettoniSeeder`.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPacchettiGettoni::route('/'),
        ];
    }
}
