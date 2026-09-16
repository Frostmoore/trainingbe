<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\ChiSiVede;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🆕 **Lo spazio in cui si sta lavorando, nel pannello** — 3b-AH.1-quater.8,
 * 16/09/2026.
 *
 * ══ 🚨 PERCHE' ESISTE ══════════════════════════════════════════════════════
 *
 * 📌 Da AH.1-quater una persona può avere **due cappelli**: un giro di allievi
 * suo — dove vive il suo `users.tenant_id` — e il ruolo di `trainer` **dentro
 * una palestra**. Sono indipendenti, ed è il caso che il prodotto vuole.
 *
 * ⛔ `ResolveTenant` mette nel contesto **sempre e solo** `$user->tenant`, cioè
 * lo spazio suo. Per quella persona il pannello della palestra era quindi una
 * porta che si apriva su una **stanza vuota**: non risultava un trainer di
 * quella palestra, e ogni scoping la trattava come «nessuno dei due».
 *
 * ══ ⚖️ E PERCHE' NON VIOLA ADR-04 ══════════════════════════════════════════
 *
 * 🚨 ADR-04 dice che il tenant **non arriva mai dal client**: né header, né
 * parametro, né corpo. ⚠️ Qui la scelta la fa la persona, e va detto chiaro —
 * ma **non è il client a decidere il permesso**:
 *
 * 1. la scelta vive in **sessione**, non in un parametro di richiesta;
 * 2. ogni richiesta la **ricontrolla** contro `ChiSiVede::spaziDi()`, cioè il
 *    proprio spazio più le palestre dove quella persona ha davvero il ruolo;
 * 3. se il controllo non passa — ruolo revocato, palestra chiusa, sessione
 *    copiata — si ricade in silenzio sul proprio spazio.
 *
 * 💡 Cioè: il client può **chiedere**, mai **stabilire**. Il giorno in cui la
 * palestra gli toglie il ruolo, la scelta smette di valere da sola, senza che
 * nessuno debba ricordarsi di ripulire la sessione.
 *
 * ══ ⚠️ DOVE STA NELLA CATENA, E PERCHE' ════════════════════════════════════
 *
 * **Dopo `ResolveTenant` e prima di `Authenticate`.** Dopo il primo perché lo
 * deve poter sovrascrivere; prima del secondo perché Filament chiama
 * `canAccessPanel()` dentro `Authenticate`, e quel metodo guarda i ruoli — che
 * adesso si leggono nello spazio del contesto. Invertirli vorrebbe dire
 * decidere chi entra guardando lo spazio sbagliato.
 *
 * ⛔ **E non si registra nell'API**: lì il contesto deve restare quello
 * dell'utente e basta, senza nessuna scelta da nessuna parte.
 */
class SpazioDelPannello
{
    /** La chiave in sessione. */
    public const CHIAVE = 'spazio_del_pannello';

    public function __construct(
        private readonly TenantContext $contesto,
        private readonly ChiSiVede $chiSiVede,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $utente = $request->user();

        if (! $utente instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $scelto = (int) $request->session()->get(self::CHIAVE, 0);

        if ($scelto === 0 || $scelto === $utente->tenant_id) {
            return $next($request);
        }

        /*
         * ⚠️ `withoutGlobalScopes()`: il `TenantScope` su `tenants` filtra sul
         * contesto, che in questo istante è ancora quello vecchio — la palestra
         * che sto per scegliere non si troverebbe.
         */
        $palestra = Tenant::withoutGlobalScopes()->find($scelto);

        /*
         * 🚨 **Tre ragioni per lasciar perdere la scelta, e una sola uscita.**
         *
         * ⚠️ La terza — la palestra sospesa — non è pignoleria: `EnsureTenantActive`
         * gira **dopo** di qui e chiude il pannello a chi ha in mano un tenant
         * inattivo. Con la scelta ancora in sessione quella persona resterebbe
         * **chiusa fuori da tutto**, compresa la pagina per tornare a casa sua:
         * un vicolo cieco da cui si esce solo svuotando i cookie.
         *
         * 💡 Così invece la palestra sospesa la sfila da sola, e lei si ritrova
         * nel proprio spazio — che è suo e funziona.
         */
        if ($palestra === null
            || ! $palestra->isActive()
            || ! $this->chiSiVede->spaziDi($utente)->contains($scelto)) {
            /*
             * ⚠️ **Si dimentica**, invece di lasciarla lì a fallire a ogni
             * richiesta. 💡 Chi ha perso il ruolo si ritrova nel proprio spazio
             * e basta: è la cosa giusta e non ha bisogno di spiegazioni.
             */
            $request->session()->forget(self::CHIAVE);

            return $next($request);
        }

        $this->contesto->set($palestra);

        return $next($request);
    }
}
