<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Avvisi\Pages;

use App\Filament\God\Resources\Avvisi\AvvisoResource;
use App\Jobs\MandaLAvvisoInPush;
use Filament\Resources\Pages\EditRecord;

class EditAvviso extends EditRecord
{
    protected static string $resource = AvvisoResource::class;

    /**
     * 📲 **Acceso l'avviso, parte la push** — blocco B4, 24/09/2026.
     *
     * 📌 Il committente, il 24/09: *«sì, gli avvisi devono mandare anche
     * push»*.
     *
     * ⚠️ **Solo quando si accende**, non a ogni salvataggio: correggere un
     * refuso in un avviso già acceso non deve rimandare la notifica a tutti.
     *
     * ⏳ Oggi non parte niente davvero: Firebase non è configurato
     * (`DA-FARE.md` voce 20). 💡 Il pezzo c'è lo stesso, così il giorno che
     * le chiavi arrivano non si va a cercare dove agganciarlo.
     */
    /*
     * 🚨 **Il «prima» si cattura PRIMA**, e non è pignoleria: dopo il
     * salvataggio `getOriginal()` restituisce già i valori **nuovi**, quindi
     * «era spento?» rispondeva sempre di no e la push non partiva mai.
     *
     * ⚠️ L'ha trovato un test che pretendeva la coda piena. ⛔ Da fuori non si
     * sarebbe visto niente: l'avviso si accendeva, la modale compariva, e la
     * notifica semplicemente non c'era.
     */
    private bool $eraSpento = false;

    protected function beforeSave(): void
    {
        $this->eraSpento = ! (bool) $this->record->getOriginal('attivo');
    }

    protected function afterSave(): void
    {
        if ($this->eraSpento && $this->record->attivo && $this->record->anche_push) {
            MandaLAvvisoInPush::dispatch((int) $this->record->getKey());
        }
    }
}
