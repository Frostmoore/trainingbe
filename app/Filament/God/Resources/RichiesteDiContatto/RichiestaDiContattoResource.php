<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\RichiesteDiContatto;

use App\Enums\StatoDellaRichiesta;
use App\Enums\TipoDiRichiesta;
use App\Filament\God\Resources\RichiesteDiContatto\Pages\ListRichiesteDiContatto;
use App\Filament\God\Resources\RichiesteDiContatto\Pages\ViewRichiestaDiContatto;
use App\Filament\Support\VoceDiScheda;
use App\Models\RichiestaDiContatto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
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
 * ══ 🆕 F9.3 — SCHEDE, LA PAGINA DELLA RICHIESTA, E LA RISPOSTA ════════════
 *
 * 📌 *«deve contenere tutte le richieste dal sito con timestamp, da chi è
 * stata fatta eccetera, insomma le colonne importanti»* · pagina della
 * richiesta: *«deve contenere il testo completo della richiesta · deve
 * permettermi di rispondere direttamente dal pannello»*.
 *
 * - elenco **a schede** (come il resto del pannello), con «Apri» e «Aggiorna»;
 * - `ViewRichiestaDiContatto`: tutto, il messaggio intero, le risposte date,
 *   e il tasto **«Rispondi»** (mail vera, e la riga in `risposte_alle_richieste`).
 *
 * ── 🚨 Perché non si può creare né cancellare ─────────────────────────────
 *
 * ⛔ **Creare** una richiesta a mano vorrebbe dire scrivere a nome di qualcuno
 * che non ha scritto: la riga è la prova che una persona ha compilato un
 * modulo, e una prova che si può fabbricare non è una prova.
 *
 * ⛔ **Cancellare** toglie di mezzo l'unica traccia di un contatto. 💡 Si
 * chiude, e le chiuse si nascondono con un filtro. La cancellazione vera la fa
 * `registri:pota` dopo 24 mesi (informativa §3.12), e porta via anche le
 * risposte.
 */
class RichiestaDiContattoResource extends Resource
{
    protected static ?string $model = RichiestaDiContatto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $modelLabel = 'richiesta';

    protected static ?string $pluralModelLabel = 'richieste';

    protected static ?string $navigationLabel = 'Richieste dal sito';

    protected static ?string $slug = 'richieste';

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
            ->recordUrl(fn (RichiestaDiContatto $record): string => static::getUrl('view', ['record' => $record]))
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    TextColumn::make('nome')
                        ->label('Nome')
                        ->weight('bold')
                        ->searchable()
                        ->description(fn (RichiestaDiContatto $r): string => $r->email),

                    VoceDiScheda::make('Arrivata', TextColumn::make('created_at')->label('Arrivata')->dateTime('d/m/Y H:i')->sortable()),

