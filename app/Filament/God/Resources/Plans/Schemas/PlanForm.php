<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Schemas;

use App\Enums\PlanKind;
use App\Models\Plan;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * ✂️ **Il modulo di un piano cucito a mano** — 21/09/2026.
 *
 * 📌 *«creami la possibilità di creare nuovi abbonamenti per palestre con il
 * numero di trainers che voglio io»*.
 *
 * ── 🚨 I prezzi si scrivono in EURO e si salvano in CENTESIMI ─────────────
 *
 * Il database tiene i centesimi — è l'unico modo di non perdere mezzo
 * centesimo a ogni moltiplicazione. ⛔ Ma un modulo che chiede «29900» si
 * sbaglia: basta uno zero in più e il cliente paga 2.990 € al mese. 💡 Quindi
 * qui si scrive `299,00` e la conversione la fa il campo, con `round()`: senza,
 * `(int) (2.80 * 100)` farebbe **279**, che è il classico centesimo perso in
 * virgola mobile.
 *
 * ── ⚠️ Le convenzioni dei campi vuoti, che NON sono intercambiabili ───────
 *
 * | Campo | Vuoto vuol dire |
 * |---|---|
 * | `max_members`, `max_trainers` | **senza tetto** |
 * | `ai_monthly_calls_per_member` | «lo decide il sistema», non «zero» |
 * | `prezzo_iscritto_cent` | **non è** un piano a consumo (`Plan::aConsumo()`) |
 *
 * 🚨 Scrivere `0` al posto di lasciare vuoto è un errore che non dà errore: su
 * un tetto `0` vuol dire «nessun posto», sulle chiamate AI vuol dire
 * «illimitate». Sono le convenzioni della catena della quota, e qui si
 * ripetono negli aiuti dei campi perché è qui che si sbagliano.
 */
