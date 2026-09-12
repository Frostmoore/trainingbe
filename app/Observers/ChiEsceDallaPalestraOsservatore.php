<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🚪 **Chi lascia un tenant si porta dietro i rapporti chiusi** — 3b-AH.1-ter
 * (12/09/2026).
 *
 * ══ 📌 LA REGOLA ══════════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«Se un trainer di una palestra lascia la palestra ma non è
 * un `free_trainer`, deve perdere anche tutti i suoi allievi»* — e quegli allievi
 * **restano alla palestra**, perché non erano suoi: erano iscritti della
 * palestra, affidati a lui.
 *
 * ══ 🚨 PERCHE' UN OSSERVATORE E NON UN'AZIONE DEL PANNELLO ════════════════
 *
 * ⛔ Un trainer se ne va per **più strade**: il pannello lo cancella, il super
 * admin lo cancella da `/god`, un comando di manutenzione lo chiude, e domani
 * ce ne sarà una quarta. ⚠️ Appendere la regola a una sola di quelle vorrebbe
 * dire che le altre lasciano in piedi rapporti di qualcuno che non c'è più — e
 * un rapporto in piedi **occupa uno slot** e **apre un canale di chat**.
 *
 * 💡 Qui la regola sta attaccata al **fatto** — questa persona se ne va — non a
 * un pulsante.
 *
 * ══ ⚠️ SI DISATTIVANO, NON SI CANCELLANO ══════════════════════════════════
 *
 * Decisione **D5**: *«il legame resta, la storia si conserva, il canale si
 * chiude»*. 💡 Lo storico di un allievo continua a dire chi lo seguiva e quando;
 * quello che finisce è il rapporto, non la memoria.
 *
 * ⛔ **E vale in tutte e due le direzioni**: i rapporti in cui questa persona era
 * il **trainer** e quelli in cui era l'**allievo**. Chiudere solo i primi
 * lascerebbe un trainer con uno slot occupato da qualcuno che non esiste più.
 */
class ChiEsceDallaPalestraOsservatore
{
    /**
     * ⚠️ **`deleting` e non `deleted`**: qui `users` usa `SoftDeletes`, quindi
     * la riga resta e la differenza sarebbe solo di ordine — ma il giorno in cui
     * qualcuno passasse a una cancellazione vera, `deleted` girerebbe con le
     * chiavi esterne già sparite.
     */
    public function deleting(User $utente): void
    {
        $this->chiudiIRapporti($utente, 'cancellazione');
    }

    /**
     * 🚨 **E anche quando viene solo spento**, che è la strada più usata.
     *
     * ⚠️ `is_active = false` non è una cancellazione, ed è **reversibile**: per
     * questo i rapporti si **disattivano** e non si perdono. 💡 Se domani quella
     * persona torna, la palestra li riattiva dal pannello — e la data di quando
     * erano stati chiusi resta scritta.
     *
     * ⛔ Senza questo ramo, un trainer spento avrebbe continuato a occupare gli
     * slot dei suoi allievi e a tenere aperti i canali di chat: sospeso per
     * tutti tranne che per le due cose che costano.
     */
    public function updated(User $utente): void
    {
        if ($utente->wasChanged('is_active') && $utente->is_active === false) {
            $this->chiudiIRapporti($utente, 'disattivazione');
        }
    }

    private function chiudiIRapporti(User $utente, string $perche): void
    {
        $id = (int) $utente->getKey();

        $quanti = app(TenantContext::class)->runWithoutTenant(
            fn (): int => DB::table('trainer_member')
                ->whereNull('disattivato_il')
                ->where(fn ($q) => $q->where('trainer_id', $id)->orWhere('member_id', $id))
                ->update(['disattivato_il' => now()]),
        );

        if ($quanti > 0) {
            Log::info('Rapporti trainer chiusi', [
                'user_id' => $id,
                'perche' => $perche,
                'quanti' => $quanti,
            ]);
        }
    }
}
