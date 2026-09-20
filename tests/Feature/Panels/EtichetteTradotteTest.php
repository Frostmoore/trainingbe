<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 🈯 **Nel pannello non si leggono chiavi grezze** — 20/09/2026.
 *
 * ⛔ Nel giro del 19/09 il pannello mostrava `filament-panels::layout.topbar.label`
 * e altre due: il pacchetto italiano di Filament non ha quelle chiavi, e
 * `APP_FALLBACK_LOCALE=it` non lascia niente su cui ripiegare.
 *
 * 🚨 Questo test serve **agli aggiornamenti**: se un domani Filament rinomina
 * o aggiunge chiavi di layout, la chiave grezza torna a schermo e nessuno se
 * ne accorge — non è un errore, è solo testo brutto che sembra un guasto.
 */
class EtichetteTradotteTest extends TestCase
{
    #[Test]
    public function le_etichette_di_struttura_del_pannello_sono_in_italiano(): void
    {
        app()->setLocale('it');

        foreach ([
            'filament-panels::layout.skip_to_content.label',
            'filament-panels::layout.navigation.label',
            'filament-panels::layout.topbar.label',
        ] as $chiave) {
            $testo = __($chiave);

            $this->assertNotSame($chiave, $testo, "«{$chiave}» non è tradotta: a schermo si legge la chiave.");
            $this->assertMatchesRegularExpression('/^[A-ZÀÈÉÌÒÙ][a-zàèéìòù ]+$/u', (string) $testo);
        }
    }
}
