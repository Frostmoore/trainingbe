<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Tenants\Tables;

use App\Enums\AuditAction;
use App\Enums\PlanKind;
use App\Enums\TenantStatus;
use App\Filament\God\Resources\Tenants\TenantResource;
use App\Filament\Support\MandaIlReset;
use App\Filament\Support\VoceDiScheda;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\ContoDegliIscritti;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * L'elenco delle palestre.
 *
 * Le colonne rispondono alle domande che ci si fa davvero guardandolo: quante
 * persone ci sono dentro, l'abbonamento è attivo, e qual è il codice da dettare
 * al telefono a chi chiama.
 */
class TenantsTable
{
    /**
     * 🧮 **Quanti iscritti ha ogni palestra**, chiesto una volta sola.
     *
     * ⚠️ È una colonna che si disegna per ogni riga: senza memoria, una query
     * per riga. 💡 Il conteggio resta quello di `ContoDegliIscritti`, che è
     * anche quello che finisce in fattura — due conti diversi qui vorrebbero
     * dire mostrare un prezzo e addebitarne un altro.
     *
     * @var array<int, int>|null
     */
    private static ?array $iscritti = null;

    /** Quanto paga questa palestra **questo mese**, già scritto in euro. */
    private static function quantoPaga(Tenant $palestra): string
    {
        $piano = $palestra->abbonamentoAttivo?->plan;

        if ($piano === null) {
            return '—';
        }

        self::$iscritti ??= app(ContoDegliIscritti::class)->perOgniPalestra();

        $cent = $piano->quantoCostaAlMese(self::$iscritti[$palestra->getKey()] ?? 0);

        $euro = number_format($cent / 100, 2, ',', '.').' €';

        /*
         * 💡 Su un piano a consumo il numero **cambia ogni mese**: dirlo evita
         * la telefonata «ma perché adesso è diverso?».
         */
        return $piano->aConsumo() ? $euro.' (a consumo)' : $euro;
    }

    public static function configure(Table $table): Table
    {
        return $table
            /*
             * ══ 🃏 SCHEDE, NON RIGHE — 09/09/2026 ════════════════════════
             *
             * 📌 *«fai tutto con cards. Stessi elementi»*. Il perché per esteso
             * sta in `UsersTable`; qui vale la stessa cosa, e in più il colore
             * della palestra in una scheda **serve davvero**: in tabella era una
             * pallina in una colonna senza titolo, qui è il primo segno che
             * distingue una palestra dall'altra a colpo d'occhio.
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
            /*
             * 🆕 **Si apre la scheda** — 22/09/2026, fase F3.
             *
             * ⛔ Qui c'era `recordUrl(null)`, e il motivo era buono: toccando
             * la scheda ci si ritrovava dentro un **modulo di modifica** senza
             * averlo chiesto. 💡 Adesso ci si ritrova dentro una pagina che si
             * **guarda**, e il modulo resta dietro il suo pulsante.
             */
            ->recordUrl(fn (Tenant $r): string => TenantResource::getUrl('view', ['record' => $r]))
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    /*
                     * Il colore accanto al nome, non su una riga sua: da solo
                     * sarebbe una pallina sospesa che non si capisce cosa
                     * qualifichi. Qui e' il titolo della scheda, e come il nome
                     * degli utenti non porta etichetta.
                     */
                    Split::make([
                        ColorColumn::make('color_primary')
                            ->label('')
                            ->tooltip('Colore principale')
                            ->grow(false),

                        TextColumn::make('name')
                            ->label('Palestra')
                            ->searchable()
                            ->sortable()
                            ->description(fn (Tenant $r): string => $r->slug)
                            ->weight('bold'),
                    ]),

                    /*
                     * ⛔ **Il «Codice» non c'è più** — 21/09/2026, fase F1.
                     *
                     * 📌 Il committente: *«il codice palestra non ha più senso
                     * di esistere, gli inviti si fanno con il link di invito.
                     * Togliamo proprio tutto il discorso dei codici»*.
                     *
                     * ✅ E con F11 (24/09) la colonna non c'è più nemmeno in
                     * tabella: né registrazione, né accesso, né app.
                     */

                    VoceDiScheda::make('Stato', TextColumn::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (TenantStatus $state): string => $state->label())
                        ->color(fn (TenantStatus $state): string => $state->color())),

                    /*
                     * ══ 🚨 IL PIANO VERO, NON QUELLO DI `tenants.plan` ══════
                     *
                     * 📌 Il committente: *«I piani devono avere i nomi veri»*.
                     *
                     * ⛔ Qui c'era `tenants.plan`: una colonna **legacy** con
                     * tre valori inventati (`starter`, `pro`, `enterprise`) che
                     * **non hanno niente a che vedere** con l'abbonamento. Il
                     * piano vero sta in `plan_subscriptions → plans`, ed è
                     * quello che decide posti, AI e fattura.
                     *
                     * 🚨 Quindi questa pagina mostrava, per ogni palestra, **un
                     * piano che non esiste**. Nessun errore: un badge grigio
                     * con dentro una parola.
                     */
                    VoceDiScheda::make('Piano', TextColumn::make('abbonamentoAttivo.plan.name')
                        ->label('Piano')
                        ->badge()
                        ->color(fn (Tenant $r): string => $r->abbonamentoAttivo === null ? 'danger' : 'success')
                        ->placeholder('nessun abbonamento')),

