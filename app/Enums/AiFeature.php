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
     * 📈 L'analisi della progressione degli esercizi di una scheda — 3b-I.A.
     *
     * ── 🚨 UNA CHIAMATA PER SCHEDA, NON PER ESERCIZIO ──────────────────────
     *
     * 📌 *«facciamoglielo fare una sola volta per scheda con tutti gli esercizi
     * insieme poi li dividiamo noi»*. Costa **un gettone** (non multimodale) e
     * torna un oggetto per esercizio: la divisione la fa il server.
     *
     * ── ⚠️ Etichetta sua anche se costa come le altre ──────────────────────
     *
     * `ai_usage_logs.feature` e' la contabilita': senza un valore proprio,
     * «quanto ci costa l'analisi delle schede» non si potrebbe piu' sapere. E'
     * la stessa ragione scritta per `PlanFood`.
     */
    case PlanProgress = 'plan_progress';

    /**
     * L'importazione di un **piano alimentare** da PDF — N20.
     *
     * 🚨 **Voce sua e non `PdfImport`**, che e' quella delle schede di
     * allenamento. ⚠️ Non e' pignoleria: ⛔ **dal 28/08 costano uguale (50), e
     * questo e' proprio il motivo per cui la distinzione serve ancora** — se
     * fossero la stessa voce, «quanto ci costano i piani alimentari» e «quanto
     * ci costano le schede» sarebbero lo stesso numero per sempre.
     *
     * E soprattutto **sbagliare qui non si vede**. Un piano alimentare letto
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
            self::PlanProgress => 'Progressione degli esercizi',
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
            // multimodale — conta per la scelta del modello e per il limite di
            // dimensione. ⚠️ Il **prezzo** pero' non passa piu' di qui: i due
            // PDF stanno a 50 per decisione loro, vedi `costoInGettoni()`.
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
     * | PDF di una **scheda** di allenamento | **50** |
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
     * non e' la stessa cosa: nessuno dei due PDF e' una foto, ma tutti e due
     * passano da un modello grande con un documento allegato.
     *
     * 💡 La differenza fra nome commerciale e criterio tecnico e' accettabile
     * perche' va nella direzione giusta: chi compra vede un limite **piu'
     * generoso** di quello che il codice applica alle chiamate care, mai il
     * contrario.
     *
     * ⛔ **E dal 28/08 i due PDF non toccano piu' quel limite**: si pagano solo
     * a gettoni (`siPagaSoloConIGettoni()`), quindi non consumano la quota
     * inclusa ne' il suo sotto-limite multimodale.
     */
    public function costoInGettoni(): int
    {
        return match ($this) {
            /*
             * 🚨 **I due PDF costano uguale — 50 — dal 28/08/2026.**
             *
             * Il piano alimentare ci stava dal 19/08 (N20.1): non e' una
             * pagina, sono giorni, pasti, alimenti e grammi su parecchie
             * facciate scansionate — *«generalmente sono MOLTO piu' grandi»*.
             *
             * 📌 *«L'import dei pdf costa SEMPRE 50 gettoni, abbonato o no»*.
             *
             * ⚠️ La scheda stava a 10 perche' **ricadeva nel default dei
             * multimodali**, non perche' qualcuno avesse deciso 10 per lei: era
             * un prezzo per omissione. E il default sbagliato non si vede, e'
             * proprio questo il punto — nessun errore, solo un numero piu'
             * basso di quello giusto.
             *
             * 💡 Una scheda impaginata a tabelle non e' piu' economica di un
             * piano alimentare: e' lo stesso tipo di lettura, su un documento
             * dello stesso genere. Erano due prezzi diversi per la stessa cosa.
             *
             * ⚠️ E il prezzo va **detto prima**: chi importa deve saperlo, non
             * scoprirlo dal saldo.
             *
             * 💡 Un `match` e non una terza regola generale: le eccezioni di
             * prezzo sono per caso, e una regola in piu' («i documenti costano
             * X») sarebbe sbagliata al primo caso che non ci rientra.
             */
            self::NutritionPdfImport, self::PdfImport => 50,

            default => $this->isMultimodal() ? 10 : 1,
        };
    }

    /**
     * Questa chiamata si paga **solo** a gettoni, saltando la quota inclusa?
     *
     * ── 🚨 E' l'eccezione all'ordine di `CancelloDeiGettoni` ────────────────
     *
     * Ovunque nel sistema vale *«prima la quota inclusa, poi i gettoni»*, e la
     * ragione sta scritta li': **un gettone speso mentre la quota e' ancora
     * piena e' un gettone rubato**.
     *
     * ⛔ Per gli import da PDF quella regola non si applica, per decisione del
     * committente il 28/08/2026: 📌 *«devono essere proprio GETTONI, non si puo'
     * usare la quota flat»*.
     *
     * 💡 **Perche' e' difendibile e non un capriccio**: la quota inclusa e' un
     * tetto di chiamate **ricorrenti** — il consiglio del giorno, un alimento,
     * un'analisi — tarate su un costo di circa un centesimo l'una. Un PDF ne
     * costa cinquanta volte tanto. Farlo passare dalla quota vuol dire che una
     * sola importazione si mangia un terzo del mese di un abbonato, e quella
     * persona scopre a meta' mese che l'AI «non funziona piu'» senza aver fatto
     * niente di strano.
     *
     * 🚨 **E' una PROPRIETA' DELLA FUNZIONE, non un secondo cancello.** Metterla
     * qui vuol dire che chiunque apra il cancello la rispetta senza doverla
     * sapere; scriverla nei chiamanti vorrebbe dire due sedi della stessa regola
     * commerciale — l'errore che `CancelloDeiGettoni` esiste apposta per non
     * fare.
     *
     * ⚠️ **Conseguenza da dire a schermo, non da far scoprire dal saldo**: chi
     * ha la quota intatta ma zero gettoni **non puo' importare**. E' voluto, ed
     * e' esattamente la regola gia' scritta per il piano alimentare — *«il
     * prezzo va detto prima»*.
     */
    public function siPagaSoloConIGettoni(): bool
    {
        return $this === self::PdfImport
            || $this === self::NutritionPdfImport;
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
