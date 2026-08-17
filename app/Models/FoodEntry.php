<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FoodSource;
use App\Enums\MealType;
use App\Exceptions\MassaIncoerenteException;
use App\Models\Concerns\BelongsToTenant;
use App\Observers\FoodEntryObserver;
use App\Services\Nutrition\FoodUnit;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una voce del diario alimentare.
 *
 * 🚨 **`grams` e' la fonte di verita'.** `qty` e `unit` sono come l'utente lo ha
 * scritto; i grammi sono cio' su cui si calcola. Il modello li deriva da solo
 * quando mancano — vedi il boot — cosi' nessun chiamante puo' salvare una voce
 * senza il dato che serve alle somme.
 */
#[ObservedBy(FoodEntryObserver::class)]
class FoodEntry extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $attributes = [
        'source' => 'manual',
    ];

    protected $fillable = [
        'tenant_id', 'user_id', 'eaten_at', 'meal', 'description',
        'grams', 'qty', 'unit',
        'kcal', 'protein', 'carbs', 'fat',
        'kcal_100', 'protein_100', 'carbs_100', 'fat_100',
        'source', 'ai_raw', 'nutrition_plan_id', 'food_id',
    ];

    protected function casts(): array
    {
        return [
            'eaten_at' => 'datetime',
            'meal' => MealType::class,
            'source' => FoodSource::class,
            'ai_raw' => 'array',
            'grams' => 'float',
            'qty' => 'float',
            'kcal' => 'float',
            'protein' => 'float',
            'carbs' => 'float',
            'fat' => 'float',
            'kcal_100' => 'float',
            'protein_100' => 'float',
            'carbs_100' => 'float',
            'fat_100' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $voce): void {
            // I grammi mancanti si derivano da quantita' e unita': meglio un
            // valore derivato che una voce che non entra in nessun totale.
            if ($voce->grams === null) {
                $voce->grams = FoodUnit::toGrams($voce->qty, $voce->unit);
            }

            self::normalizzaUnitaSconosciuta($voce);
            self::derivaValoriPer100($voce);

            // Se conosciamo i valori per 100 g ma non gli assoluti, si
            // calcolano. L'AI risponde spesso solo con i primi.
            if ($voce->grams !== null && $voce->kcal === null && $voce->kcal_100 !== null) {
                $fattore = $voce->grams / 100;

                $voce->kcal = round($voce->kcal_100 * $fattore, 2);
                $voce->protein ??= $voce->protein_100 !== null ? round($voce->protein_100 * $fattore, 2) : null;
                $voce->carbs ??= $voce->carbs_100 !== null ? round($voce->carbs_100 * $fattore, 2) : null;
                $voce->fat ??= $voce->fat_100 !== null ? round($voce->fat_100 * $fattore, 2) : null;
            }

            // 🚨 Per ultima: controlla i valori DEFINITIVI, dopo ogni derivazione.
            self::rifiutaMassaImpossibile($voce);
        });
    }

    /**
     * 🚨 **I macronutrienti non possono pesare piu' dell'alimento.**
     *
     * ── Perche' questa guardia BLOCCA, mentre le altre no ────────────────────
     *
     * Il 12/08/2026 il modello ha prodotto delle coppiette di maiale con 56 g di
     * proteine, 4 di carboidrati e 40 di grassi **per 100 g**: cento grammi di
     * macro in cento grammi di prodotto, cioe' acqua zero. 588 kcal sono un
     * numero perfettamente plausibile, ed e' per questo che sarebbe passato.
     *
     * Il committente: *«la guardia sull'impossibilita' della massa e'
     * hard-blocking perche' non e' possibile che un alimento abbia piu' macro che
     * peso»*. Ed e' esatto: qui non c'e' nessuna interpretazione, nessuna tabella
     * e nessun margine di stima in cui quel numero abbia senso. La guardia sulla
     * coerenza **energetica** e' morbida perche' la' un numero fuori banda puo'
     * essere giusto (la fibra, i polioli); qui no.
     *
     * ⚠️ **Sta nel `saving()` e non in una regola di validazione**, cosi' vale per
     * OGNI strada: l'AI con `save: true`, la conferma, l'inserimento a mano, la
     * modifica, i preferiti, il piano alimentare, e quella che verra' scritta fra
     * un anno. Una `Rule` andrebbe ripetuta in cinque controller e dimenticata
     * nel sesto.
     *
     * 🚨 **Si controlla per ULTIMA**, dopo tutte le derivazioni: prima dei
     * ricalcoli i valori possono essere legittimamente incompleti, e bocciare
     * un'incoerenza che il modello stesso stava per sistemare vorrebbe dire
     * rifiutare voci sane.
     *
     * 💡 **Il 2% di tolleranza** e' per gli arrotondamenti: il modello manda
     * numeri interi, e tre arrotondamenti per eccesso su una voce da 100 g
     * possono sforare di poco senza che ci sia niente di impossibile. Cento
     * grammi d'olio che dichiarano 100 g di grassi sono giusti; 140 no.
     */
    private static function rifiutaMassaImpossibile(self $voce): void
    {
        $grammi = $voce->grams;

        if ($grammi === null || $grammi <= 0) {
            return;
        }

        $p = (float) ($voce->protein ?? 0);
        $c = (float) ($voce->carbs ?? 0);
        $f = (float) ($voce->fat ?? 0);
        $somma = $p + $c + $f;

        // 1. Oltre la massa dell'alimento: impossibile, punto.
        $oltreLaMassa = $somma > $grammi * 1.02;

        /*
         * 2. Al limite, ma con piu' di un macronutriente.
         *
         * 🚨 **La prima prova da sola non prendeva le coppiette**: 56 + 4 + 40
         * fa esattamente 100 su 100 g, cioe' al limite e non oltre. Il test lo
         * ha fatto vedere subito, in Dart prima e qui poi.
         *
         * 💡 Un alimento con piu' di un macronutriente contiene **sempre acqua**,
         * e non arriva mai al 100%. Al 100% ci arrivano solo i grassi puri e gli
         * zuccheri puri, che di macronutrienti ne hanno **uno solo**: 100 g
         * d'olio sono 100 g di grassi, e vanno salvati.
         *
         * ⚠️ Questa seconda prova e' l'unico pezzo **euristico** di una regola
         * altrimenti fisica: al 97% non e' impossibile in senso stretto, e' solo
         * inverosimile. Se un alimento vero dovesse mai inciamparci, la soglia va
         * allargata **qui e in `VoceStimata.macroImpossibili` insieme** — se le
         * due divergono, l'app lascia salvare qualcosa che il server rifiuta.
         */
        $quantiMacro = count(array_filter([$p, $c, $f], static fn (float $m): bool => $m > 0.5));
        $senzaAcqua = $quantiMacro > 1 && $somma > $grammi * 0.97;

        if (! $oltreLaMassa && ! $senzaAcqua) {
            return;
        }

        throw new MassaIncoerenteException(
            alimento: (string) $voce->description,
            grammi: (float) $grammi,
            macroTotali: $somma,
        );
    }

    /**
     * Un'unita' che non sappiamo convertire diventa **grammi**.
     *
     * ── 🚨 Il difetto, riferito provando l'app il 12/08/2026 ──────────────
     *
     * *«Ho scritto "Due cotolette di pollo" e me le segna come 2 pezzi. Ma
     * pezzi non è un'unità di misura, e quando vado a modificarle a mano non mi
     * ricalcola nulla se seleziono grammi.»*
     *
     * L'AI risponde con l'unita' **con cui la persona ha parlato** — «pezzi»,
     * «fette», «porzioni» — che non sta in `FoodUnit::FACTORS` e non si puo'
     * convertire: un pezzo non ha un peso, dipende dal cibo.
     *
     * 💡 **Ma i grammi l'AI li ha gia' dati**, perche' sa di che alimento si
     * parla. Quindi non si butta niente: si tiene il peso e si riscrive la
     * quantita' in grammi, che e' l'unica unita' su cui il ricalcolo funziona
     * sempre.
     *
     * ⚠️ **La descrizione resta intatta** («Due cotolette di pollo»), quindi
     * l'informazione «erano due» non si perde: cambia solo il modo di misurarle.
     * Il committente lo ha chiesto esplicitamente — *«comunque tutto va
     * convertito in grammi, così può ricalcolare automaticamente»*.
     */
    private static function normalizzaUnitaSconosciuta(self $voce): void
    {
        if ($voce->grams === null || $voce->grams <= 0) {
            return;
        }

        if (FoodUnit::valid($voce->unit) !== null) {
            return;
        }

        $voce->unit = 'g';
        $voce->qty = $voce->grams;
    }

    /**
     * Dagli assoluti si ricavano i valori **per 100 g**.
     *
     * ── 🚨 La riga per cui la modifica a mano non ricalcolava niente ──────
     *
     * `DiaryController::ricalcolaSeCambiaLaQuantita()` esce subito quando
     * `kcal_100` e' `null`, e **lo schema dell'AI non ha nessun campo `_100`**:
     * chiede `grams`, `kcal`, `protein`, `carbs`, `fat` e basta. Quindi ogni
     * voce creata dall'AI nasceva senza riferimento per 100 g, e cambiarne la
     * quantita' lasciava i macro **fermi ai valori di prima**.
     *
     * 💡 Il conto e' esatto e non inventa niente: se 300 g valgono 480 kcal,
     * 100 g ne valgono 160. E' l'informazione che c'era gia', scritta in una
     * forma che si puo' riscalare.
     *
     * ⚠️ **Non sovrascrive quello che arriva**: chi manda gia' i valori per
     * 100 g — l'inserimento manuale da un'etichetta — li ha piu' precisi di
     * qualunque divisione.
     */
    private static function derivaValoriPer100(self $voce): void
    {
        if ($voce->grams === null || $voce->grams <= 0) {
            return;
        }

        $fattore = 100 / $voce->grams;

        foreach (['kcal', 'protein', 'carbs', 'fat'] as $macro) {
            $per100 = $macro.'_100';

            if ($voce->{$per100} !== null || $voce->{$macro} === null) {
                continue;
            }

            $voce->{$per100} = round((float) $voce->{$macro} * $fattore, 2);
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(NutritionPlan::class, 'nutrition_plan_id');
    }

    // ───────────────────────── query ─────────────────────────

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Le voci di **un giorno di chi guarda** — A3.
     *
     * 🚨 **Il parametro e' un `GiornoLocale` e non un `Carbon`, ed e' il punto
     * in cui il difetto e' stato chiuso.** Prima questo scope rifaceva
     * `startOfDay()`/`endOfDay()` sul `Carbon` ricevuto, cioe' **in UTC**: la
     * cena delle 00:30 di Roma cadeva nel giorno prima, e il diario si apriva
     * sul giorno sbagliato.
     *
     * ⚠️ Cambiare il tipo e' deliberato. Accettare ancora un `Carbon` avrebbe
     * lasciato in piedi ogni chiamata sbagliata **senza un solo segnale**; cosi'
     * invece chi passa un istante prende un errore di tipo, subito.
     */
    public function scopeOnDate(Builder $query, GiornoLocale $giorno): Builder
    {
        return $query->whereBetween('eaten_at', $giorno->finestra());
    }

    /**
     * I totali di un insieme di voci.
     *
     * Sta qui e non nei controller perche' la somma dei macro e' la stessa in
     * diario, dashboard e API: tre implementazioni sono tre numeri diversi che
     * aspettano di divergere — e' successo esattamente cosi' nell'app storica.
     *
     * @param  iterable<self>  $voci
     * @return array{kcal: float, protein: float, carbs: float, fat: float}
     */
    public static function totals(iterable $voci): array
    {
        $t = ['kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];

        foreach ($voci as $v) {
            $t['kcal'] += (float) ($v->kcal ?? 0);
            $t['protein'] += (float) ($v->protein ?? 0);
            $t['carbs'] += (float) ($v->carbs ?? 0);
            $t['fat'] += (float) ($v->fat ?? 0);
        }

        return array_map(static fn (float $n): float => round($n, 2), $t);
    }
}