                    /*
                     * 💡 **Quanto paga, a colpo d'occhio** — 📌 *«Mi serve di
                     * sapere al volo quanto paga»*.
                     *
                     * ⚠️ Un piano **a consumo** ha `price_cents = 0`: leggere
                     * quella colonna direbbe «gratis» sul piano più caro del
                     * listino. Si passa da `Plan::quantoCostaAlMese()`, che la
                     * domanda la fa una volta sola e nel modo giusto.
                     */
                    VoceDiScheda::make('Paga', TextColumn::make('quanto_paga')
                        ->label('Paga')
                        ->state(fn (Tenant $r): string => self::quantoPaga($r))
                        ->weight('bold')),

                    /*
                     * 📌 *«Mi serve di sapere al volo quando gli scade
                     * l'abbonamento e se si auto-rinnova»*.
                     *
                     * ⚠️ `ends_at = null` vuol dire **«non scade»**, ed è la
                     * convenzione delle palestre: scriverci «scaduto» sarebbe
                     * l'errore opposto a quello che si sta correggendo.
                     */
                    VoceDiScheda::make('Scadenza', TextColumn::make('abbonamentoAttivo.ends_at')
                        ->label('Scade')
                        ->dateTime('d/m/Y')
                        ->placeholder('non scade')
                        ->description(fn (Tenant $r): ?string => $r->abbonamentoAttivo === null
                            ? null
                            : ($r->abbonamentoAttivo->rinnova ? 'si rinnova da solo' : 'non si rinnova'))
                        ->color(fn (Tenant $r): string => $r->abbonamentoAttivo?->rinnova ? 'gray' : 'warning')),

                    /*
                     * ⏳ **Promo: segnaposto fino alla fase F2.** 📌 *«Semmai
                     * qui mi ci metti un campo "Promo" dove si vedranno le
                     * promozioni attive per questa palestra»*.
                     *
                     * 💡 Sta qui vuoto e non "dopo": così il posto è già
                     * deciso, e chi farà F2 non deve rifare la tabella.
                     */
                    VoceDiScheda::make('Promo', TextColumn::make('promo')
                        ->label('Promo')
                        ->state('—')
                        ->color('gray')
                        ->tooltip('Le promozioni arrivano con la fase F2')),

                    // Conteggi via `withCount` nella query: senza, ogni scheda
                    // farebbe la sua query e l'elenco ne sparerebbe una per
                    // palestra.
                    VoceDiScheda::make('Utenti', TextColumn::make('users_count')
                        ->label('Utenti')
                        ->counts('users')
                        ->badge()
                        ->color('gray')
                        ->sortable()),

                    /*
                     * ⛔ **«Prova fino al» non c'è più** — 📌 *«non serve a
                     * nulla e non esiste»*.
                     *
                     * ⚠️ `tenants.trial_ends_at` **non è del tutto morta**:
                     * `Tenant::isActive()` la legge ancora per i tenant in
                     * stato `trial`. 💡 Ma nessuno mette più niente in prova —
                     * zero righe valorizzate — e mostrare una colonna sempre
                     * vuota è rumore.
                     */

