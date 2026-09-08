<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Tables;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\Stripe\Cassa;
use App\Support\Impersonation\Impersonator;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * L'elenco degli utenti di tutte le palestre.
 *
 * I filtri sono quelli che servono a chi fa supporto, nell'ordine in cui li
 * userebbe: **di quale palestra**, **che ruolo ha**, **e' ancora attivo**.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * ══ 🃏 SCHEDE, NON RIGHE — 09/09/2026 ════════════════════════
             *
             * 📌 Il committente: *«mi sono rotto il cazzo de ste tabelle, fanno
             * schifo su ogni tipo di schermo, fai tutto con cards. Stessi
             * elementi»*.
             *
             * ⛔ **Una tabella con otto colonne non ha una larghezza a cui stia
             * bene.** Su un telefono si accavalla, su un monitor largo lascia
             * metà schermo vuoto e allontana il nome dall'ultimo dato della sua
             * stessa riga: per leggere una persona bisogna attraversare tutto.
             *
             * ✅ **Stessi elementi, stesse azioni, stessi filtri.** `Stack` e
             * `Split` sono componenti di **impaginazione**: le colonne restano
             * quelle di prima, cambia dove finiscono.
             *
             * ⚠️ **Ricerca e ordinamento non si perdono**: Filament appiattisce
             * le colonne dentro i layout (`HasColumns::columns()`), quindi
             * `searchable()` e `sortable()` continuano a valere e la tendina
             * «ordina» compare da sola nella barra.
             *
             * 🚨 **Ma le ETICHETTE spariscono.** Dentro una scheda non c'è
             * nessuna intestazione di colonna: un `09/09/2026` da solo non dice
             * se è la data di iscrizione o dell'ultimo accesso. ⛔ Per questo
             * ogni valore che non si spiega da sé ha adesso un **prefisso** o
             * un'**icona**. Senza, sarebbe una tabella smontata invece che una
             * scheda.
             */
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            ->columns([
                Stack::make([
                    TextColumn::make('name')
                        ->label('Nome')
                        ->searchable()
                        ->sortable()
                        ->weight('bold')
                        ->description(fn (User $r): string => $r->username !== null ? '@'.$r->username : ''),

                    TextColumn::make('email')
                        ->label('Email')
                        ->searchable()
                        ->copyable()
                        ->copyMessage('Email copiata')
                        // 💡 L'icona sostituisce l'intestazione che nella scheda
                        // non c'è: una riga di testo con la @ dentro si riconosce,
                        // ma l'icona la trova l'occhio prima di leggere.
                        ->icon('heroicon-m-envelope')
                        ->color('gray'),

                    /*
                     * ⚠️ **Le due targhette stanno affiancate**, non impilate:
                     * la palestra e i ruoli sono la stessa domanda — «chi è
                     * questo dentro il prodotto» — e su righe separate una
                     * scheda diventa alta il doppio senza dire niente di più.
                     */
                    Split::make([
                        TextColumn::make('tenant.name')
                            ->label('Palestra')
                            ->placeholder('Piattaforma')
                            ->badge()
                            ->color(fn (?string $state): string => $state === null ? 'danger' : 'gray')
                            ->sortable(),

                        TextColumn::make('ruoli')
                            ->label('Ruoli')
                            ->badge()
                            ->separator(',')
                                // Non `->roles`: in modalita' teams la relazione e' filtrata
                                // sul tenant corrente e qui il contesto e' vuoto, quindi
                                // sarebbe vuota per tutti. Si legge la pivot direttamente.
                            ->getStateUsing(fn (User $r): array => static::ruoliDi($r))
                                // 💡 I due ruoli senza palestra restano `gray` come gli
                                // iscritti: il colore qui serve a far saltare all'occhio
                                // **chi ha poteri**, e loro non ne hanno sulla piattaforma.
                            ->color(fn (string $state): string => match ($state) {
                                UserRole::SuperAdmin->value => 'danger',
                                UserRole::GymAdmin->value => 'warning',
                                UserRole::Trainer->value, UserRole::FreeTrainer->value => 'info',
                                default => 'gray',
                            }),

                    ])->from('sm'),

                    /*
                 * 🚨 **`IconColumn` è diventato un testo, ed è l'unico elemento
                 * cambiato davvero.** In una tabella una spunta sotto la
                 * colonna «Attivo» si capisce; in una scheda una spunta da
                 * sola, senza intestazione, non dice **di cosa** sia la spunta.
                 * 💡 La targhetta dice la parola.
                 */
                    TextColumn::make('is_active')
                        ->label('Attivo')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'attivo' : 'disattivato')
                        ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                        ->sortable(),

                    TextColumn::make('last_login_at')
                        ->label('Ultimo accesso')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('mai')
                        // ⛔ Senza il prefisso questa data e quella di iscrizione
                        // sono due numeri identici in due righe vicine.
                        ->prefix('ultimo accesso: ')
                        ->size('xs')
                        ->color('gray')
                        ->sortable()
                        ->toggleable(),

                    /*
                 * La quota AI **effettiva** — 14/08/2026.
                 *
                 * 🚨 Non e' la colonna `ai_monthly_call_cap`, ed e' la ragione
                 * per cui c'e': quella colonna e' vuota per quasi tutti, e una
                 * tabella di caselle vuote farebbe concludere «nessuno ha
                 * quota» — il contrario del vero. Qui si legge cosa risponde la
                 * **catena intera** (`MemberAiQuota`).
                 *
                 * ⚠️ Costa una query per riga, percio' e' spenta di default:
                 * chi fa supporto la accende quando gli serve.
                 */
                    TextColumn::make('quota_ai')
                        ->label('Quota AI')
                        ->badge()
                        ->getStateUsing(fn (User $r): string => static::quotaDi($r))
                        ->color(fn (string $state): string => match (true) {
                            $state === 'AI spenta' => 'gray',
                            $state === 'illimitata' => 'danger',
                            str_starts_with($state, 'sua:') => 'warning',
                            default => 'gray',
                        })
                        ->toggleable(isToggledHiddenByDefault: true),

                    TextColumn::make('created_at')
                        ->label('Iscritto il')
                        ->date('d/m/Y')
                        ->prefix('iscritto il ')
                        ->size('xs')
                        ->color('gray')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ])->space(2),
            ])
            ->filters([
                /*
                 * 🚨 **Solo le palestre nella tendina** — F1.4.
                 *
                 * ⚠️ Qui il filtro `->palestre()` fa più che tenere corta una
                 * lista: senza, questa tendina diventerebbe **l'elenco in chiaro
                 * di tutte le persone iscritte** — il nome di un tenant
                 * personale è il nome e cognome di chi ci sta dentro — offerto
                 * per giunta con una ricerca incrementale.
                 *
                 * 💡 E non si perde niente: il filtro serviva a rispondere
                 * «mostrami gli utenti della palestra X». Per trovare **una**
                 * persona c'è già la ricerca per nome ed email, che è la strada
                 * naturale e non passa da qui.
                 */
                SelectFilter::make('tenant_id')
                    ->label('Palestra')
                    ->options(fn (): array => Tenant::query()->palestre()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                /*
                 * ⚠️ **Le voci si generano dall'enum, non si scrivono a mano** — F2.3.
                 *
                 * Qui c'erano quattro righe scritte a mano. Aggiungendo
                 * `FreeUser` e `FreeTrainer` sarebbero rimaste quattro: il
                 * filtro avrebbe continuato a funzionare, semplicemente senza
                 * i ruoli nuovi — e nessuno se ne sarebbe accorto, perché un
                 * filtro incompleto non da' errore, da' meno risultati.
                 *
                 * 💡 È lo stesso principio già applicato al `RoleSeeder`:
                 * `UserRole` è la fonte di verità, e aggiungere un ruolo deve
                 * significare toccare **un file solo**.
                 */
                SelectFilter::make('ruolo')
                    ->label('Ruolo')
                    ->options(fn (): array => collect(UserRole::cases())
                        ->mapWithKeys(fn (UserRole $r): array => [$r->value => $r->label()])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $ruolo = $data['value'] ?? null;

                        if ($ruolo === null) {
                            return $query;
                        }

                        if ($ruolo === 'super_admin') {
                            return $query->where('is_super_admin', true);
                        }

                        return $query->whereIn('id', DB::table('model_has_roles')
                            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                            ->where('roles.name', $ruolo)
                            ->select('model_has_roles.model_id'));
                    }),

                TernaryFilter::make('is_active')
                    ->label('Attivo')
                    ->placeholder('Tutti')
                    ->trueLabel('Solo attivi')
                    ->falseLabel('Solo disattivati'),

                Filter::make('mai_entrato')
                    ->label('Non ha mai fatto accesso')
                    ->query(fn (Builder $q): Builder => $q->whereNull('last_login_at')),

                TrashedFilter::make()->label('Cancellati'),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * «AI illimitata» — l'interruttore per le prove, 14/08/2026.
                 *
                 * ── 🚨 Perche' un'azione e non solo il campo nel modulo ────
                 *
                 * Il campo c'e' gia' (`UserForm`, sezione «Quota AI»), e da solo
                 * basterebbe. ⚠️ Ma il gesto vero e' «sblocca questi cinque
                 * amici perche' provino l'app», e farlo dal modulo vuol dire
                 * cinque volte: apri, scorri, scrivi `0` in due caselle
                 * ricordandosi che **zero vuol dire illimitato**, salva, torna
                 * indietro.
                 *
                 * 💡 Qui e' un tocco, e la convenzione controintuitiva
                 * (`0` = illimitato) resta dentro il codice invece di essere una
                 * cosa da ricordare a mano ogni volta.
                 *
                 * 🚨 **Ed e' un interruttore, non un pulsante**: sulla stessa
                 * riga toglie quello che ha messo. Una concessione che si da' e
                 * non si toglie dallo stesso posto e' una concessione che
                 * rimane accesa per sempre — su un ambiente di prova diventa la
                 * bolletta del mese dopo.
                 */
                /*
                 * ══ 🎁 «ABBONAMENTO ILLIMITATO» — 3b-H.10, 27/08/2026 ═══════
                 *
                 * 📌 *«mettimi anche l'opzione per dare a un utente abbonamento
                 * illimitato, cosi' mi posso abilitare e disabilitare quando
                 * voglio per fare i test e regalare abbonamenti a chi cazzo mi
                 * pare»*.
                 *
                 * ── ⚠️ NON E' LA STESSA COSA DI «AI ILLIMITATA» ─────────────
                 *
                 * | | Cosa sblocca |
                 * |---|---|
                 * | **AI illimitata** | le chiamate al modello, senza tetto |
                 * | **Abbonamento** | tutto cio' che sta dietro `eAbbonato()`: le schede oltre tre, le multiday, e il gate di 3b-I |
                 *
                 * 💡 Si sovrappongono solo in parte: `limiti_delle_schede.dart`
                 * si apre con **una delle due**. Ma la modale, il listino e il
                 * gate guardano `eAbbonato()`, che l'AI illimitata non tocca.
                 *
                 * ── 🚨 LA TRAPPOLA: L'ABBONAMENTO STA SUL TENANT ────────────
                 *
                 * `PlanSubscription` ha `tenant_id`, non `user_id`. ⛔ Su un
                 * iscritto di una **palestra** questo pulsante regalerebbe
                 * l'abbonamento a **tutta la palestra**, e nessuno se ne
                 * accorgerebbe finche' non arriva il conto delle chiamate.
                 *
                 * ✅ Per questo l'azione **c'e' solo sui tenant personali**, e
                 * su una palestra dice perche' non si puo' invece di sparire:
                 * un pulsante che manca fa cercare, uno spiegato fa capire.
                 */
                Action::make('abbonamento_regalato')
                    ->label(fn (User $r): string => self::haRegalo($r) ? 'Togli abbonamento' : 'Regala abbonamento')
                    ->icon('heroicon-m-gift')
                    ->color(fn (User $r): string => self::haRegalo($r) ? 'gray' : 'success')
                    ->visible(fn (User $r): bool => $r->tenant?->ePersonale() ?? false)
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $r): string => self::haRegalo($r)
                        ? "Togliere l'abbonamento a {$r->name}?"
                        : "Regalare l'abbonamento a {$r->name}?")
                    ->modalDescription(fn (User $r): string => self::haRegalo($r)
                        ? 'Tornera\' al piano gratuito. ⚠️ Se aveva pagato con Stripe, questo NON disdice niente la\': '
                          .'l\'addebito continua. Si toglie solo il regalo.'
                        : 'Abbonamento senza scadenza, gratuito per lui e a carico nostro. '
                          .'Sblocca le schede oltre tre, le multiday e tutto cio\' che e\' riservato agli abbonati. '
                          .'Finisce nel registro.')
                    ->modalSubmitActionLabel(fn (User $r): string => self::haRegalo($r) ? 'Togli' : 'Regala')
                    ->action(function (User $record): void {
                        $tenantId = $record->tenant?->getKey();

                        if ($tenantId === null) {
                            return;
                        }

                        $regalo = self::regaloDi($record);

                        if ($regalo !== null) {
                            $regalo->delete();
                        } else {
                            $piano = Plan::query()->where('code', Plan::PLUS)->first();

                            if ($piano === null) {
                                Notification::make()
                                    ->title('Il piano `plus` non esiste')
                                    ->body('Il listino non e\' stato seminato: non c\'e\' niente da regalare.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            PlanSubscription::withoutGlobalScopes()->create([
                                'tenant_id' => $tenantId,
                                'plan_id' => $piano->getKey(),

                                /*
                                 * ══ 🎁 UN MESE, E SI RINNOVA — 08/09/2026 ══
                                 *
                                 * 📌 Il committente: *«deve funzionare anche
                                 * con gli abbonamenti che regalo»*, del toggle
                                 * «Rinnova in automatico».
                                 *
                                 * ⛔ **Prima era `ends_at = null`**, cioe' «non
                                 * scade mai»: su una riga cosi' il toggle non
                                 * poteva governare niente — spegnerlo non
                                 * avrebbe cambiato nessuna data.
                                 *
                                 * 💡 Adesso un regalo e' **un mese che si
                                 * rinnova da solo**, come un abbonamento vero,
                                 * e a rinnovarlo e' `abbonamenti:rinnova-i-
                                 * regali`. ⚠️ Chi lo spegne se lo tiene fino
                                 * alla fine del mese in corso, e poi finisce.
                                 *
                                 * 🚨 **Il giorno in piu' non e' decorativo**:
                                 * e' lo stesso margine di Stripe. Un comando
                                 * che gira alle 3:40 non deve lasciare fuori
                                 * nessuno fra la mezzanotte e quell'ora.
                                 *
                                 * ⛔ La convenzione `ends_at = null` **resta**
                                 * per le palestre: quelle non passano di qui.
                                 */
                                'starts_at' => now(),
                                'ends_at' => now()->addMonth()->addDay(),

                                /*
                                 * ⛔ Niente id di Stripe: **e' il segno che
                                 * questo abbonamento non e' stato pagato**. Da
                                 * quello l'app capisce di non mostrare il
                                 * pulsante «gestisci», che aprirebbe una pagina
                                 * vuota.
                                 */
                                'stripe_subscription_id' => null,
                                'stripe_customer_id' => null,

                                // 🎁 Acceso: un regalo va avanti finche' non lo
                                // si toglie da qui, o non lo spegne chi lo ha
                                // ricevuto.
                                'rinnova' => true,
                            ]);
                        }

                        app(AuditLogger::class)->log(
                            AuditAction::AbbonamentoRegalato,
                            $record,
                            [
                                'email' => $record->email,
                                'da' => 'elenco utenti',
                                'ora' => $regalo !== null ? 'tolto' : 'regalato',
                            ],
                        );

                        Notification::make()
                            ->title($regalo !== null ? 'Abbonamento tolto' : 'Abbonamento regalato')
                            ->success()
                            ->send();
                    }),

                /*
                 * == DISDIRE UN ABBONAMENTO **PAGATO** -- 09/09/2026 ==
                 *
                 * Il committente: "dal pannello god devo poter anche togliere
                 * un abbonamento pagato con stripe quando voglio".
                 *
                 * -- PERCHE' E' UN PULSANTE A PARTE E NON LO STESSO --
                 *
                 * "Togli abbonamento" accanto NON tocca Stripe, e lo dice nella
                 * sua finestra: cancella la nostra riga e l'addebito continua.
                 * Fondere i due pulsanti avrebbe voluto dire un comando che a
                 * volte ferma un pagamento e a volte no, a seconda di com'e'
                 * fatta la riga sotto -- cioe' la cosa piu' pericolosa che un
                 * pannello di amministrazione possa avere.
                 *
                 * Due pulsanti, due conseguenze, due frasi diverse.
                 *
                 * -- COSA SUCCEDE DAVVERO --
                 *
                 * | Su Stripe        | l'abbonamento viene disdetto SUBITO      |
                 * | Da noi           | ends_at = adesso, senza aspettare il webhook |
                 * | Alla persona     | l'app dice "e' scaduto" al primo rientro |
                 * | I soldi versati  | NON tornano: il rimborso si fa da Stripe |
                 *
                 * Su una palestra colpisce TUTTI i suoi iscritti, perche'
                 * l'abbonamento sta sul tenant. La finestra lo dice con il
                 * numero di persone: un pannello che non quantifica il danno
                 * prima di farlo non sta chiedendo davvero una conferma.
                 */
                Action::make('disdici_pagato')
                    ->label('Disdici pagato')
                    ->icon('heroicon-m-credit-card')
                    ->color('danger')
                    ->visible(fn (User $r): bool => self::haPagato($r))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $r): string => "Disdire l'abbonamento pagato di {$r->name}?")
                    ->modalDescription(function (User $r): string {
                        $quanti = self::quantiNeUsano($r);

                        $avviso = $quanti > 1
                            ? "ATTENZIONE: questo abbonamento e' di una palestra e lo usano {$quanti} persone. Lo perderanno tutte. "
                            : '';

                        return $avviso
                            .'Viene disdetto SUBITO su Stripe: non ci saranno altri addebiti, e l\'accesso finisce adesso. '
                            .'Quello che ha gia\' pagato NON viene rimborsato: il rimborso si fa da Stripe, a mano. '
                            .'Finisce nel registro.';
                    })
                    ->modalSubmitActionLabel('Disdici su Stripe')
                    ->action(function (User $record): void {
                        try {
                            app(Cassa::class)->disdiciSubito($record);
                        } catch (\Throwable $e) {
                            report($e);

                            /*
                             * Il messaggio VERO, non uno generico: qui chi
                             * guarda e' un amministratore, e se Stripe ha
                             * risposto "no such subscription" deve leggerlo --
                             * e' l'unica informazione che gli dice cosa fare.
                             */
                            Notification::make()
                                ->title('Disdetta non riuscita')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        app(AuditLogger::class)->log(
                            AuditAction::AbbonamentoPagatoDisdetto,
                            $record,
                            ['email' => $record->email, 'da' => 'elenco utenti'],
                        );

                        Notification::make()
                            ->title('Abbonamento disdetto su Stripe')
                            ->body('Nessun altro addebito. I soldi gia\' pagati non sono stati rimborsati.')
                            ->success()
                            ->send();
                    }),

                Action::make('ai_illimitata')
                    ->label(fn (User $r): string => $r->ai_monthly_call_cap === 0 ? 'Togli illimitata' : 'AI illimitata')
                    ->icon('heroicon-m-sparkles')
                    ->color(fn (User $r): string => $r->ai_monthly_call_cap === 0 ? 'gray' : 'warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $r): string => $r->ai_monthly_call_cap === 0
                        ? "Togliere l'AI illimitata a {$r->name}?"
                        : "Dare l'AI illimitata a {$r->name}?")
                    ->modalDescription(fn (User $r): string => $r->ai_monthly_call_cap === 0
                        ? 'Tornera\' al tetto che le spetta per palestra, trainer o piano.'
                        : 'Nessun tetto mensile, foto comprese. Il costo lo paghiamo noi: '
                          .'e\' pensata per le prove, non per i clienti. Finisce nel registro.')
                    ->modalSubmitActionLabel(fn (User $r): string => $r->ai_monthly_call_cap === 0 ? 'Togli' : 'Dai illimitata')
                    ->action(function (User $record): void {
                        $prima = $record->ai_monthly_call_cap;
                        $illimitata = $prima !== 0;

                        /*
                         * 🚨 `forceFill` perche' i due tetti sono **fuori da
                         * `$fillable`** di proposito (vedi `User`): una
                         * concessione non si assegna in massa da una richiesta
                         * HTTP. ⚠️ Senza, questa riga non salverebbe niente **e
                         * non darebbe errore**.
                         */
                        $record->forceFill([
                            /*
                             * 🚨 **Il cancello, non solo il tetto** — 15/08/2026.
                             *
                             * Il 14/08 qui si scrivevano solo i due tetti, e
                             * l'azione **non funzionava**: la quota dice
                             * *quante* chiamate, `ai_enabled_override` dice
                             * *se* l'AI spetti. Chi si registra da solo sta sul
                             * piano `free`, che l'AI non ce l'ha — il tetto era
                             * un rubinetto senza acqua.
                             *
                             * 💡 Il pulsante promette «AI illimitata»: deve
                             * consegnare **entrambe** le cose, o il nome mente.
                             */
                            'ai_enabled_override' => $illimitata ? true : null,

                            // ⚠️ `0` = illimitato, `null` = «come le altre».
                            // Sono opposti, e qui si usano entrambi.
                            'ai_monthly_call_cap' => $illimitata ? 0 : null,
                            'ai_monthly_photo_call_cap' => $illimitata ? 0 : null,
                        ])->save();

                        app(AuditLogger::class)->log(
                            AuditAction::AiQuotaChanged,
                            $record,
                            [
                                'email' => $record->email,
                                'da' => 'elenco utenti',
                                'dopo' => $illimitata ? 'AI ACCESA + ILLIMITATO' : 'come le altre',
                            ],
                            tenant: $record->tenant_id,
                        );

                        Notification::make()
                            ->title($illimitata
                                ? $record->name.' ha l\'AI illimitata'
                                : $record->name.' e\' tornato al tetto normale')
                            ->success()
                            ->send();
                    }),

                // ───────────────────── impersonazione ─────────────────────
                //
                // 🚨 Il controllo sta in `Impersonator::can()`, non qui: qui c'e'
                // solo la sua conseguenza visiva. Nascondere un pulsante non e'
                // un permesso — chi arriva all'azione per un'altra strada trova
                // comunque il controllo vero.
                Action::make('impersona')
                    ->label('Impersona')
                    ->icon('heroicon-m-user-circle')
                    ->color('danger')
                    ->visible(fn (User $r): bool => static::puoImpersonare($r))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $r): string => "Entrare nell'account di {$r->name}?")
                    ->modalDescription(
                        'Vedrai e potrai modificare i suoi dati come se fossi lui. '
                        .'L\'accesso viene registrato — chi, quando e su chi — e la traccia resta.'
                    )
                    ->modalSubmitActionLabel('Entra')
                    ->action(function (User $record) {
                        app(Impersonator::class)->start($record);

                        Notification::make()
                            ->title('Stai usando l\'account di '.$record->name)
                            ->body('Esci dalla barra rossa in cima quando hai finito.')
                            ->warning()
                            ->persistent()
                            ->send();

                        return redirect()->to(
                            RoleAwareLoginResponse::urlFor($record) ?? '/admin',
                        );
                    }),
            ])
            // Nessuna azione di massa: su una tabella che contiene gli utenti di
            // tutti i clienti insieme, una cancellazione multipla e' un incidente
            // che aspetta una spunta distratta.
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessun utente')
            ->emptyStateDescription('Gli utenti nascono dentro una palestra: dall\'app col codice d\'invito, o dal pannello della palestra.');
    }

    /** @return list<string> */
    /**
     * La quota **effettiva**, in forma corta per un badge.
     *
     * 💡 Distingue «gliel'abbiamo data noi» da «le spetta»: `sua:` marca il
     * tetto scritto **su questa persona**, che e' l'unico che qualcuno ha
     * deciso a mano e l'unico che abbia senso andare a togliere.
     */
    private static function quotaDi(User $utente): string
    {
        // 🚨 Prima il cancello: un tetto su un'AI spenta e' un numero che non
        // vuol dire niente, ed e' l'equivoco su cui il 14/08 e' andato perso un
        // giro intero.
        if (! app(PianoAttivo::class)->haLaAi($utente)) {
            return 'AI spenta';
        }

        if ($utente->ai_monthly_call_cap === 0) {
            return 'illimitata';
        }

        if ($utente->ai_monthly_call_cap !== null) {
            return 'sua: '.$utente->ai_monthly_call_cap;
        }

        $tetto = app(MemberAiQuota::class)->capFor($utente);

        // ⚠️ `null` da `capFor()` vuol dire **illimitato**: la catena e' gia'
        // stata risolta, e lo `0` di un livello superiore e' gia' tradotto.
        return $tetto === null ? 'illimitata' : (string) $tetto;
    }

    private static function ruoliDi(User $user): array
    {
        $ruoli = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->pluck('roles.name')
            ->all();

        if ($user->isSuperAdmin()) {
            array_unshift($ruoli, 'super_admin');
        }

        return array_values(array_unique($ruoli));
    }

    private static function puoImpersonare(User $target): bool
    {
        $attore = auth()->user();

        return $attore instanceof User
            && app(Impersonator::class)->can($attore, $target);
    }

    /**
     * L'abbonamento **regalato** di questa persona, se ce l'ha — 3b-H.10.
     *
     * 🚨 **Si riconosce dall'assenza degli id di Stripe.** Un abbonamento
     * pagato ha `stripe_subscription_id`; questo no, ed e' l'unico modo di
     * distinguerli. ⛔ Senza questa condizione il pulsante «togli» cancellerebbe
     * anche gli abbonamenti **pagati**, lasciando l'addebito attivo su Stripe e
     * il cliente senza il prodotto — cioe' il danno peggiore possibile.
     */
    /** Ha un abbonamento **pagato** e attivo? */
    private static function haPagato(User $utente): bool
    {
        return app(Cassa::class)->abbonamentoPagatoDi($utente) !== null;
    }

    /**
     * Quante persone usano questo abbonamento.
     *
     * L'abbonamento sta sul TENANT, non sulla persona: su una palestra
     * disdire tocca tutti gli iscritti, e chi preme deve vedere il NUMERO
     * prima di premere. "Attenzione, riguarda anche altri" non e' una
     * quantificazione, e' un'atmosfera.
     */
    private static function quantiNeUsano(User $utente): int
    {
        $tenantId = $utente->tenant?->getKey();

        if ($tenantId === null) {
            return 0;
        }

        return User::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();
    }

    private static function regaloDi(User $utente): ?PlanSubscription
    {
        $tenantId = $utente->tenant?->getKey();

        if ($tenantId === null) {
            return null;
        }

        return PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('stripe_subscription_id')
            ->attivi()
            ->orderByDesc('starts_at')
            ->first();
    }

    private static function haRegalo(User $utente): bool
    {
        return self::regaloDi($utente) !== null;
    }
}
