<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;

/**
 * Consigli o piano — N19.
 *
 * ── 🚨 La differenza non e' di nome, e' di FORMA ───────────────────────────
 *
 * In Italia l'elaborazione di una dieta e' un atto riservato a medici, biologi
 * nutrizionisti e dietisti (art. 348 c.p.). ⚠️ E il punto non e' l'etichetta
 * che ci mettiamo sopra: e' cosa contiene il documento.
 *
 * «Mangia pollo, riso e broccoli» e' un **consiglio**.
 * «200 g di pollo alle 13:00, giorno 3» e' una **dieta**, comunque la si chiami.
 *
 * 💡 Per questo la distinzione vive in un enum e non in un flag: chi legge il
 * codice deve trovarci scritto **perche'** esistono due cose, non solo che ce
 * ne sono due.
 */
enum TipoPianoAlimentare: string
{
    /**
     * Un elenco di alimenti. Niente quantita', niente orari, niente giorni.
     *
     * 💡 E' quello che un trainer puo' dare, e lo puo' dare da sempre.
     */
    case Consigli = 'consigli';

    /**
     * Un piano vero: grammi, pasti, giorni.
     *
     * 🚨 Lo puo' comporre **solo** chi ha il titolo — o lo importa la persona
     * stessa da un PDF fatto da un professionista abilitato (N20).
     */
    case Piano = 'piano';

    public function label(): string
    {
        return match ($this) {
            self::Consigli => 'Consigli alimentari',
            self::Piano => 'Piano alimentare',
        };
    }

    /**
     * Chi puo' scrivere questo tipo.
     *
     * ── 🚨 La regola sta QUI, e in nessun altro posto ──────────────────────
     *
     * ⚠️ Ripetuta nel controller, nel pannello e nel `FormRequest`, fra sei
     * mesi sarebbero tre regole diverse — e quella che conta e' **la piu'
     * permissiva delle tre**, cioe' quella che qualcuno ha allentato per far
     * passare un caso e non ha piu' ristretto.
     */
    public function scrivibileDa(User $chi): bool
    {
        return match ($this) {
            /*
             * 💡 I consigli li puo' dare chiunque segua qualcuno: un trainer,
             * un trainer indipendente, il titolare della palestra. Non e' un
             * atto riservato.
             */
            self::Consigli => $chi->isTrainer()
                || $chi->isFreeTrainer()
                || $chi->isGymAdmin()
                // 💡 Chi puo' prescrivere puo' certamente suggerire. L'inverso
                // no, ed e' tutta la ragione di questo enum.
                || $chi->isNutrizionista(),

            /*
             * 🚨 **Il piano vero: solo chi ha il titolo.**
             *
             * ⚠️ Il ruolo e' **predisposto e non attivo** (N22.8): nessun
             * percorso reale lo assegna, quindi in pratica oggi questa riga non
             * lascia passare nessuno. Ma esiste, ed e' il posto — l'unico — in
             * cui il giorno dell'attivazione non si dovra' cambiare niente.
             *
             * 💡 La struttura a grammi la riempie anche l'importazione di N20,
             * dove il piano l'ha fatto un abilitato fuori dall'app e noi
             * facciamo da quaderno.
             */
            self::Piano => $chi->isNutrizionista(),
        };
    }

    /**
     * Cosa puo' contenere questo tipo.
     *
     * ⚠️ `false` vuol dire: niente `days`, niente `meals`, niente grammi. Solo
     * un elenco di alimenti.
     */
    public function ammetteQuantita(): bool
    {
        return $this === self::Piano;
    }
}
