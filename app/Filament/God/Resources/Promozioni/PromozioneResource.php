<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\GenereDiPromozione;
use App\Enums\StatoPromozione;
use App\Filament\God\Resources\Promozioni\Pages\CreatePromozione;
use App\Filament\God\Resources\Promozioni\Pages\EditPromozione;
use App\Filament\God\Resources\Promozioni\Pages\ListPromozioni;
use App\Filament\God\Resources\Promozioni\Schemas\ModuloDellaPromozione;
use App\Filament\Support\VoceDiScheda;
use App\Jobs\AvvisaLaPromozioneATutti;
use App\Jobs\ConsegnaLaPromozioneATutti;
use App\Models\Promozione;
use App\Services\Promozioni\ChiLaRiceve;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

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

    // 🆕 P1 — senza, Filament ricava l'indirizzo dal nome in inglese: `/god/promozioni/promoziones`.
    protected static ?string $slug = 'promozioni';

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['premi.pacchetto', 'premi.plan', 'bersagli.tenant', 'bersagli.user', 'condizioni']);
    }

    /** 🆕 P1 — i campi stanno in `ModuloDellaPromozione`: la creazione li usa a passi, qui a sezioni. */
    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(ModuloDellaPromozione::sezioni());
    }

    /**
     * 🆕 P1 — **a schede**, come il resto del pannello: 📌 *«le tabelle mi
     * fanno cagare, se hanno tante funzioni diventa un troiaio»*.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    TextColumn::make('nome')
                        ->label('Offerta')
                        ->weight('bold')
                        ->description(fn (Promozione $p): string => $p->genere->label().' · '.$p->codice)
                        ->searchable(['nome', 'codice']),

                    VoceDiScheda::make('Dà', TextColumn::make('premi_scritti')
                        ->label('Dà')
                        ->state(fn (Promozione $p): string => ucfirst($p->scrittoBene()))
                        ->wrap()),

                    VoceDiScheda::make('A chi', TextColumn::make('a_chi')
                        ->label('A chi')
                        // 🆕 P5 — e le condizioni, in AND.
                        ->state(fn (Promozione $p): string => ($p->bersagli->map->descrizione()->join(', ') ?: 'nessuno')
                            .($p->condizioni->isNotEmpty() ? ' — solo se '.$p->condizioni->map->descrizione()->join(' e ') : ''))
                        ->wrap()),

                    /*
                     * 🚨 **Lo stato che si legge non è solo la colonna**: una
                     * promozione «attiva» ma scaduta, esaurita o senza budget
                     * non si spende, e mostrarla verde sarebbe la cosa più
                     * fuorviante di questa pagina.
                     */
                    VoceDiScheda::make('Stato', TextColumn::make('stato')
                        ->label('Stato')
                        ->badge()
                        ->state(fn (Promozione $p): string => self::stato($p))
                        ->color(fn (Promozione $p): string => $p->eSpendibile() ? 'success' : 'gray')),

                    VoceDiScheda::make('Come', TextColumn::make('attivazione')
                        ->label('Come')
                        ->formatStateUsing(fn (ComeSiAttiva $state): string => $state->label())),

                    VoceDiScheda::make('Usata', TextColumn::make('usata_volte')
                        ->label('Usata')
                        ->state(fn (Promozione $p): string => $p->massimo_usi === null
                            ? (string) $p->usata_volte
                            : $p->usata_volte.' / '.$p->massimo_usi)),

                    VoceDiScheda::make('Fino al', TextColumn::make('scade_il')
                        ->label('Fino al')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('per sempre')),
                ]),
            ])
            ->filters([
                SelectFilter::make('genere')
                    ->label('Cosa promuove')
                    ->options(GenereDiPromozione::options()),

                SelectFilter::make('stato')
                    ->label('Stato')
                    ->options(collect(StatoPromozione::cases())
                        ->mapWithKeys(fn (StatoPromozione $s) => [$s->value => $s->label()])),

                Filter::make('spendibili')
                    ->label('Solo quelle spendibili adesso')
                    ->query(fn (Builder $query): Builder => $query->spendibili()),

                /*
                 * 🆕 P7 — opzione 39: **gli stati che non sono una colonna**.
                 * ⚠️ «Esaurite» qui guarda solo il tetto degli usi: il budget si
                 * somma dalle assegnazioni e non sta in una `where`. Sulla scheda
                 * lo stato è giusto comunque (`stato()` guarda anche il budget).
                 */
                SelectFilter::make('come_sta')
                    ->label('Come sta')
                    ->options([
                        'scadute' => 'Scadute',
                        'non_ancora' => 'Non ancora cominciate',
                        'esaurite' => 'Esaurite (usi)',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'scadute' => $query->whereNotNull('scade_il')->where('scade_il', '<=', now()),
                        'non_ancora' => $query->whereNotNull('inizia_il')->where('inizia_il', '>', now()),
                        'esaurite' => $query->whereNotNull('massimo_usi')->whereColumn('usata_volte', '>=', 'massimo_usi'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    // 🆕 P7 — «Apri»: la pagina ha i numeri e chi l'ha presa, non solo il modulo.
                    ->label('Apri')
                    // Come gli altri tasti delle schede: bordati, e la griglia di pannello-a-schede.css li allarga.
                    ->button()
                    ->outlined()
                    ->color('gray'),

                /*
                 * 🔗 Il link da mandare. 📌 *«il link deve portare a una pagina
                 * che spiega l'offerta, ma nel pannello non sul sito»*. 🆕 P1 —
                 * si copia, come nella pagina degli inviti, invece di comparire
                 * in una notifica da ricopiare a mano.
                 */
                Action::make('link')
                    ->button()
                    ->outlined()
                    ->label('Copia il link')
                    ->icon('heroicon-m-clipboard-document')
                    ->color('gray')
                    ->alpineClickHandler(fn (Promozione $p): string => 'window.navigator.clipboard.writeText('
                        .Js::from(self::link($p))
                        .'); $tooltip(\'Copiato\', { theme: $store.theme, timeout: 2000 })'),

                /*
                 * 🆕 🚀 P2 — **«Esegui»**: i regali a tutti i destinatari, adesso
                 * (opzione 31). In coda, perché possono essere migliaia.
                 * ⚠️ Ogni volta è un giro nuovo: chi l'ha già ricevuta in un giro
                 * precedente la riceve di nuovo, se il massimo per persona lo
                 * permette — e la finestra lo dice prima.
                 */
                Action::make('esegui')
                    ->button()
                    ->outlined()
                    ->label('Esegui')
                    ->icon('heroicon-m-rocket-launch')
                    ->color('success')
                    ->visible(fn (Promozione $p): bool => $p->attivazione === ComeSiAttiva::Massa && $p->haRegali())
                    ->disabled(fn (Promozione $p): bool => ! $p->eSpendibile())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Promozione $p): string => "Consegnare «{$p->nome}» a tutti i destinatari?")
                    ->modalDescription(fn (Promozione $p): string => 'Arriva adesso: '.$p->scrittoBene().'. '
                        .($p->massimo_per_persona === null
                            ? 'Senza massimo per persona: chi l\'ha già ricevuta la riceve di nuovo.'
                            : 'Chi l\'ha già ricevuta '.$p->massimo_per_persona.' volte resta fuori.')
                        .($p->eseguita_il !== null ? ' Ultimo giro: '.$p->eseguita_il->format('d/m/Y H:i').'.' : ''))
                    ->modalSubmitActionLabel('Esegui')
                    ->action(function (Promozione $p): void {
                        ConsegnaLaPromozioneATutti::dispatch($p->getKey());

                        Notification::make()
                            ->title('Consegna partita')
                            ->body('I regali arrivano nei prossimi secondi. Le persone raggiunte si contano nella promozione.')
                            ->success()
                            ->send();
                    }),

                /*
                 * 🆕 🔎 P5 — **Anteprima**: quante persone la prendono oggi, e le
                 * prime venti. 📌 Con bersagli, esclusioni e condizioni insieme
                 * è l'unico modo di sapere a chi arriva prima di premere «Esegui».
                 */
                Action::make('anteprima')
                    ->button()
                    ->outlined()
                    ->label('Anteprima')
                    ->icon('heroicon-m-users')
                    ->color('gray')
                    ->modalHeading(fn (Promozione $p): string => "A chi arriva «{$p->nome}», oggi")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->modalContent(fn (Promozione $p): HtmlString => self::anteprima($p)),

                /*
                 * 🆕 📣 P8 — **«Avvisa i destinatari»**: per gli sconti e le
                 * offerte col link, che non «arrivano» a nessuno da sole. Solo se
                 * la promozione ha almeno un canale.
                 */
                Action::make('avvisa')
                    ->button()
                    ->outlined()
                    ->label('Avvisa i destinatari')
                    ->icon('heroicon-m-megaphone')
                    ->color('gray')
                    ->visible(fn (Promozione $p): bool => (array) $p->avviso !== [] && $p->eSpendibile())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Promozione $p): string => "Avvisare chi può usare «{$p->nome}»?")
                    ->modalDescription('Parte a tutte le persone che la promozione prende oggi (vedi «Anteprima»), coi canali scelti. Mandarlo due volte vuol dire due avvisi.')
                    ->modalSubmitActionLabel('Avvisa')
                    ->action(function (Promozione $p): void {
                        AvvisaLaPromozioneATutti::dispatch($p->getKey());

                        Notification::make()->title('Avviso partito')->success()->send();
                    }),

                Action::make('sospendi')
                    ->button()
                    ->outlined()
                    // 🆕 P7 — una disattivata non si riattiva: è definitiva.
                    ->visible(fn (Promozione $p): bool => $p->stato !== StatoPromozione::Disattivata)
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

    /** Lo stato come si legge sulla scheda: la colonna, corretta da date, usi e budget. */
    public static function stato(Promozione $p): string
    {
        return match (true) {
            $p->stato !== StatoPromozione::Attiva => $p->stato->label(),
            $p->eEsaurita(), $p->eFuoriBudget() => 'Esaurita',
            $p->eScaduta() => 'Scaduta',
            ! $p->eNelPeriodo() => 'Non ancora',
            default => 'Attiva',
        };
    }

    /**
     * 🆕 P5 — il contenuto dell'anteprima: il numero e le prime venti persone.
     *
     * ⚠️ Conta **adesso**: chi si iscrive domani e rientra nei destinatari
     * domani ci sarà.
     */
    public static function anteprima(Promozione $p): HtmlString
    {
        $tutte = app(ChiLaRiceve::class)->destinatari($p);
        $quante = 0;
        $prime = [];

        foreach ($tutte as $utente) {
            $quante++;

            if (count($prime) < 20) {
                $prime[] = e($utente->name).' <span style="opacity:.6">'.e($utente->email).'</span>';
            }
        }

        $elenco = $prime === [] ? '' : '<ul style="margin-top:.75rem">'.implode('', array_map(
            static fn (string $riga): string => "<li>{$riga}</li>",
            $prime,
        )).'</ul>';

        return new HtmlString(
            '<p><strong>'.$quante.'</strong> '.($quante === 1 ? 'persona' : 'persone').' la prendono oggi.</p>'
            .$elenco
            .($quante > 20 ? '<p style="margin-top:.5rem;opacity:.6">…e altre '.($quante - 20).'.</p>' : ''),
        );
    }

    /** L'indirizzo che porta l'offerta, nel pannello. */
    public static function link(Promozione $p): string
    {
        return rtrim((string) config('app.url'), '/').'/admin/offerta/'.$p->codice;
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
