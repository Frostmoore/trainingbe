<?php

declare(strict_types=1);

namespace App\Services\Scoperta;

use App\Models\Comune;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Che cosa vuol dire «vicino» — 18/08/2026. **M1.3.**
 *
 * ── 🚨 Vicino e' una distanza, non una provincia ───────────────────────────
 *
 * Il piano diceva «stessa provincia, poi le confinanti». ⚠️ Sarebbe stata una
 * tabella di adiacenze scritta a mano — 107 province, ognuna con la sua lista —
 * cioe' qualche centinaio di righe compilate a memoria, **senza nessun modo di
 * accorgersi di un errore**: se ci si dimentica che Rimini confina con Pesaro,
 * nessun test fallisce, e semplicemente per sempre una palestra a trenta
 * chilometri non compare a nessuno.
 *
 * 💡 Con le coordinate del comune (che sono un dato pubblico: vedi la migrazione
 * per perche' questo **non** e' il GPS della persona) la stessa domanda si
 * risponde con una sottrazione, e la risposta e' migliore: chi sta a Rimini ha
 * Pesaro a 30 km e Ferrara a 100, ma Pesaro e' in un'altra **regione** — per
 * provincia non sarebbe mai comparsa.
 *
 * ── ⚠️ E allora perche' esiste ancora il ripiego amministrativo ────────────
 *
 * Perche' **57 comuni su 7.896 non hanno le coordinate**: la fonte e' del 2018 e
 * i comuni nati o fusi dopo non ci sono. 🚨 Un servizio che desse per scontato
 * che ci siano sempre restituirebbe una collezione vuota per quelle persone —
 * cioe' «non c'e' nessuna palestra in Italia», che e' il peggior modo di
 * fallire: silenzioso e plausibile.
 *
 * ── 🚨 Perche' il rettangolo prima della distanza ──────────────────────────
 *
 * La distanza vera (haversine) e' una **funzione**: MySQL la calcola riga per
 * riga e non puo' usare nessun indice. Su ottomila comuni, a ogni tasto premuto,
 * sarebbe una scansione completa.
 *
 * 💡 Quindi si restringe prima con un rettangolo su `lat`/`lng` — che l'indice
 * copre — e si calcola la distanza solo su quello che resta. ⚠️ Il rettangolo
 * prende **piu'** di quanto serve (agli angoli sfora il raggio di un 41%): e'
 * voluto, perche' un filtro grossolano che tiene qualcosa in piu' e' corretto,
 * mentre uno che ne perde e' un difetto.
 */
class Vicinanza
{
    /** Il raggio predefinito: quanto qualcuno e' disposto a spostarsi per una palestra. */
    public const RAGGIO_KM = 50;

    /**
     * ⚠️ Il tetto assoluto. Serve a non far diventare `comuniVicini()` un modo
     * per farsi restituire l'intera tabella scrivendo un raggio enorme.
     */
    public const RAGGIO_MASSIMO_KM = 200;

    /** Il raggio terrestre in chilometri, per l'haversine. */
    private const RAGGIO_TERRA_KM = 6371.0;

    /**
     * 💡 Un grado di latitudine vale ~111 km ovunque. La longitudine no: vale
     * 111 km all'equatore e si stringe salendo. In Italia (lat 35–47) il fattore
     * sta fra 0,68 e 0,82, e si calcola con il coseno della latitudine.
     */
    private const KM_PER_GRADO_LAT = 111.0;

    /**
     * I comuni vicini a questo, **dal piu' vicino al piu' lontano**.
     *
     * 🚨 Il comune di partenza e' **incluso** ed e' sempre il primo (distanza
     * zero). ⚠️ Escluderlo sarebbe l'errore piu' facile da fare qui: chi cerca
     * una palestra vicino a Bologna vuole prima di tutto quelle **a Bologna**.
     *
     * @return Collection<int, Comune>
     */
    public function comuniVicini(Comune $centro, int $raggioKm = self::RAGGIO_KM, int $limite = 200): Collection
    {
        $raggioKm = max(1, min($raggioKm, self::RAGGIO_MASSIMO_KM));

        if (! $centro->haCoordinate()) {
            return $this->ripiegoAmministrativo($centro, $limite);
        }

        return $this->query($centro, $raggioKm)
            ->limit($limite)
            ->get()
            ->map(function (Comune $c) use ($centro): Comune {
                /*
                 * 💡 La distanza si attacca all'oggetto invece di essere
                 * ricalcolata da chi legge: e' gia' stata calcolata dal database
                 * per ordinare, e rifarla in PHP darebbe due numeri che possono
                 * divergere all'ultima cifra.
                 */
                $c->setAttribute('distanza_km', round($this->distanzaKm($centro, $c), 1));

                return $c;
            });
    }

