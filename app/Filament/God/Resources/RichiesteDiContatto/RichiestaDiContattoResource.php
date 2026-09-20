<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\RichiesteDiContatto;

use App\Enums\StatoDellaRichiesta;
use App\Enums\TipoDiRichiesta;
use App\Filament\God\Resources\RichiesteDiContatto\Pages\ListRichiesteDiContatto;
use App\Models\RichiestaDiContatto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * ✉️ **Le richieste arrivate dal modulo di contatto** — 21/09/2026.
 *
 * 📌 Il committente: *«tanto c'è scrivici. Mettici un modulo di contatto così
 * mi scrivono»*. ⚠️ «Mi scrivono» vuol dire che **qualcuno deve leggerle**: il
 * modulo senza questa pagina sarebbe una tabella che si riempie e basta.
 *
 * ── 🚨 Perché non si può creare né cancellare ─────────────────────────────
 *
 * ⛔ **Creare** una richiesta a mano vorrebbe dire scrivere a nome di qualcuno
 * che non ha scritto: la riga è la prova che una persona ha compilato un
 * modulo, e una prova che si può fabbricare non è una prova.
 *
 * ⛔ **Cancellare** toglie di mezzo l'unica traccia di un contatto — e dopo una
 * trattativa finita male è proprio quella che si va a cercare. 💡 Si chiude,
 * e le chiuse si nascondono con un filtro.
 *
 * 🚨 La cancellazione vera esiste comunque e sta altrove: chi esercita il
 * diritto all'oblio scrive, e si cancella la riga dal database con quello che
 * dice l'informativa (§3.12, 24 mesi). Un pulsante in elenco non è la strada:
 * sarebbe un clic distratto a un pixel da «chiudi».
 */
class RichiestaDiContattoResource extends Resource
{
    protected static ?string $model = RichiestaDiContatto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $modelLabel = 'richiesta';

    protected static ?string $pluralModelLabel = 'richieste';

    protected static ?string $navigationLabel = 'Richieste dal sito';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    /**
     * Il numero accanto alla voce di menù: quante **non** ha ancora letto
     * nessuno.
     *
     * 💡 È l'unico motivo per cui questa pagina si apre: senza il badge, una
     * richiesta arrivata di sabato la si scopre il mercoledì.
     */
    public static function getNavigationBadge(): ?string
    {
        $quante = RichiestaDiContatto::query()->nuove()->count();

        return $quante === 0 ? null : (string) $quante;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('stato')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (StatoDellaRichiesta $state): string => $state->label())
                    ->color(fn (StatoDellaRichiesta $state): string => $state->color()),

                TextColumn::make('tipo')
                    ->label('Chi')
                    ->badge()
                    ->formatStateUsing(fn (TipoDiRichiesta $state): string => $state->label())
                    ->color(fn (TipoDiRichiesta $state): string => $state->color()),

                TextColumn::make('nome')
                    ->label('Nome')
                    ->description(fn (RichiestaDiContatto $r): ?string => $r->organizzazione)
                    ->searchable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('telefono')
                    ->label('Telefono')
                    ->placeholder('—')
                    ->toggleable(),

                /*
                 * 💡 Una colonna sola per iscritti + trainer: è la dimensione
                 * del cliente, e serve a decidere quale telefonata fare per
                 * prima. Due colonne mezze vuote direbbero la stessa cosa
                 * occupando il doppio.
                 */
                TextColumn::make('iscritti')
                    ->label('Quanto è grande')
                    ->state(fn (RichiestaDiContatto $r): string => $r->quantoEGrande())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stato')
                    ->label('Stato')
                    ->options(collect(StatoDellaRichiesta::cases())
                        ->mapWithKeys(fn (StatoDellaRichiesta $s) => [$s->value => $s->label()]))
                    /*
                     * ⚠️ Di default **le chiuse non si vedono**: un elenco che
                     * cresce all'infinito smette di essere una coda di lavoro e
                     * diventa un archivio, e la richiesta nuova ci si perde
                     * dentro.
                     */
                    ->default(StatoDellaRichiesta::Nuova->value),

                SelectFilter::make('tipo')
                    ->label('Chi scrive')
                    ->options(collect(TipoDiRichiesta::cases())
                        ->mapWithKeys(fn (TipoDiRichiesta $t) => [$t->value => $t->label()]))
                    ->multiple(),
            ])
            ->recordActions([
                Action::make('leggi')
                    ->label('Leggi')
                    ->icon('heroicon-m-eye')
                    ->modalHeading(fn (RichiestaDiContatto $r): string => "{$r->nome} — {$r->tipo->label()}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->schema([
                        TextEntry::make('created_at')->label('Arrivata')->dateTime('d/m/Y H:i'),
                        TextEntry::make('email')->label('Email')->copyable(),
                        TextEntry::make('telefono')->label('Telefono')->placeholder('—'),
                        TextEntry::make('organizzazione')->label('Attività')->placeholder('—'),
                        TextEntry::make('iscritti')->label('Iscritti')->placeholder('non detto'),
                        TextEntry::make('trainer')->label('Trainer')->placeholder('non detto'),
                        TextEntry::make('messaggio')->label('Messaggio')->columnSpanFull(),
                        TextEntry::make('note_interne')
                            ->label('Note interne')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                /*
                 * 🚨 Stato e note **nella stessa azione**, e non due pulsanti.
                 * Chi prende in carico una richiesta scrive anche perché
                 * («richiamata, vuole 40 posti»): separarli vorrebbe dire due
                 * clic per una cosa sola, e la seconda metà non la fa nessuno.
                 */
                Action::make('lavora')
                    ->label('Aggiorna')
                    ->icon('heroicon-m-pencil-square')
                    ->modalHeading(fn (RichiestaDiContatto $r): string => "Aggiorna: {$r->nome}")
                    ->modalSubmitActionLabel('Salva')
                    ->fillForm(fn (RichiestaDiContatto $r): array => [
                        'stato' => $r->stato->value,
                        'note_interne' => $r->note_interne,
                    ])
                    ->schema([
                        Select::make('stato')
                            ->label('Stato')
                            ->options(collect(StatoDellaRichiesta::cases())
                                ->mapWithKeys(fn (StatoDellaRichiesta $s) => [$s->value => $s->label()]))
                            ->required()
                            ->native(false),

                        Textarea::make('note_interne')
                            ->label('Note interne')
                            ->rows(4)
                            ->helperText('Non le vede chi ha scritto: sono per te.')
                            ->columnSpanFull(),
                    ])
                    ->action(function (RichiestaDiContatto $r, array $data): void {
                        $stato = StatoDellaRichiesta::from($data['stato']);

                        $r->stato = $stato;
                        $r->note_interne = $data['note_interne'] ?? null;

                        /*
                         * 💡 La data si scrive **la prima volta** che esce da
                         * «nuova», e non si riscrive: dice quanto ci abbiamo
                         * messo a rispondere, e riscriverla la farebbe dire
                         * sempre «subito».
                         */
                        if ($stato !== StatoDellaRichiesta::Nuova && $r->presa_in_carico_il === null) {
                            $r->presa_in_carico_il = Carbon::now();
                        }

                        $r->save();
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessuna richiesta')
            ->emptyStateDescription('Qui arriva quello che scrivono dal modulo «Scrivici» del sito.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRichiesteDiContatto::route('/'),
        ];
    }
}
