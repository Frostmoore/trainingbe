<?php

declare(strict_types=1);

namespace App\Services\Scoperta;

use App\Models\Comune;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Il selettore di citta' — 18/08/2026. **M1.2.**
 *
 * ── 🚨 L'ordinamento e' tutto il servizio ──────────────────────────────────
 *
 * Trovare i comuni che cominciano per «mil» e' banale. Il punto e' **quale
 * mettere per primo**: in ordine alfabetico verrebbero `Milanesi`, `Milena`,
 * `Milazzo`, e `Milano` compare quarto. ⚠️ Un selettore di citta' in cui il
 * capoluogo non e' fra i primi e' un selettore che le persone smettono di usare
 * dopo due tentativi.
 *
 * 💡 Quindi l'ordine e', nell'ordine: **corrispondenza esatta**, poi **inizia
 * con**, poi **contiene**; e a parita', **il comune piu' grande per primo**.
 *
 * ── ⚠️ Qui il `LIKE '%x%'` e' ammesso, e sul catalogo alimenti no ──────────
 *
 * Sembra una contraddizione con la regola gia' scritta («su MySQL `LIKE 'x%'`
 * usa l'indice, `LIKE '%x%'` no»). Non lo e': quella regola vale per `foods`,
 * che ha **centinaia di migliaia** di righe, dove una scansione a ogni tasto
 * premuto e' un problema vero.
 *
 * 🚨 Qui le righe sono **ottomila**, e non cresceranno mai: l'Italia ha il numero
 * di comuni che ha. Una scansione completa di ottomila righe corte e' sotto il
 * millisecondo. Rinunciare al «contiene» per un principio applicato dove non
 * serve vorrebbe dire che chi digita `emilia` non trova `Reggio nell'Emilia` —
 * un difetto reale in cambio di un'ottimizzazione immaginaria.
 */
class RicercaComuni
{
    /** ⚠️ Sotto i due caratteri non si cerca: qualunque risposta sarebbe rumore. */
    public const MINIMO_CARATTERI = 2;

    public function __construct(private readonly ChiaveComune $chiavi) {}

    /**
     * I comuni che corrispondono al testo, i migliori per primi.
     *
     * @return Collection<int, Comune>
     */
    public function cerca(string $testo, int $limite = 15): Collection
    {
        $q = $this->chiavi->perCercare($testo);

        if (mb_strlen($q) < self::MINIMO_CARATTERI) {
            return new Collection;
        }

        /*
         * ⚠️ I caratteri speciali di `LIKE` vanno neutralizzati: chi digita `%`
         * in un campo di ricerca non sta chiedendo «tutti i comuni». Non e' un
         * problema di sicurezza — il valore e' comunque legato come parametro —
         * ma di risultato assurdo.
         *
         * 💡 `ChiaveComune` toglie gia' tutto cio' che non e' lettera o cifra,
         * quindi in pratica non arriva mai niente da neutralizzare. Sta qui lo
         * stesso perche' questa classe non deve dipendere da un dettaglio
         * dell'altra per essere corretta.
         */
        $like = addcslashes($q, '%_\\');

        /** @var Collection<int, Comune> $trovati */
        $trovati = Comune::query()
            ->attivi()
            ->where(fn (Builder $w) => $w
                ->where('chiave', 'like', "%{$like}%")
                ->orWhere('chiave_altro', 'like', "%{$like}%"))
            /*
             * 🚨 I tre gradini della pertinenza, dal migliore al peggiore.
             *
             * 💡 `chiave_altro` conta quanto `chiave`: chi vive a Bolzano e
             * digita `bozen` deve avere lo stesso trattamento di chi digita
             * `bolzano`, non essere retrocesso in fondo perche' ha usato l'altra
             * lingua ufficiale del suo comune.
             */
            ->orderByRaw(
                'CASE '.
                'WHEN chiave = ? OR chiave_altro = ? THEN 0 '.
                'WHEN chiave LIKE ? OR chiave_altro LIKE ? THEN 1 '.
                'ELSE 2 END',
                [$q, $q, "{$like}%", "{$like}%"],
            )
            /*
             * ⚠️ `COALESCE`: i nove comuni senza popolazione finirebbero in cima
             * con `NULL`, che in `ORDER BY ... DESC` MySQL mette per ultimo — ma
             * in `ASC` per primo, e basta cambiare idea sul verso per avere in
             * testa proprio i comuni di cui non si sa niente.
             */
            ->orderByRaw('COALESCE(popolazione, 0) DESC')
            ->orderBy('nome')
            ->limit($limite)
            ->get();

        return $trovati;
    }
}
