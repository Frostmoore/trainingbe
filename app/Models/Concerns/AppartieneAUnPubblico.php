<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Promozione;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 🎯 **Una riga che descrive il pubblico di qualcosa** — blocco B4, 24/09/2026.
 *
 * Lo usano `PromozioneBersaglio` e `PromozioneCondizione`, che dal 24/09 non
 * servono più solo alle promozioni ma anche agli **avvisi in-app**.
 *
 * ══ 🚨 IL PONTE CON `promozione_id`, E PERCHE' NON E' DEBITO INUTILE ════════
 *
 * ⛔ Il 24/09 ho cambiato `Promozione::bersagli()` da `hasMany` a `morphMany` e
 * **47 test sono diventati rossi in un colpo**: fabbriche, seeder e mezzo
 * progetto creano quelle righe passando `promozione_id`, e con il morph vuoto
 * la promozione **non trovava più nessuno**.
 *
 * 🚨 Il sintomo non diceva niente di tutto questo: *«Questa offerta non è per il
 * tuo account»*. ⚠️ Cioè una promozione che **esiste, è attiva, ha i suoi
 * bersagli scritti** e non li vede più.
 *
 * 💡 Quindi la riga si aggancia da sola: chi passa `promozione_id` ottiene
 * comunque il morph riempito. ⛔ **Non è un cerotto**: è il modo in cui si
 * cambia una colonna viva — prima le due strade convivono, poi si toglie la
 * vecchia quando nessuno la usa più. Toglierla subito voleva dire riscrivere
 * ogni chiamante nello stesso commit, senza reti.
 */
trait AppartieneAUnPubblico
{
    public static function bootAppartieneAUnPubblico(): void
    {
        static::creating(function ($riga): void {
            if ($riga->pubblico_id !== null) {
                return;
            }

            if ($riga->promozione_id !== null) {
                $riga->pubblico_type = Promozione::class;
                $riga->pubblico_id = $riga->promozione_id;
            }
        });
    }

    /** 💡 Di chi è questo pubblico: una promozione, o un avviso. */
    public function pubblico(): MorphTo
    {
        return $this->morphTo();
    }
}