                    VoceDiScheda::make('Stato', TextColumn::make('stato')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (StatoDellaRichiesta $state): string => $state->label())
                        ->color(fn (StatoDellaRichiesta $state): string => $state->color())),

                    VoceDiScheda::make('Chi', TextColumn::make('tipo')
                        ->label('Chi')
                        ->badge()
                        ->formatStateUsing(fn (TipoDiRichiesta $state): string => $state->label())
                        ->color(fn (TipoDiRichiesta $state): string => $state->color())),

                    VoceDiScheda::make('Attività', TextColumn::make('organizzazione')->label('Attività')->placeholder('—')),

                    VoceDiScheda::make('Telefono', TextColumn::make('telefono')->label('Telefono')->placeholder('—')),

                    VoceDiScheda::make('Dimensioni', TextColumn::make('iscritti')
                        ->label('Dimensioni')
                        ->state(fn (RichiestaDiContatto $r): string => $r->quantoEGrande())),

                    // L'inizio del messaggio: per capire di cosa si tratta senza aprirla.
                    TextColumn::make('messaggio')
                        ->label('Messaggio')
                        ->limit(140)
                        ->color('gray')
                        ->searchable(),
                ]),
            ])
            ->filters([
                SelectFilter::make('stato')
                    ->label('Stato')
                    ->options(collect(StatoDellaRichiesta::cases())
                        ->mapWithKeys(fn (StatoDellaRichiesta $s) => [$s->value => $s->label()])),

                SelectFilter::make('tipo')
                    ->label('Chi scrive')
                    ->options(collect(TipoDiRichiesta::cases())
                        ->mapWithKeys(fn (TipoDiRichiesta $t) => [$t->value => $t->label()]))
                    ->multiple(),
            ])
            ->recordActions([
                Action::make('apri')
                    ->label('Apri e rispondi')
                    ->icon('heroicon-m-envelope-open')
                    ->button()
                    ->outlined()
                    ->url(fn (RichiestaDiContatto $record): string => static::getUrl('view', ['record' => $record])),

                static::aggiorna()->button()->outlined(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessuna richiesta')
            ->emptyStateDescription('Qui arriva quello che scrivono dal modulo «Scrivici» del sito.');
    }

    /** La pagina della richiesta: tutto, il messaggio intero, e le risposte date. */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Chi ha scritto')
                ->columns(3)
                ->schema([
                    TextEntry::make('nome')->label('Nome'),
                    TextEntry::make('email')->label('Email')->copyable(),
                    TextEntry::make('telefono')->label('Telefono')->placeholder('—')->copyable(),
                    TextEntry::make('organizzazione')->label('Attività')->placeholder('—'),
                    TextEntry::make('tipo')->label('Chi')->badge()
                        ->formatStateUsing(fn (TipoDiRichiesta $state): string => $state->label()),
                    TextEntry::make('iscritti')->label('Dimensioni')
                        ->state(fn (RichiestaDiContatto $record): string => $record->quantoEGrande()),
                    TextEntry::make('created_at')->label('Arrivata')->dateTime('d/m/Y H:i'),
                    TextEntry::make('stato')->label('Stato')->badge()
                        ->formatStateUsing(fn (StatoDellaRichiesta $state): string => $state->label())
                        ->color(fn (StatoDellaRichiesta $state): string => $state->color()),
                    TextEntry::make('presa_in_carico_il')->label('Presa in carico')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),

            Section::make('Il messaggio')
                ->schema([
                    TextEntry::make('messaggio')->hiddenLabel()->prose()
                        ->formatStateUsing(fn (string $state): string => nl2br(e($state)))
                        ->html(),
                ]),

            Section::make('Le risposte')
                ->description('Quello che hai mandato da qui, dalla più recente.')
                ->schema([
                    RepeatableEntry::make('risposte')
                        ->hiddenLabel()
                        ->placeholder('Nessuna risposta ancora.')
                        ->schema([
                            TextEntry::make('created_at')->label('Mandata il')->dateTime('d/m/Y H:i'),
                            TextEntry::make('mandata')->label('Mail')
                                ->formatStateUsing(fn (bool $state): string => $state ? 'partita' : 'non partita (posta non collegata)')
                                ->badge()
                                ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                            TextEntry::make('oggetto')->label('Oggetto')->columnSpanFull(),
                            TextEntry::make('testo')->label('Testo')->columnSpanFull()
                                ->formatStateUsing(fn (string $state): string => nl2br(e($state)))
                                ->html(),
                        ])
                        ->columns(2),
                ]),

            Section::make('Note interne')
                ->description('Non le vede chi ha scritto: sono per te.')
                ->collapsed()
                ->schema([
                    TextEntry::make('note_interne')->hiddenLabel()->placeholder('—'),
                ]),
        ]);
    }

    /** Stato e note: dall'elenco e dalla pagina, scritto una volta. */
    public static function aggiorna(): Action
    {
        return Action::make('lavora')
            ->label('Aggiorna')
            ->icon('heroicon-m-pencil-square')
            ->modalHeading(fn (RichiestaDiContatto $record): string => "Aggiorna: {$record->nome}")
            ->modalSubmitActionLabel('Salva')
            ->fillForm(fn (RichiestaDiContatto $record): array => [
                'stato' => $record->stato->value,
                'note_interne' => $record->note_interne,
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
                    ->helperText('Non le vede chi ha scritto: sono per te.'),
            ])
            ->action(function (RichiestaDiContatto $record, array $data): void {
                $stato = StatoDellaRichiesta::from($data['stato']);

                $record->stato = $stato;
                $record->note_interne = $data['note_interne'] ?? null;

                // La prima volta che esce da «nuova», si segna quando.
                if ($stato !== StatoDellaRichiesta::Nuova && $record->presa_in_carico_il === null) {
                    $record->presa_in_carico_il = Carbon::now();
                }

                $record->save();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRichiesteDiContatto::route('/'),
            'view' => ViewRichiestaDiContatto::route('/{record}'),
        ];
    }
}
