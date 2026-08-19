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
     * L'importazione di un **piano alimentare** da PDF — N20.
     *
     * 🚨 **Voce sua e non `PdfImport`**, che e' quella delle schede di
     * allenamento. ⚠️ Non e' pignoleria: costano in modo diverso (50 contro 7),
     * e soprattutto **sbagliare qui non si vede**. Un piano alimentare letto
     * male produce numeri plausibili, non un errore: 200 g letti 20 g e' una
     * dieta sbagliata su un documento che la persona crede fedele
     * all'originale.
     *
     * 💡 Per questo l'importazione non scrive niente: produce una **bozza**
     * da confermare riga per riga, con l'originale a fianco (N20.3).
     */
    case NutritionPdfImport = 'nutrition_pdf_import';

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
            self::NutritionPdfImport => 'Import piano alimentare PDF',
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
        return $this === self::FoodPhoto
            || $this === self::PdfImport
            // 🚨 N20: un PDF e' un allegato, quindi la chiamata e'
            // multimodale. Il costo pero' e' suo (50), non i 10 delle altre.
            || $this === self::NutritionPdfImport;
    }

    /**
     * Quanto costa questa chiamata a chi ha comprato i gettoni — D16.
     *
     * ── 🚨 Il listino, deciso dal committente il 19/08/2026 ─────────────
     *
     * | Chiamata | Gettoni |
     * |---|---|
     * | Foto di un pasto | **10** |
     * | PDF di una **scheda** di allenamento | **10** |
     * | PDF di un **piano alimentare** | **50** |
     * | Tutto il resto (testo, consiglio, calorie) | **1** |
     *
     * ⚠️ **Il 10 e' un PREZZO, non una misura.** Il numero misurato era **7**:
     * una foto costa 0,0225 $ contro 0,00318 $ di una chiamata ordinaria,
     * cioe' 7,1 volte (`STIMA-COSTI-AI.md` §3.7). Il committente ha scelto 10, e
     * la differenza e' margine — che e' una decisione commerciale, non un errore
     * di calcolo.
     *
     * 💡 **Se un giorno il costo misurato superasse il 10**, il prezzo va
     * rialzato: la regola che non si rompe e' *«il prezzo non scende mai sotto il
     * costo»*. Questo e' il solo posto in cui cambiarlo.
     *
     * 🚨 **I 50 del piano alimentare restano un caso a se'.** Un piano non e'
     * una pagina: sono giorni, pasti, alimenti e grammi, spesso su parecchie
     * facciate scansionate — *«generalmente sono MOLTO piu' grandi»*, il
     * committente. La chiamata costa molto piu' di una foto, e contarla a 10
     * vorrebbe dire venderla sotto costo.
     *
     * ⚠️ **Il listino dice «di cui con foto», il codice conta i multimodali**, e
     * non e' la stessa cosa: `PdfImport` non e' una foto ma passa da un modello
     * grande con un documento allegato, quindi costa come una foto.
     *
     * 💡 La differenza fra nome commerciale e criterio tecnico e' accettabile
     * perche' va nella direzione giusta: chi compra vede un limite **piu'
     * generoso** di quello che il codice applica alle chiamate care, mai il
     * contrario.
     */
    public function costoInGettoni(): int
    {
        return match ($this) {
            /*
             * 🚨 **50, e non 10 come le altre multimodali** — N20.1, listino
             * confermato dal committente il 19/08/2026.
             *
             * Un piano alimentare non e' una pagina: sono giorni, pasti,
             * alimenti e grammi, spesso su parecchie facciate scansionate.
             * *«generalmente sono MOLTO piu' grandi»*.
             *
             * ⚠️ E il prezzo va **detto prima**: chi importa deve sapere che
             * gli costa cinquanta gettoni, non scoprirlo dal saldo.
             *
             * 💡 Un `match` e non una terza regola generale: le eccezioni
             * di prezzo sono per caso, e una regola in piu' («i documenti
             * costano X») sarebbe sbagliata al primo caso che non ci rientra.
             */
            self::NutritionPdfImport => 50,

            default => $this->isMultimodal() ? 10 : 1,
        };
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
