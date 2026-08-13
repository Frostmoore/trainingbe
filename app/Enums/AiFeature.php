<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A cosa serve una chiamata AI.
 *
 * 🚨 **E' la chiave con cui si sceglie il modello.** Non tutte le richieste
 * meritano lo stesso: riconoscere «due uova e una fetta di pane» e' un compito
 * banale che un modello piccolo fa bene, leggere un PDF di allenamento
 * impaginato a tabelle non lo e'. Senza questa distinzione l'unica scelta
 * possibile e' un modello solo per tutto — e allora o si paga troppo per le cose
 * facili o si sbaglia sulle difficili.
 *
 * E' anche la dimensione con cui si guarda il consumo: «quanto ci costa il
 * riconoscimento delle foto?» e' una domanda con una risposta solo se questa
 * colonna esiste.
 */
enum AiFeature: string
{
    case FoodText = 'food_text';
    case FoodPhoto = 'food_photo';
    case WorkoutKcal = 'workout_kcal';
    case DailyAdvice = 'daily_advice';
    case PdfImport = 'pdf_import';

    /**
     * La stima di un alimento **mentre il trainer compone un piano** — D13.
     *
     * 🚨 **Etichetta propria anche se il contatore e' lo stesso** di `FoodText`.
     * `ai_usage_logs.feature` e' la contabilita': senza un valore suo, «quanto
     * ci costa far comporre i piani» e «quanto ci costa farli usare» diventano
     * lo stesso numero — e a quel punto non si puo' piu' dare un prezzo a
     * nessuno dei due.
     *
     * ⚠️ **La paga il trainer**, mai l'allievo: quando l'allievo riceve il
     * piano, il costo e' gia' stato sostenuto da chi l'ha scritto.
     */
    case PlanFood = 'plan_food';

    public function label(): string
    {
        return match ($this) {
            self::FoodText => 'Cibo da testo',
            self::FoodPhoto => 'Cibo da foto',
            self::WorkoutKcal => 'Calorie allenamento',
            self::DailyAdvice => 'Consiglio del giorno',
            self::PdfImport => 'Import scheda PDF',
            self::PlanFood => 'Alimento nel piano del trainer',
        };
    }

    /**
     * La richiesta manda immagini o documenti?
     *
     * Serve a due decisioni pratiche: il limite di dimensione da imporre prima
     * dell'invio, e l'esclusione dei modelli senza visione.
     *
     * 🆕 E da G2 a una terza: e' il discriminatore del **sotto-limite** e del
     * **costo in gettoni** (D7, D16). Non serviva inventare una tabella di pesi:
     * la distinzione fra «una chiamata di testo» e «una chiamata con un
     * allegato» era gia' nel dominio, ed e' la stessa che decide il costo.
     */
    public function isMultimodal(): bool
    {
        return $this === self::FoodPhoto || $this === self::PdfImport;
    }

    /**
     * Quanto costa questa chiamata a chi ha comprato i gettoni — D16.
     *
     * 🚨 **7, e il numero e' misurato** — `STIMA-COSTI-AI.md` §3.7.
     *
     * Un gettone rappresenta **una chiamata ordinaria**, e una chiamata
     * ordinaria costa 0,00318 $ (media pesata sull'uso reale: 3 stime da testo,
     * 6 consigli, 0,57 calorie allenamento). Una foto costa 0,0225 $, cioe'
     * **7,1 volte**.
     *
     * ⚠️ **Era 6, e sbagliava due volte**: era tarato sul rapporto con la stima
     * da testo invece che con la chiamata ordinaria, **e** su costi vecchi di
     * tre giorni — `FOOD_SYSTEM` era cresciuto del 329% con il classificatore
     * alimentare e nessuno aveva rimisurato.
     *
     * 💡 Si arrotonda **per eccesso**: il margine del documento e' ±15%, e sul
     * bordo basso ci perdiamo noi. Su un prezzo si sbaglia dal lato che non fa
     * danno.
     *
     * Se cambia il modello o il prompt, cambia questo numero — ed e' il solo
     * posto in cui va cambiato.
     *
     * ⚠️ **Il listino dice «di cui con foto», il codice conta i multimodali**, e
     * non e' la stessa cosa: `PdfImport` non e' una foto ma passa da `sonnet-5`
     * con un documento allegato, quindi costa come una foto. Contarlo insieme
     * alle stime da testo vorrebbe dire vendere a 1 gettone una chiamata che ne
     * costa 7.
     *
     * 💡 La differenza fra il nome commerciale e il criterio tecnico e'
     * accettabile perche' va nella direzione giusta: chi compra vede un limite
     * **piu' generoso** di quello che il codice applica alle chiamate care, mai
     * il contrario.
     */
    public function costoInGettoni(): int
    {
        return $this->isMultimodal() ? 7 : 1;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
