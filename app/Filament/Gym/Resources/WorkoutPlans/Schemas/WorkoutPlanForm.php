<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Schemas;

use App\Enums\MuscleGroup;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\PlanExercise;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Training\ExerciseMatcher;
use App\Support\Tenancy\TenantContext;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * L'editor di una scheda — B4.4.
 *
 * 🚨 **`reps` e' un campo di testo, non un numero, ed e' una scelta di
 * prodotto.** «8-12», «cedimento», «max», «10+10» sono prescrizioni che un
 * trainer scrive davvero. Forzarle in un intero significa perderle o costringere
 * a inventarsi un numero — e nell'app storica l'unico modo per esprimere un
 * range era metterlo nelle note, dove nessuna funzione lo leggeva.
 */
class WorkoutPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('La scheda')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(160)
                        ->columnSpanFull(),

                    SpatieMediaLibraryFileUpload::make('immagine')
                        ->label('Immagine')
                        ->collection(WorkoutPlan::COLLECTION_IMMAGINE)
                        ->image()
                        /*
                         * ✂️ **L'editor delle immagini è acceso** — 22/09/2026,
                         * Filament 5.8.4.
                         *
                         * ⛔ Su 5.7.6 era spento: `saveEditor()` partiva al
                         * salvataggio del form anche con l'editor mai aperto,
                         * il JavaScript moriva e «Salva» non faceva niente, in
                         * silenzio (atlante §106.3). 🔬 Riprovato nel browser su
                         * 5.8.4: con l'editor mai aperto la scheda si crea; con
                         * l'editor aperto, ritaglio salvato e poi «Salva», la
                         * scheda si crea con l'immagine ritagliata. Nessun errore
                         * JavaScript (atlante §137).
                         */
                        ->imageEditor()
                        ->maxSize(4096)
                        ->helperText(
                            'La copertina della scheda. In un elenco di sei «Full body A/B/C» '
                            .'è la sola cosa che le distingue a colpo d\'occhio.'
                        )
                        ->columnSpanFull(),

                    /*
                     * ⛔ **«Assegnata a» NON esiste piu'** — 14/08/2026.
                     *
                     * 🚨 Assegnare una scheda a una persona **sul server**
                     * significa scrivere in tabella che quella persona segue
                     * quel programma. Da un programma post-infortunio si capisce
                     * cos'e' successo a chi lo esegue: e' dato sanitario, e la
                     * decisione del committente e' che **non lo teniamo**.
                     *
                     * 💡 Al suo posto c'e' il «Rif. Allievo» qui sotto: una
                     * pseudonimizzazione che sceglie il trainer, che vede solo
                     * lui, e che non dice al server chi sia quella persona.
                     *
                     * ⚠️ La colonna `member_id` **resta** in tabella, e non e'
                     * un'incoerenza: serve alle schede che **l'iscritto scrive
                     * per se'** dall'app, dove il legame lo crea lui con i propri
                     * dati. Quello che non esiste piu' e' che sia **la palestra**
                     * a crearlo.
                     *
                     * 🎯 La scheda si consegna via **chat cifrata** (D4), dove il
                     * server instrada una busta che non sa leggere.
                     */

                    /*
                     * D3 - il promemoria privato di chi scrive la scheda.
                     *
                     * 🚨 Lo vede solo chi l\'ha scritta (R4), e **non entra mai
                     * nella busta cifrata**: e\' l\'etichetta del trainer.
                     *
                     * ⚠️ Il suggerimento alle iniziali non e\' cortesia: il campo
                     * sta **in chiaro sul server**, e da una scheda si capisce
                     * molto di chi la esegue - un programma post-infortunio dice
                     * cos\'e\' successo. Scriverci il nome per esteso rimette sul
                     * server il legame che l\'anonimato serve a togliere.
                     */
                    /*
                     * 🚨 **`visible()` non basta, e il test l\'ha dimostrato.**
                     *
                     * Livewire serializza lo **stato del modulo** dentro la
                     * pagina: un campo nascosto ma **idratato** manda il suo
                     * valore al browser lo stesso, dentro lo snapshot. Il primo
                     * tentativo usava solo `visible()`, e il valore compariva
                     * nell\'HTML di un altro trainer.
                     *
                     * 💡 `formatStateUsing()` interviene **sull\'idratazione**:
                     * per chi non e\' l\'autore lo stato diventa `null`, quindi
                     * non c\'e\' niente da serializzare. `visible()` resta, ma per
                     * non mostrare un campo vuoto — non e\' piu\' lui la difesa.
                     *
                     * ⚠️ La regola generale: nascondere non e\' non mandare.
                     */
                    /*
                     * ══ 🏷️ «RIFERIMENTO», NON «RIF. ALLIEVO» — 22/09/2026, F5.11 ══
                     *
                     * 📌 Il committente vuole che si chiami **Riferimento**.
                     *
                     * 💡 Non è un ritocco di stile: dopo R2 *«le schede vivono
                     * sui telefoni, non ci sono destinatari»* — quindi non
                     * esiste nessun «allievo» a cui riferirsi. ⛔ Chiamarlo
                     * ancora «Rif. Allievo» inviterebbe a scriverci dentro il
                     * nome di una persona, che è **esattamente** la cosa che
                     * questo campo serve a evitare.
                     *
                     * ⚠️ La colonna resta `rif_allievo`: rinominarla vorrebbe
                     * dire una migration, l'API e l'app in fila per un nome —
                     * e il nome che conta è quello che si legge a schermo.
                     */
                    TextInput::make('rif_allievo')
                        ->label('Riferimento')
                        ->maxLength(120)
                        ->placeholder('M.R. spalla dx')
                        ->helperText(
                            'Un tuo promemoria, per ritrovare la scheda. '
                            .'Meglio le iniziali: la vedi solo tu, ma resta scritta sul server.'
                        )
                        ->formatStateUsing(fn (?string $state, ?WorkoutPlan $record): ?string => $record !== null
                            && $record->created_by !== auth()->id() ? null : $state)
                        ->visible(fn (?WorkoutPlan $record): bool => $record === null
                            || $record->created_by === auth()->id()),

                    /*
                     * ══ ⛔ VIA LO «STATO» — 22/09/2026, F5.14 ═══════════════
                     *
                     * 📌 R2: *«Le schede vivono sui telefoni. Non ci sono
                     * destinatari. Quando un trainer (o una palestra) creano
                     * una scheda, questa va a finire tra le schede di tutti i
                     * trainer della palestra, e dal telefono questi le possono
                     * inviare a chi vogliono.»*
                     *
                     * 🚨 **«Bozza / pubblicata» rispondeva a una domanda che non
                     * esiste più**: pubblicata *per chi*? Il testo d'aiuto lo
                     * diceva da solo — *«finché è una bozza l'iscritto non la
                     * vede nell'app»* — e quell'iscritto non c'è: quello che
                     * sta qui è un **modello della palestra**, e la consegna la
                     * fa il trainer dal telefono, in chat.
                     *
                     * ⚠️ La colonna `status` resta in tabella per ora: la
                     * tolgono i passi 3 e 4 di §6.6 del piano, quando l'app in
                     * giro avrà smesso di leggerla. 💡 Le schede nuove nascono
                     * `published` — vedi `CreateWorkoutPlan`.
                     *
                     * ── ⛔ E via anche «valida dal / valida fino al» (F5.11) ──
                     *
                     * 🚨 Stessa ragione: una validità è un periodo **per una
                     * persona**. Un modello non scade, e due date sempre vuote
                     * in cima al modulo sono due campi che chiedono di essere
                     * compilati senza avere una risposta giusta.
                     */

                    /*
                     * 📝 **«Note», non «Note per l'iscritto»** — F5.11.
                     *
                     * ⛔ L'iscritto non le legge: la scheda parte da qui come
                     * modello, e quello che arriva sul telefono lo decide il
                     * trainer. 💡 Sono le note **della scheda**.
                     */
                    Textarea::make('notes')
                        ->label('Note')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Giorni')
                ->description(
                    'Una scheda puo\' avere piu\' giorni, e ogni giorno puo\' avere un\'alternativa. '
                    .'Una scheda a un giorno solo si scrive lasciando il nome vuoto.'
                )
                ->schema([
                    Repeater::make('days')
                        ->hiddenLabel() // ⚠️ `label('')` in Filament v5 ricade sull'etichetta automatica («Days»)
                        ->relationship()
                        ->orderColumn('position')
                        ->reorderable()
                        ->collapsible()
                        ->addActionLabel('Aggiungi giorno')
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Giorno')
                        ->defaultItems(1)
                        ->schema([
                            TextInput::make('name')
                                ->label('Nome del giorno')
                                ->maxLength(120)
                                ->placeholder('Giorno A - spinta')
                                ->columnSpanFull(),

                            static::esercizi(),

                            /*
                             * D2 - le alternative di giornata. Un livello solo:
                             * dentro un giorno alternativo non ce ne sono altre.
                             */
                            Repeater::make('alternative')
                                ->label('Giorni alternativi')
                                ->relationship()
                                ->orderColumn('position')
                                ->collapsible()
                                ->collapsed()
                                ->addActionLabel('Aggiungi un giorno alternativo')
                                ->maxItems(3)
                                ->defaultItems(0)
                                ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Alternativa')
                                ->schema([
                                    TextInput::make('name')->label('Nome')->maxLength(120)->columnSpanFull(),
                                    static::esercizi(conAlternative: false),
                                ])
                                ->columnSpanFull(),

                            Textarea::make('notes')->label('Note del giorno')->rows(2)->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    /**
     * Gli esercizi di un giorno, con le loro alternative - G6.1 (D2, D10).
     *
     * 💡 Scritto una volta e riusato per i giorni veri e per quelli
     * alternativi: un esercizio e\' un esercizio.
     *
     * @param  bool  $conAlternative  ⚠️ `false` dentro un giorno alternativo:
     *                                le alternative si fermano a un livello (D2)
     */
    private static function esercizi(bool $conAlternative = true): Repeater
    {
        $campi = static::campiEsercizio();

        if ($conAlternative) {
            $campi[] = Repeater::make('alternative')
                ->label('Oppure')
                ->relationship()
                ->orderColumn('position')
                ->addActionLabel('Aggiungi un\'alternativa')
                ->maxItems(3)
                ->defaultItems(0)
                ->columns(4)
                ->columnSpanFull()
                ->helperText('Al massimo tre. «Panca piana oppure panca con manubri».')
                ->schema(static::campiEsercizio());
        }

        return Repeater::make('exercises')
            ->label('Esercizi')
            ->relationship()
            ->orderColumn('position')
            ->reorderable()
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => static::etichetta($state))
            ->addActionLabel('Aggiungi esercizio')
            ->defaultItems(0)
            ->columns(4)
            ->columnSpanFull()
            ->schema($campi);
    }

    /** @return list<mixed> */
    private static function campiEsercizio(): array
    {
        return [
            Select::make('exercise_id')
                ->label('Esercizio')
                ->options(fn (): array => Exercise::query()
                    ->ordered()
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->columnSpan(4)
                // Un esercizio che non c'e' si crea al volo: se
                // bisogna uscire dalla scheda per aggiungerlo,
                // il trainer scrive il nome nelle note e la
                // libreria non cresce mai.
                /*
                 * ══ 🚨 ANCHE DA QUI SI PASSA DAL MATCHER — 3b-N, 28/08/2026 ══
                 *
                 * 📌 *«Ovviamente anche gli esercizi che uno aggiunge alla
                 * scheda senza passare per la pagina degli esercizi devono
                 * essere salvati allo stesso modo eh»*.
                 *
                 * ⛔ **Qui era l'unica strada che non lo faceva.** C'era un
                 * `Exercise::create()` grezzo, e produceva due difetti che
                 * altrove sono vietati per iscritto:
                 *
                 * 1. 🚨 **Nessuna riconciliazione.** Un trainer che scriveva
                 *    «Panca piana» in questo pannello si creava un doppione
                 *    della panca della piattaforma, dentro la sua palestra. Da
                 *    quel momento il progresso su quel movimento risultava
                 *    diviso su due righe — che e' esattamente il danno per cui
                 *    `ExerciseMatcher` esiste.
                 * 2. ⛔ **Esercizi muti.** Nessun muscolo, ne' primario ne'
                 *    secondari: la cosa che 3b-A.3.5 vieta all'API sollevando
                 *    `MuscoliNonDecisiException`. La guardia c'era, e questa
                 *    porta le passava accanto.
                 *
                 * 💡 Adesso i muscoli si chiedono anche qui, ed e' il motivo
                 * per cui il modulo e' cresciuto di due campi: senza, il
                 * matcher si rifiuterebbe di creare — giustamente.
                 */
                ->createOptionForm([
                    TextInput::make('name')->label('Nome')->required()->maxLength(160),
                    Select::make('muscle_group')
                        ->label('Muscolo principale')
                        ->options(MuscleGroup::class)
                        ->required()
                        ->helperText('Serve alla figura del corpo e al calcolo delle calorie.'),
                    Select::make('secondary_muscles')
                        ->label('Muscoli secondari')
                        ->options(MuscleGroup::class)
                        ->multiple()
                        ->maxItems(6)
                        ->helperText('Lascia vuoto se questo esercizio isola davvero.'),
                    TextInput::make('equipment')->label('Attrezzo')->maxLength(64),
                ])
                ->createOptionUsing(function (array $data): int {
                    $utente = auth()->user();

                    $esistenti = Exercise::query()->count();

                    $esercizio = app(ExerciseMatcher::class)->match(
                        (string) $data['name'],
                        $utente?->tenant_id,
                        $utente instanceof User ? $utente : null,
                        MuscleGroup::from((string) $data['muscle_group']),

                        /*
                         * ⚠️ `[]` e non `null`: qui una risposta c'e' sempre
                         * stata. `null` direbbe «non ci ho pensato», e il
                         * matcher si rifiuterebbe di creare — mentre chi ha
                         * lasciato il campo vuoto ha **deciso** che questo
                         * esercizio isola.
                         */
                        array_values(array_map(strval(...), $data['secondary_muscles'] ?? [])),
                    );

                    /*
                     * 💡 L'attrezzo si scrive **solo su cio' che e' appena
                     * nato**, come nell'API: cambiarlo su un esercizio della
                     * piattaforma perche' un trainer ha scritto un valore
                     * diverso lo cambierebbe per tutti.
                     */
                    if (Exercise::query()->count() > $esistenti && ($data['equipment'] ?? null) !== null) {
                        $esercizio->forceFill(['equipment' => $data['equipment']])->save();
                    }

                    return $esercizio->getKey();
                }),

            /*
             * ══ ⛔ VIA LA TESTATA «SERIE · RIPETIZIONI · RECUPERO · CARICO» ══
             *
             * 📌 Il committente, 23/09/2026: *«Ti avevo detto di non usare la
             * sezione serie ripetizioni recupero e carico sopra perchè è
             * possibile aggiungere serie riga per riga … mi trovo con "serie 5"
             * "ripetizioni 6-10" che non significa niente … Togli sta sezione
             * generale, che fa solo danni»*.
             *
             * 🚨 Prima (3b-D.10, F5.12) la testata restava come «modo svelto»
             * e spariva quando c'erano le righe. ⛔ Il danno era proprio il
             * modo svelto: «5 × 6-10» non dice quante ripetizioni fare in
             * nessuna serie. Adesso un esercizio si scrive **solo** riga per
             * riga, e le ripetizioni di una riga sono un numero.
             *
             * 💡 `sets`, `reps`, `rest_sec`, `target_weight` restano in tabella
             * come **riassunto**, ricalcolato da `PlanExercise::saving()` dalle
             * righe: li leggono ancora l'API vecchia e `prescription()`. Nessuno
             * li scrive più a mano.
             */
            Select::make('carico')
                ->label('Tipo di carico')
                ->options([
                    'peso' => 'Peso (kg)',
                    'niente' => 'Nessuno (corpo libero)',
                    'iso' => 'Isometria (secondi)',
                ])
                ->default('peso')
                ->selectablePlaceholder(false)
                /*
                 * ⚠️ **Non e' deducibile dalle righe**: «peso con i campi
                 * vuoti» e «a corpo libero» hanno le stesse righe e vogliono
                 * dire due cose diverse.
                 */
                ->helperText('A corpo libero il campo dei chili sparisce nell\'app.'),

            /*
             * ══ 📋 LE SERIE, UNA PER RIGA ══════════════════════════════════
             *
             * 📌 *«ogni serie deve avere Ripetizioni, Peso (o niente o Iso.) e
             * Recupero»*.
             *
             * 💡 Serve a dire la cosa che il modello vecchio non sapeva dire:
             * **12 a 40 kg, 10 a 45, 8 a 50**. ⚠️ Chiuso di default, perche' e'
             * il caso preciso e non quello comune: chi scrive «4 × 8-12» non
             * deve nemmeno vederlo.
             */
            Repeater::make('serie')
                ->label('Serie')
                ->helperText('Una riga per serie. «5 × 8» sono cinque righe da 8.')
                /*
                 * 🆕 23/09/2026 — una scheda scritta col formato vecchio
                 * («4 × 8-12», senza righe) si apre **già in righe**, espansa da
                 * `PlanExercise::serieRighe()`: al primo salvataggio diventano
                 * righe vere. ⚠️ Solo se vuoto: righe scritte non si toccano.
                 */
                ->afterStateHydrated(function (Repeater $component, mixed $state, mixed $record): void {
                    if ((! is_array($state) || $state === []) && $record instanceof PlanExercise) {
                        $component->state($record->serieRighe());
                    }
                })
                ->columnSpanFull()
                ->minItems(1)
                ->defaultItems(1)
                ->maxItems(30)
                ->addActionLabel('Aggiungi serie')
                ->itemLabel(fn (array $state): string => trim(
                    ($state['reps'] ?? '?').' rip.'
                    .(($state['weight'] ?? null) !== null ? ' × '.$state['weight'].' kg' : '')
                    .(($state['iso_sec'] ?? null) !== null ? ' · '.$state['iso_sec'].'s' : ''),
                ))
                ->schema([
                    TextInput::make('reps')
                        ->label('Ripetizioni')
                        ->integer()
                        ->minValue(0)
                        ->maxValue(999),

                    TextInput::make('weight')
                        ->label('Kg')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000)
                        // ⚠️ Vuoto **resta vuoto**: vuol dire «lo decidi li'»,
                        // mentre uno zero direbbe «a corpo libero» — che e'
                        // un'altra cosa e ha la sua voce nel tipo di carico.
                        ->helperText('Si può lasciare vuoto.'),

                    TextInput::make('iso_sec')
                        ->label('Secondi (iso)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(7200),

                    TextInput::make('rest_sec')
                        ->label('Recupero (s)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1200),
                ])
                ->columns(4),

            /*
             * ══ 🖼️ UN'IMMAGINE PER OGNI ESERCIZIO — 22/09/2026, F5.13 (A1) ══
             *
             * 📌 *«nel builder delle schede devo poter aggiungere un'immagine a
             * ogni esercizio»*.
             *
             * 💡 **Non è la foto dell'esercizio in libreria**, ed è la
             * distinzione che conta: quella è del catalogo e vale per tutti.
             * Questa è di **questa riga di questa scheda** — la presa esatta,
             * l'altezza del sedile, l'elastico che vuole quel trainer. ⛔ Con
             * una sola immagine per esercizio, chi la cambia per la propria
             * scheda la cambierebbe a tutta la palestra.
             *
             * ✂️ Con l'editor delle immagini: acceso di nuovo su Filament 5.8.4,
             * il perché sta sopra, sull'immagine della scheda (atlante §137).
             */
            SpatieMediaLibraryFileUpload::make('immagine')
                ->label('Immagine')
                ->collection(PlanExercise::COLLECTION_IMMAGINE)
                ->image()
                ->imageEditor()
                ->maxSize(4096)
                ->helperText('Facoltativa. La vede chi esegue la scheda, sopra le serie.')
                ->columnSpanFull(),

            Textarea::make('notes')
                ->label('Note')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /** @param array<string, mixed> $state */
    private static function etichetta(array $state): ?string
    {
        $id = $state['exercise_id'] ?? null;

        if ($id === null) {
            return null;
        }

        $nome = Exercise::find($id)?->name ?? 'Esercizio';

        // 🆕 23/09/2026 — dalle righe: «Panca piana · 12·10·8».
        $righe = is_array($state['serie'] ?? null) ? array_values($state['serie']) : [];
        $rip = array_values(array_filter(array_map(
            static fn (mixed $r): ?string => is_array($r) && is_numeric($r['reps'] ?? null) ? (string) (int) $r['reps'] : null,
            $righe,
        )));

        return $righe === [] ? $nome : $nome.' · '.($rip === [] ? count($righe).' serie' : implode('·', $rip));
    }

    /** @return array<int, string> */
    private static function iscritti(): array
    {
        $tenantId = app(TenantContext::class)->id();

        $query = User::query()
            ->whereIn('id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', UserRole::Member->value)
                ->where('model_has_roles.tenant_id', $tenantId)
                ->select('model_has_roles.model_id'));

        // Un trainer puo' assegnare solo ai propri: e' lo stesso confine di B3.5.
        $utente = auth()->user();

        if ($utente instanceof User && $utente->isTrainer() && ! $utente->isGymAdmin()) {
            $query->whereIn('id', $utente->assignedMembers()->pluck('users.id')->all());
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }
}
