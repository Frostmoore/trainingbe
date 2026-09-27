<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Filament\Gym\Widgets\AvvisoDaPagare;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Filament\Pages\Dashboard as CruscottoDiFilament;
use Filament\Widgets\WidgetConfiguration;

/**
 * 🏠 **Il cruscotto della palestra** — e, con il servizio sospeso, **solo l'avviso**.
 *
 * ══ 📌 PERCHE' ESISTE UNA SOTTOCLASSE ═════════════════════════════════════
 *
 * 📌 Il committente, 27/09: *«sul pannello vede solo l'avviso e non può fare
 * nulla»*.
 *
 * ⛔ `SoloLAvvisoSeNonHaiPagato` impedisce di **raggiungere** le altre pagine, ma
 * il cruscotto resta raggiungibile — e deve. 🚨 Senza questa classe, chi non ha
 * pagato vedrebbe l'avviso **in mezzo ai numeri della palestra**: i posti
 * occupati, il giro della settimana, gli slot da decidere. ⚠️ Cioè una pagina che
 * dice *«è tutto sospeso»* mentre mostra un'attività che va avanti.
 *
 * ══ 💡 PERCHE' QUI E NON QUATTRO `canView()` ══════════════════════════════
 *
 * ⛔ La strada breve era aggiungere un `canView()` a ciascuno degli altri
 * riquadri. 🚨 Ma sono **quattro oggi**, e il quinto che qualcuno aggiungerà
 * l'anno prossimo comparirebbe — senza che nessun test lo noti, perché nessun
 * test sa che quel riquadro esiste.
 *
 * 🔑 Qui la regola è scritta al **rovescio**, e questo cambia tutto: non *«nascondi
 * questi»*, ma *«mostra solo l'avviso»*. 💡 Una lista di esclusione si dimentica
 * di aggiornare; una lista di inclusione **non può**.
 */
class Cruscotto extends CruscottoDiFilament
{
    /**
     * ⚠️ **Il percorso resta quello di sempre.** ⛔ Cambiarlo romperebbe ogni
     * collegamento salvato, ogni segnalibro e il rimando del middleware.
     */
    protected static string $routePath = '/';

    /**
     * @return array<class-string|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        if (self::spazio()?->isActive() === false) {
            return [AvvisoDaPagare::class];
        }

        return parent::getWidgets();
    }

    /**
     * 🔎 Lo spazio che si ha davanti **adesso**.
     *
     * ⚠️ Dal contesto e non da `auth()->user()->tenant`: chi porta due cappelli
     * lavora in più spazi, e il cruscotto deve parlare di quello che ha davanti.
     * 🔑 È la stessa lettura che fa `AvvisoDaPagare`, e deve restare la stessa:
     * se le due divergessero, si vedrebbe l'avviso senza i numeri, o i numeri
     * senza l'avviso.
     */
    private static function spazio(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }
}
