<?php

declare(strict_types=1);

namespace App\Services\Nutrition\Catalogo;

use App\Models\Food;

/**
 * Chi vince quando due fonti dicono la stessa cosa — 17/08/2026.
 *
 * ── 🚨 La gerarchia, decisa dal committente ────────────────────────────────
 *
 *     CREA  →  Open Food Facts  →  utenti
 *
 * *«Mi pare banale che i risultati qualificati debbano battere i risultati non
 * qualificati.»*
 *
 * | Fonte | Rango | Cos'e' |
 * |---|---|---|
 * | `CREA:` | **3** | Misure di laboratorio di un ente di ricerca pubblico |
 * | `OFF:` | **2** | Etichette trascritte da volontari, con un codice a barre a cui rispondere |
 * | utente | **1** | Quello che una persona ha digitato sul telefono |
 *
 * ── ⚠️ Il difetto che ha reso necessaria questa classe ────────────────────
 *
 * Dopo la prima importazione completa le righe CREA erano scese da **832 a
 * 700**: 132 alimenti misurati in laboratorio erano stati rimpiazzati da
 * prodotti confezionati con lo stesso nome normalizzato. 🚨 `updateOrCreate`
 * sulla chiave fa esattamente questo e non se ne lamenta — sovrascrive fonte,
 * note e i quattro macro. Nessun errore, nessun avviso: solo dati peggiori al
 * posto di dati migliori.
 *
 * 💡 **La regola sta qui e in nessun altro posto.** Ripetuta nei tre comandi
 * che scrivono nel catalogo, sarebbe divergente entro un mese — e la
 * divergenza si vedrebbe solo confrontando i conteggi, cioe' mai.
 */
class GerarchiaFonti
{
    /**
     * I prefissi di `foods.fonte`, e stanno qui perche' non finiscano scritti
     * a mano in due posti che poi divergono.
     */
    public const PREFISSO_CREA = 'CREA:';

    public const PREFISSO_OFF = 'OFF:';

    public const RANGO_CREA = 3;

    public const RANGO_OFF = 2;

    public const RANGO_UTENTE = 1;

    /** Quanto pesa una riga, in base a chi l'ha scritta. */
    public function rango(?string $fonte): int
    {
        if ($fonte === null) {
            return self::RANGO_UTENTE;
        }

        if (str_starts_with($fonte, self::PREFISSO_CREA)) {
            return self::RANGO_CREA;
        }

        if (str_starts_with($fonte, self::PREFISSO_OFF)) {
            return self::RANGO_OFF;
        }

        return self::RANGO_UTENTE;
    }

    /**
     * Se una scrittura di rango `$nuovo` puo' sostituire la riga esistente.
     *
     * ── Le tre risposte, e il perche' di ciascuna ─────────────────────────
     *
     * **Rango piu' alto → si sovrascrive.** Un'importazione CREA che trova un
     * alimento digitato da qualcuno lo sostituisce: e' il senso della
     * gerarchia. ⚠️ Anche se quell'utente ci teneva: il suo dato era una stima,
     * questo e' una misura.
     *
     * **Rango piu' basso → non si tocca.** Un utente non sovrascrive mai CREA
     * o Open Food Facts, e Open Food Facts non sovrascrive mai CREA.
     *
     * **Stesso rango → dipende da chi sono.** Fra due fonti dichiarate uguali
     * (CREA che riscrive CREA, OFF che riscrive OFF) si aggiorna, perche' e' la
     * stessa fonte che si corregge. 🚨 Fra due utenti **vince chi c'era prima**
     * — la regola decisa il 17/08 — con l'unica eccezione del manuale che
     * promuove una voce creata dall'AI: una stima del modello e' un'ipotesi, un
     * numero digitato e' qualcuno che ha letto l'etichetta.
     */
    public function puoScrivere(?Food $esistente, int $nuovo, ?string $origineNuova = null): bool
    {
        if ($esistente === null) {
            return true;
        }

        $vecchio = $this->rango($esistente->fonte);

        if ($nuovo !== $vecchio) {
            return $nuovo > $vecchio;
        }

        if ($nuovo > self::RANGO_UTENTE) {
            // La stessa fonte che si aggiorna da sola.
            return true;
        }

        // 💡 Fra utenti: il manuale promuove l'AI, una volta sola. Dopo,
        // `origine` e' `manuale` e questa condizione non e' piu' vera.
        return $esistente->origine === Food::ORIGINE_AI
            && $origineNuova === Food::ORIGINE_MANUALE;
    }
}
