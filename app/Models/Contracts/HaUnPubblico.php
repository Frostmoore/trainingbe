<?php

declare(strict_types=1);

namespace App\Models\Contracts;

use App\Models\PromozioneBersaglio;
use App\Models\PromozioneCondizione;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 🎯 **Qualcosa che ha un pubblico** — blocco B4, 24/09/2026.
 *
 * ══ 🚨 PERCHE' ESISTE QUESTA INTERFACCIA ════════════════════════════════════
 *
 * `ChiLaRiceve` sa rispondere a una domanda difficile — *«questa persona
 * rientra?»* — con i bersagli in **OR**, le esclusioni che vincono su tutto e le
 * condizioni in **AND**. ⚠️ Quella domanda serve **identica** a due cose
 * diverse: una promozione e un avviso in-app.
 *
 * ⛔ Senza un contratto ci sarebbero due strade: duplicare il servizio (e vederlo
 * divergere al primo ritocco) oppure far puntare l'avviso a una **promozione
 * finta**, sporcando l'elenco delle promozioni di righe che promozioni non sono.
 *
 * 💡 Con questa interfaccia il servizio non sa e non deve sapere **di cosa** sta
 * calcolando il pubblico: gli basta che abbia bersagli e condizioni.
 */
interface HaUnPubblico
{
    /**
     * I bersagli, in **OR** fra loro. ⚠️ Una riga con `escludi` **toglie**, e
     * vince su tutte.
     *
     * @return MorphMany<PromozioneBersaglio, covariant static>
     */
    public function bersagli(): MorphMany;

    /**
     * I filtri in **AND** sopra i bersagli.
     *
     * @return MorphMany<PromozioneCondizione, covariant static>
     */
    public function condizioni(): MorphMany;
}
