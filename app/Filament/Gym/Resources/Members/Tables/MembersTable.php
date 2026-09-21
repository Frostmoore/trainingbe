<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Tables;

use App\Enums\AuditAction;
use App\Filament\Support\VoceDiScheda;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\EsciDaUnaPalestra;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * L'elenco degli iscritti.
 *
 * Le colonne rispondono alle domande che si fa chi lavora in palestra: **chi non
 * viene piu'**, **chi non ha una scheda**, **chi sto seguendo io**. Un elenco di
 * nomi ed email non serve a niente che non si possa fare con un foglio di calcolo.
 */
class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * ══ 🃏 SCHEDE, NON RIGHE — 09/09/2026 ════════════════════════
             *
             * 📌 *«fai tutto con cards. Stessi elementi»*. Il perché per esteso
             * sta in `UsersTable`.
             *
             * 💡 Qui il guadagno è particolare: chi lavora in palestra guarda
             * questo elenco **dal telefono**, in sala, mentre parla con
             * qualcuno. ⛔ Una riga con cinque colonne su uno schermo da cinque
             * pollici è la definizione di illeggibile.
             */
            /*
             * == LA SCHEDA NON E' TUTTA CLICCABILE -- 09/09/2026 ==
             *
             * Il committente: "la card non deve essere tutta cliccabile, solo
             * il tasto modifica".
             *
             * In una TABELLA cliccare una riga e' un gesto che uno fa apposta.
             * In una SCHEDA piena di targhette e pulsanti diventa una trappola:
             * si tocca per leggere, o si sbaglia mira su un'azione, e ci si
             * ritrova dentro un modulo di modifica senza averlo chiesto.
             *
             * `recordUrl(null)` toglie il collegamento dall'intera area:
             * l'unico modo di entrare in modifica resta il suo pulsante.
             */
            ->recordUrl(null)
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    TextColumn::make('name')
                        ->label('Iscritto')
                        ->searchable()
                        ->sortable()
                        ->weight('bold')
                        ->description(fn (User $r): ?string => $r->email),

                    /*
                     * ══ ⛔ VIA «SEGUITO DA» — 23/09/2026, F5.6 ══════════════
                     *
                     * 📌 Il committente lo vuole fuori, e dall'altro lato
                     * sparisce «iscritti seguiti» dalla pagina del trainer
                     * (F5.9): l'assegnazione non si governa più dal pannello.
                     *
                     * 🚨 **Non serviva più a niente.** Dal 16/09 un trainer
                     * vede **tutta** la palestra, come già la vede nell'app: la
                     * colonna prometteva una divisione del lavoro che il
                     * pannello non applica più da nessuna parte. ⛔ E dopo R2
                     * nemmeno le schede passano di lì — non c'è più niente che
                     * dipenda da chi è assegnato a chi.
                     *
                     * ⚠️ **La tabella `member_trainer` resta**, e non è
                     * dimenticanza: la usa la chat, per sapere con chi può
                     * parlare un iscritto. Quello che sparisce è governarla da
                     * qui.
                     */

                    /*
                     * Qui c'era "Ultimo allenamento", e non e' stata tolta per
                     * ripulire: e' stata tolta perche' NON DEVE ESSERCI --
                     * FASE 11.6, 21/08/2026.
                     *
                     * Il committente, 16/08: "Niente, nemmeno se si allena".
                     * Gli allenamenti stanno sul telefono di chi li fa, e il
                     * server non li vede piu' -- non e' che non li mostriamo,
                     * non ce li ha.
                     *
                     * E' la stessa scelta gia' fatta per il peso (D9-bis) e per
                     * il diario alimentare: la palestra sa CHI E' ISCRITTO e
                     * COSA HA COMPRATO, non come vive.
                     *
                     * Per lo stesso motivo qui non c'e' la colonna "Peso":
                     * "il trainer non ha bisogno di vedere peso, altezza ed
                     * eta': puo' chiederli". Da S5.4 il peso non sta nemmeno
                     * piu' sul server.
                     */

                    VoceDiScheda::make('Attivo', TextColumn::make('is_active')
                        ->label('Attivo')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'attivo' : 'disattivato')
                        ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                        ->sortable()),

                    VoceDiScheda::make('Iscritto il', TextColumn::make('created_at')
                        ->label('Iscritto il')
                        ->date('d/m/Y')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true)),
                ])->space(3),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Attivo')
                    ->placeholder('Tutti')
                    ->trueLabel('Solo attivi')
                    ->falseLabel('Solo disattivati'),

                /*
                 * ⛔ **«Senza scheda pubblicata» è sparito** — 22/09/2026, F5.4.
                 *
                 * 🚨 Selezionava **tutti**: dopo R2 le schede non sono legate a
                 * nessuno sul server, quindi nessun iscritto ne ha una — il
                 * filtro rispondeva sempre «tutti quanti».
                 *
                 * ⚠️ È la stessa trappola del filtro qui sotto, che dava
                 * addirittura un errore SQL: un filtro che resta acceso dopo
                 * che la cosa che filtrava è sparita **non dice niente e non si
                 * lamenta**.
                 */

                /*
                 * 🆕 ⛔ Qui c'era «Non si allena da 30 giorni», su
                 * `workoutSessions` — una tabella che non esiste piu' dalla
                 * FASE 11.6: sceglierlo dava un errore SQL. E non deve tornare:
                 * 📌 *«Niente, nemmeno se si allena»* (16/08). Trovato il 19/09.
                 */

                TrashedFilter::make()->label('Cancellati'),
            ])
            /*
             * == LE AZIONI HANNO UN BORDO, E STANNO DUE PER RIGA ==
             *
             * Il committente: "i tasti azione mettine 2 per riga, altrimenti
             * non si capisce niente (mettili centrati e con un bordo)".
             *
             * Il bordo lo da' `->button()->outlined()` qui sotto, azione per
             * azione. La griglia a due colonne NON si puo' dire da qui:
             * Filament v5 non espone nessuna API per disporre le azioni, e la
             * regola sta in `resources/css/pannello-a-schede.css`.
             *
             * ATTENZIONE: se un giorno le azioni tornassero in fila, il primo
             * posto da guardare e' se il deploy ha eseguito `filament:assets` --
             * senza, quel foglio non esce e nulla lo segnala.
             */
            ->recordActions([
                /*
                 * 👁️ **«Scheda» al posto di «Modifica»** — F5.7.
                 *
                 * ⛔ La pagina di modifica non esiste più: la palestra non
                 * cambia l'identità né la password di una persona. 💡 Quello
                 * che si fa da dentro — sospendere, disiscrivere, regalare
                 * gettoni, mandare il link della password — sono azioni, non
                 * campi di un modulo.
                 */
                ViewAction::make()
                    ->label('Scheda')
                    ->button()
                    ->outlined(),
            ])
            /*
             * 🆕 🚨 **«Disiscrivi», non «Elimina»** — D4, 19/09/2026.
             *
             * ⛔ Qui c'era `DeleteBulkAction`: cancellava gli **account** di
             * tutte le persone selezionate. 📌 *«Una palestra deve poter AL
             * MASSIMO disiscrivere un allievo»*. 💡 Stessa strada della persona
             * che se ne va da sola (`EsciDaUnaPalestra`), una alla volta.
             */
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('disiscrivi')
                        ->label('Disiscrivi')
                        ->icon('heroicon-o-user-minus')
                        ->color('danger')
                        ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true)
                        ->requiresConfirmation()
                        ->modalHeading('Disiscrivere le persone selezionate?')
                        ->modalDescription('Escono dalla palestra e tornano nel loro spazio personale, con il loro account e i loro dati.')
                        ->modalSubmitActionLabel('Disiscrivi')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $persone): void {
                            foreach ($persone as $persona) {
                                $palestra = $persona->tenant_id;

                                app(EsciDaUnaPalestra::class)($persona);

                                app(AuditLogger::class)->log(
                                    AuditAction::IscrittoDisiscritto,
                                    $persona,
                                    ['email' => $persona->email],
                                    tenant: $palestra,
                                );
                            }
                        }),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Nessun iscritto')
            /*
             * ⛔ **Via il «codice della palestra»** — 23/09/2026, F5.6.
             *
             * 📌 *«il tasto codice palestra non ha senso, non esiste più il
             * codice palestra»* · *«Togliamo proprio tutto il discorso dei
             * codici delle palestre»*. 💡 L'unica strada è il **link
             * d'invito**: monouso, con scadenza, e revocabile uno per uno.
             */
            ->emptyStateDescription('Mandagli un link d\'invito: vale per una persona sola e scade dopo sette giorni.');
    }
}
