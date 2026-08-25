<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Schemas;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Support\Tenancy\TenantContext;
use Filament\Forms\Components\DatePicker;
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
                    TextInput::make('rif_allievo')
                        ->label('Rif. Allievo')
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

                    Select::make('status')
                        ->label('Stato')
                        ->options(PlanStatus::options())
                        ->default(PlanStatus::Draft->value)
                        ->required()
                        ->native(false)
                        ->helperText('Finche\' e\' una bozza l\'iscritto non la vede nell\'app.'),

                    DatePicker::make('starts_at')->label('Valida dal'),
                    DatePicker::make('ends_at')->label('Valida fino al'),

                    Textarea::make('notes')
                        ->label('Note per l\'iscritto')
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
                        ->label('')
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
                ->createOptionForm([
                    TextInput::make('name')->label('Nome')->required()->maxLength(160),
                    TextInput::make('equipment')->label('Attrezzo')->maxLength(64),
                ])
                ->createOptionUsing(fn (array $data): int => Exercise::create([
                    'name' => $data['name'],
                    'equipment' => $data['equipment'] ?? null,
                    'is_custom' => true,
                    'created_by' => auth()->id(),
                ])->getKey()),

            /*
             * ══ 🚨 IL MODO SVELTO, E QUELLO PRECISO — 3b-D.10, 25/08/2026 ══
             *
             * 📌 *«queste modifiche devono riguardare anche l'editor del trainer
             * e quello del server, mi pare ovvio»*.
             *
             * 💡 **Restano tutti e due**, e non e' indecisione: «4 × 8-12» e' il
             * modo in cui si scrive la maggior parte delle schede, e
             * costringere a compilare quattro righe identiche sarebbe un
             * peggioramento per il caso normale.
             *
             * ⚠️ Quando si compilano le righe, **vincono loro**: questi quattro
             * campi diventano il riassunto e li ricalcola `PlanExercise` a ogni
             * salvataggio. 🚨 Per questo l'aiuto sotto lo dice — un campo che si
             * riscrive da solo senza avvisare sembra un difetto.
             */
            TextInput::make('sets')
                ->label('Serie')
                ->numeric()
                ->minValue(1)
                ->maxValue(50)
                ->helperText('Se compili le righe qui sotto, questo lo calcolo io.'),

            TextInput::make('reps')
                ->label('Ripetizioni')
                ->maxLength(32)
                ->placeholder('8-12')
                ->helperText('Anche «cedimento» o «max».'),

            TextInput::make('rest_sec')
                ->label('Recupero (s)')
                ->numeric()
                ->minValue(0)
                ->maxValue(3600),

            TextInput::make('target_weight')
                ->label('Carico (kg)')
                ->numeric()
                ->minValue(0)
                ->maxValue(999),

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
                ->label('Serie riga per riga')
                ->helperText('Facoltative. Se ci sono, comandano loro.')
                ->columnSpanFull()
                ->collapsed()
                ->defaultItems(0)
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
                        ->numeric()
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
        $serie = $state['sets'] ?? null;
        $rip = $state['reps'] ?? null;

        return trim($nome.' '.($serie !== null ? "· {$serie}×" : '').($rip !== null ? $rip : ''));
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