class PlanForm
{
    /**
     * 🆕 F9.1 — lo stesso modulo per i piani **di listino** e per quelli **su
     * misura** (*«deve avere gli stessi campi di piani e listino»*): cambiano
     * solo il segno `cucito_a_mano` e cosa dice «In vendita».
     */
    public static function configure(Schema $schema, bool $suMisura = true): Schema
    {
        return $schema->components([

            Section::make('Che piano è')
                ->description('Il codice non si cambia più dopo: gli abbonamenti ci puntano.')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Codice')
                        ->required()
                        ->maxLength(40)
                        /*
                         * ⚠️ Stesso alfabeto della rotta `/iscriviti/{piano}`
                         * (`[a-z0-9_]{2,40}`): un codice con un accento o uno
                         * spazio farebbe un piano irraggiungibile dal sito il
                         * giorno che lo si volesse rendere pubblico.
                         */
                        ->rule('regex:/^[a-z0-9_]{2,40}$/')
                        ->helperText('Minuscole, numeri e trattino basso. Per esempio: su_misura_powergym.')
                        ->unique(Plan::class, 'code', ignoreRecord: true)
                        /*
                         * 🚨 Immutabile dopo la creazione. `plan_subscriptions`
                         * punta all'id, ma il **codice** è quello che cercano
                         * il seeder, Stripe e mezza suite di test: cambiarlo a
                         * un piano vivo è il modo di far sparire un cliente da
                         * ogni ricerca.
                         */
                        ->disabled(fn (?Plan $record): bool => $record !== null)
                        ->dehydrated(fn (?Plan $record): bool => $record === null),

                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        // ⚠️ 80 e non 255: e' la larghezza vera della colonna.
                        ->maxLength(80)
                        ->helperText('Quello che legge il cliente nel suo pannello.'),

                    Select::make('kind')
                        ->label('Per chi')
                        ->options([
                            PlanKind::Gym->value => 'Palestra',
                            PlanKind::Trainer->value => 'Trainer',
                            PlanKind::Person->value => 'Persona',
                        ])
                        ->default(PlanKind::Gym->value)
                        ->required()
                        ->native(false)
                        ->disabled(fn (?Plan $record): bool => $record !== null)
                        ->dehydrated(fn (?Plan $record): bool => $record === null)
                        ->helperText('Anche questo non si cambia: decide chi può riceverlo.'),

                    /*
                     * 🆕 F9.1 — 📌 *«a listino non so cosa sia»*. Ora si chiama
                     * **«In vendita»** e dice cosa fa.
                     */
                    Toggle::make('is_public')
                        ->label('In vendita')
                        ->default(! $suMisura)
                        ->helperText($suMisura
                            ? '⛔ Tienilo spento. Acceso, questo piano compare fra i prezzi del sito e '
                              .'nel pannello, e chiunque può comprarlo: un accordo cucito per un cliente '
                              .'diventerebbe il prezzo per tutti.'
                            : 'Acceso: compare fra i prezzi del sito e nel pannello, e chiunque lo può '
                              .'comprare. Spento: chi ce l\'ha lo tiene, ma nessuno lo può più comprare.'),

                    /*
                     * ✂️ La colonna che dice «questa riga la comanda una
                     * persona, non `PlanSeeder`». 🚨 Nascosta e sempre `true`:
                     * un piano creato da qui e marcato `false` sarebbe un piano
                     * che il pannello si rifiuterebbe di modificare subito
                     * dopo averlo creato.
                     */
                    Hidden::make('cucito_a_mano')->default($suMisura),
                ]),

            Section::make('Quanti posti')
                ->description('Vuoto = senza tetto. Zero = nessun posto: non sono la stessa cosa.')
                ->columns(2)
                ->schema([
                    TextInput::make('max_members')
                        ->label('Posti iscritto')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Quanti abbonamenti può regalare. Vuoto = senza tetto (piano a consumo).'),

                    TextInput::make('max_trainers')
                        ->label('Posti trainer')
                        ->numeric()
                        ->minValue(0)
                        ->helperText(
                            '🚨 Ogni trainer ha l\'AI accesa e non viene fatturato: è il motivo '
                            .'per cui a listino sono 2, 4, 6 e 20. Qui metti quelli concordati.'
                        ),
                ]),

            Section::make('Quanto paga')
                ->description('In euro, IVA inclusa. Un piano a canone ha il canone; uno a consumo ha il prezzo a iscritto.')
                ->columns(2)
                ->schema([
                    /*
                     * ⚠️ **`price_cents` non è nullable**: la colonna è `NOT NULL
                     * DEFAULT 0`, e lasciare il campo vuoto deve dare **zero**,
                     * non `null`. ⛔ Un `null` qui sarebbe un errore SQL al
                     * salvataggio, cioè un modulo che si rifiuta di chiudersi
                     * senza spiegare perché.
                     */
                    self::euro('price_cents', 'Canone', vuotoEZero: true)
                        ->helperText('0 su un piano a consumo: lì il canone vero è quello qui sotto.'),

                    /*
                     * 🆕 📅 **Ogni quanto si paga** — F9.2. 📌 *«deve contenere la
                     * temporalità (mese, settimana, giorno, anno...)»*. Stripe
                     * addebita con questo intervallo (`Plan::intervalloStripe()`).
                     * ⚠️ Vale per chi lo compra **da adesso**: chi l'ha già
                     * continua col suo intervallo finché non cambia piano.
                     */
                    Select::make('intervallo')
                        ->label('Ogni quanto si paga')
                        ->options([
                            'giorno' => 'Ogni giorno',
                            'settimana' => 'Ogni settimana',
                            'mese' => 'Ogni mese',
                            'anno' => 'Ogni anno',
                        ])
                        ->default('mese')
                        ->required()
                        ->native(false)
                        ->helperText('Il canone qui accanto si paga con questa cadenza. Il piano a consumo si paga sempre a mese chiuso.'),

                    self::euro('prezzo_iscritto_cent', 'Prezzo per iscritto')
                        ->helperText('Compilalo SOLO per un piano a consumo: è ciò che lo rende tale.'),

                    self::euro('canone_cent', 'Fisso mensile del consumo')
                        ->helperText('Si somma al prezzo per iscritto. A listino è 15 € per le palestre.'),

                    TextInput::make('minimo_iscritti')
                        ->label('Minimo di iscritti fatturati')
                        ->numeric()
                        ->minValue(0)
                        ->helperText(
                            'Sotto questo numero si paga comunque questo numero. ⚠️ Il minimo vale '
                            .'sul CONTEGGIO, non sul totale.'
                        ),
                ]),

            Section::make('Intelligenza artificiale')
                ->columns(2)
                ->schema([
                    Toggle::make('ai_enabled')
                        ->label('AI compresa')
                        ->default(true)
                        ->helperText('Spenta, gli iscritti di questo piano possono usare solo i gettoni comprati.'),

                    TextInput::make('ai_monthly_calls_per_member')
                        ->label('Chiamate al mese, per iscritto')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Vuoto = default di sistema. 0 = illimitate. A listino sono 150.'),

                    TextInput::make('ai_monthly_photo_calls_per_member')
                        ->label('...di cui con foto')
                        ->numeric()
                        ->minValue(0)
                        ->helperText(
                            'Sotto-limite del numero qui sopra, non un budget a parte: una foto '
                            .'consuma tutti e due i contatori, e costa circa sette volte una '
                            .'chiamata normale. A listino sono 15.'
                        ),
                ]),
        ]);
    }

    /**
     * Un campo in euro su una colonna in centesimi.
     *
     * 🚨 `round()` e non `(int)`: `(int) (2.80 * 100)` fa **279**. È il
     * centesimo che sparisce in virgola mobile, e su un prezzo a iscritto
     * moltiplicato per trecento persone diventano tre euro al mese, per sempre.
     */
    private static function euro(string $campo, string $etichetta, bool $vuotoEZero = false): TextInput
    {
        return TextInput::make($campo)
            ->label($etichetta)
            ->numeric()
            ->minValue(0)
            ->prefix('€')
            ->rules([Rule::numeric()])
            ->formatStateUsing(fn (?int $state): ?string => $state === null
                ? null
                : number_format($state / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn (?string $state): ?int => ($state === null || $state === '')
                ? ($vuotoEZero ? 0 : null)
                : (int) round(((float) $state) * 100));
    }
}
