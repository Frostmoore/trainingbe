<?php

declare(strict_types=1);

namespace App\Filament\Gym\Widgets;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\Widget;

/**
 * 💳 **«Devi pagare, ed ecco come»** — 27/09/2026, decisione del committente.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * > *«se una palestra o un free trainer non hanno pagato, devono poter
 * > accedere a app e pannello … nel pannello devono avere qualcosa che gli
 * > comunichi che devono pagare, con un modo per permettere loro di pagare»*.
 *
 * ⛔ Fino al 27/09 chi non aveva pagato **non entrava affatto** nel pannello:
 * `canAccessPanel('admin')` pretendeva `tenant->isActive()`. 🚨 Cioè si
 * chiudeva fuori proprio chi doveva rientrare per pagare — *«sennò non mi
 * possono proprio pagare»*.
 *
 * 💡 Ora si entra, e la prima cosa che si vede è questo.
 *
 * ══ ⚠️ PERCHE' UN WIDGET IN CIMA E NON UNA NOTIFICA ═══════════════════════
 *
 * ⛔ Una notifica si chiude e non torna: chi la scarta una volta non la rivede
 * e non sa più perché metà delle cose non funziona. 💡 Questo resta lì finché
 * la situazione non cambia, ed è **il primo elemento** del cruscotto
 * (`$sort = -100`).
 *
 * ⚖️ Non è un muro: tutto il resto del pannello resta raggiungibile. La
 * decisione era *«entra, e vede come pagare»*, non *«entra e non può fare
 * niente»*.
 */
class AvvisoDaPagare extends Widget
{
    protected string $view = 'filament.gym.widgets.avviso-da-pagare';

    /**
     * 🔝 Prima di tutto il resto: se il servizio è sospeso, è la cosa più
     * importante della pagina.
     */
    protected static ?int $sort = -100;

    protected int|string|array $columnSpan = 'full';

    /**
     * ⛔ **Non pigro** — 28/09/2026, 63.A.
     *
     * 🚨 Di serie Filament carica i widget **dopo** la pagina, con una seconda
     * richiesta. ⛔ Per questo riquadro è sbagliato due volte:
     *
     * 1. 👁️ **Per chi legge**: è la cosa più importante della pagina e arriverebbe
     *    ultima, comparendo con uno scatto sotto gli occhi di qualcuno che nel
     *    frattempo ha già cominciato a cliccare altrove.
     * 2. 🚨 **Per chi verifica**: caricato dopo, **non è nella pagina**. Un test
     *    che apre `/admin` e cerca l'avviso non lo trova, e il modo comodo di
     *    farlo passare sarebbe interrogare il widget da solo — cioè tornare
     *    esattamente all'errore che ha fatto sembrare funzionante la `v36.29.0`.
     *
     * 💡 Con `false` il riquadro è dentro l'HTML della pagina, e la prova che il
     * cliente lo vede è la stessa prova che lo vede il test.
     */
    protected static bool $isLazy = false;

    /**
     * ⚠️ **Si mostra solo quando serve.** ⛔ Un avviso che c'è sempre non lo
     * legge nessuno, e quando conta davvero è indistinguibile dallo sfondo.
     */
    public static function canView(): bool
    {
        return self::spazio()?->isActive() === false;
    }

    /** Il nome di chi deve pagare, per non scrivere «la tua palestra» a un trainer. */
    public function getNome(): string
    {
        return self::spazio()?->name ?? 'Il tuo spazio';
    }

    /**
     * 🔎 Lo spazio in cui si sta lavorando adesso.
     *
     * ⚠️ Dal **contesto**, non da `auth()->user()->tenant`: chi porta due
     * cappelli lavora in più spazi, e l'avviso deve parlare di **quello che ha
     * davanti** — non di casa sua.
     */
    private static function spazio(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }
}