                    VoceDiScheda::make('Creata', TextColumn::make('created_at')
                        ->label('Creata')
                        ->date('d/m/Y')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true)),
                ])->space(3),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(collect(TenantStatus::cases())
                        ->mapWithKeys(fn (TenantStatus $s) => [$s->value => $s->label()])),

                /*
                 * 🚨 Si filtra per il piano **vero**, quello dell'abbonamento
                 * in corso. ⛔ Il filtro di prima leggeva `tenants.plan`: le
                 * tre voci inventate.
                 */
                SelectFilter::make('piano')
                    ->label('Piano')
                    ->options(fn (): array => Plan::query()
                        ->diTipo(PlanKind::Gym)
                        ->orderBy('price_cents')
                        ->pluck('name', 'code')
                        ->all())
                    ->query(fn (Builder $q, array $data): Builder => blank($data['value'] ?? null)
                        ? $q
                        : $q->whereHas(
                            'abbonamentoAttivo.plan',
                            fn (Builder $p): Builder => $p->where('code', $data['value']),
                        )),

                Filter::make('senza_abbonamento')
                    ->label('Senza abbonamento')
                    ->query(fn (Builder $q): Builder => $q->whereDoesntHave('abbonamentoAttivo')),

                TrashedFilter::make()->label('Cancellate'),
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
                EditAction::make()
                    ->button()
                    ->outlined(),

                /*
                 * 🔑 **Il reset del titolare** — 22/09/2026, fase F3.6.
                 *
                 * 📌 *«mi serve un tasto per mandare il reset password»*, anche
                 * qui. ⚠️ Ma **una palestra non ha una password**: ce l'ha la
                 * persona che la amministra. 💡 Chi sia, e perché proprio lei,
                 * lo decide `Tenant::aChiScrivere()`.
                 *
                 * ⛔ Su una palestra senza titolare il pulsante **sparisce**
                 * invece di mandare il link a un iscritto qualsiasi.
                 */
                MandaIlReset::make(
                    fn (Tenant $r): ?User => $r->aChiScrivere(),
                    'reset_del_titolare',
                )->label('Reset del titolare'),

                Action::make('sospendi')
                    ->button()
                    ->outlined()
                    ->label(fn (Tenant $r): string => $r->status === TenantStatus::Suspended ? 'Riattiva' : 'Sospendi')
                    ->icon(fn (Tenant $r): string => $r->status === TenantStatus::Suspended
                        ? 'heroicon-m-play'
                        : 'heroicon-m-pause')
                    ->color(fn (Tenant $r): string => $r->status === TenantStatus::Suspended ? 'success' : 'warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Tenant $r): string => $r->status === TenantStatus::Suspended
                        ? 'Gli iscritti torneranno a entrare dall\'app e lo staff dal pannello.'
                        : 'Nessuno di questa palestra potrà più entrare, né dall\'app né dal pannello. '
                          .'I dati restano al loro posto.')
                    // Sospendere una palestra chiude fuori tutti i suoi utenti in
                    // un colpo solo: e' fra le azioni per cui, il giorno dopo,
                    // qualcuno chiedera' chi e' stato. Percio' finisce nel
                    // registro (B10.2).
                    ->action(function (Tenant $r): void {
                        $riattiva = $r->status === TenantStatus::Suspended;

                        $r->update([
                            'status' => $riattiva ? TenantStatus::Active : TenantStatus::Suspended,
                        ]);

                        app(AuditLogger::class)->log(
                            $riattiva ? AuditAction::TenantReactivated : AuditAction::TenantSuspended,
                            $r,
                            ['utenti_coinvolti' => $r->users()->count()],
                            tenant: $r,
                        );
                    }),

                /*
                 * ══ 🗑️ IL CESTINO — 22/09/2026, fase F3 ═══════════════════
                 *
                 * 📌 Il committente: *«mi serve anche un tasto per rimuovere
                 * proprio la palestra, però non deve essere davvero cancellata,
                 * deve andare in una specie di "cestino" (rimozione logica) ma
                 * devo poterle ripristinare quando voglio»*.
                 *
                 * 💡 `Tenant` usa già `SoftDeletes`: qui si aggiunge il gesto,
                 * non il meccanismo.
                 *
                 * ⚠️ **Non è la stessa cosa di «sospendi»**, e il pannello deve
                 * farlo sentire: sospesa = nessuno entra, ma è lì e si vede;
                 * nel cestino = sparisce dagli elenchi e dai conti. ⛔ Due
                 * pulsanti che sembrano uguali portano a premere quello
                 * sbagliato.
                 *
                 * 🚨 **Niente conservazione a termine**: 📌 *«no no, se non li
                 * devo conservare non li conserviamo»*. Il cestino serve a
                 * tornare indietro da un errore, non a tenere i dati dieci
                 * anni. I documenti contabili hanno la loro regola, e stanno
                 * altrove.
                 */
                DeleteAction::make()
                    ->button()
                    ->outlined()
                    ->label('Cestina')
                    ->modalHeading(fn (Tenant $r): string => "Cestina {$r->name}")
                    ->modalDescription(
                        'Sparisce dagli elenchi e nessuno dei suoi entra più. '
                        .'⚠️ I dati restano: si ripristina quando vuoi, dal filtro «Cancellate».'
                    )
                    ->successNotificationTitle('Palestra cestinata')
                    ->after(fn (Tenant $r) => app(AuditLogger::class)->log(
                        AuditAction::TenantSuspended,
                        $r,
                        ['gesto' => 'cestinata', 'utenti_coinvolti' => $r->users()->count()],
                        tenant: $r,
                    )),

                RestoreAction::make()
                    ->button()
                    ->outlined()
                    ->label('Ripristina')
                    ->successNotificationTitle('Palestra ripristinata')
                    ->after(fn (Tenant $r) => app(AuditLogger::class)->log(
                        AuditAction::TenantReactivated,
                        $r,
                        ['gesto' => 'ripristinata'],
                        tenant: $r,
                    )),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Nessuna palestra')
            ->emptyStateDescription('Creane una, e falle entrare gli iscritti con un link d\'invito.');
    }
}