    /**
     * Gli `id` dei comuni vicini. E' la forma che serve al catalogo.
     *
     * 💡 Metodo a parte e non `->pluck('id')` sul risultato di `comuniVicini()`:
     * qui non serve caricare gli oggetti, e su un raggio largo sono qualche
     * centinaio di modelli costruiti per essere buttati via subito.
     *
     * @return array<int, int>
     */
    public function idVicini(Comune $centro, int $raggioKm = self::RAGGIO_KM, int $limite = 200): array
    {
        if (! $centro->haCoordinate()) {
            return $this->ripiegoAmministrativo($centro, $limite)->pluck('id')->all();
        }

        /** @var array<int, int> $id */
        $id = $this->query($centro, $raggioKm)->limit($limite)->pluck('id')->all();

        return $id;
    }

    /**
     * La distanza in chilometri fra due comuni, o `null` se uno dei due non ha
     * le coordinate.
     *
     * 🚨 Torna `null` e non `0`: zero vorrebbe dire «nello stesso posto», ed e'
     * la risposta sbagliata piu' credibile che si possa dare — un ordinamento
     * per distanza metterebbe in cima proprio i comuni di cui non si sa niente.
     */
    public function distanzaKm(Comune $a, Comune $b): ?float
    {
        if (! $a->haCoordinate() || ! $b->haCoordinate()) {
            return null;
        }

        $latA = deg2rad((float) $a->lat);
        $latB = deg2rad((float) $b->lat);
        $dLat = $latB - $latA;
        $dLng = deg2rad((float) $b->lng - (float) $a->lng);

        $h = sin($dLat / 2) ** 2 + cos($latA) * cos($latB) * sin($dLng / 2) ** 2;

        return self::RAGGIO_TERRA_KM * 2 * asin(min(1.0, sqrt($h)));
    }

    /**
     * La query: rettangolo indicizzato, poi haversine per l'ordine.
     *
     * @return Builder<Comune>
     */
    private function query(Comune $centro, int $raggioKm): Builder
    {
        $lat = (float) $centro->lat;
        $lng = (float) $centro->lng;

        $deltaLat = $raggioKm / self::KM_PER_GRADO_LAT;

        /*
         * ⚠️ Il coseno va protetto: alle latitudini italiane non ci si avvicina
         * mai al polo, ma un coseno che tende a zero farebbe esplodere il delta
         * a infinito e il rettangolo diventerebbe tutto il pianeta. Il limite
         * inferiore costa niente e toglie di mezzo un'intera classe di guasti.
         */
        $cos = max(0.01, cos(deg2rad($lat)));
        $deltaLng = $raggioKm / (self::KM_PER_GRADO_LAT * $cos);

        $distanza = sprintf(
            '(%F * 2 * ASIN(LEAST(1, SQRT(POWER(SIN(RADIANS(lat - %F) / 2), 2) '.
            '+ COS(RADIANS(%F)) * COS(RADIANS(lat)) * POWER(SIN(RADIANS(lng - %F) / 2), 2)))))',
            self::RAGGIO_TERRA_KM,
            $lat,
            $lat,
            $lng,
        );

        return Comune::query()
            ->attivi()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereBetween('lat', [$lat - $deltaLat, $lat + $deltaLat])
            ->whereBetween('lng', [$lng - $deltaLng, $lng + $deltaLng])
            /*
             * 🚨 Il `HAVING` e non un secondo `WHERE`: la distanza e' un alias
             * calcolato, e MySQL non lo conosce ancora quando valuta la `WHERE`.
             * Ripetere l'espressione intera nella `WHERE` funzionerebbe, ma
             * sarebbe la stessa formula scritta due volte — cioe' due formule
             * che un giorno divergono.
             */
            ->selectRaw("comuni.*, {$distanza} AS distanza_calcolata")
            ->having('distanza_calcolata', '<=', $raggioKm)
            ->orderBy('distanza_calcolata');
    }

    /**
     * 🚨 Il ripiego per i comuni senza coordinate: stessa provincia, poi stessa
     * regione.
     *
     * ⚠️ E' peggiore della distanza — la provincia non dice quanto e' lontano
     * niente — ma e' molto meglio di niente, che e' l'alternativa. E riguarda
     * cinquantasette comuni su settemilaottocentonovantasei.
     *
     * 💡 Il comune stesso resta comunque **primo**, come nel caso normale: e'
     * l'unica cosa di cui si e' certi anche senza coordinate.
     *
     * @return Collection<int, Comune>
     */
    private function ripiegoAmministrativo(Comune $centro, int $limite): Collection
    {
        /** @var Collection<int, Comune> $trovati */
        $trovati = Comune::query()
            ->attivi()
            ->where(fn (Builder $q) => $q
                ->where('provincia', $centro->provincia)
                ->orWhere('regione', $centro->regione))
            ->orderByRaw('CASE WHEN id = ? THEN 0 WHEN provincia = ? THEN 1 ELSE 2 END', [$centro->id, $centro->provincia])
            ->orderBy('nome')
            ->limit($limite)
            ->get();

        return $trovati;
    }
}
