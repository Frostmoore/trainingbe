<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Filament\Support\Colors\Color;

/**
 * I colori della palestra applicati al pannello — B3.1.
 *
 * 🚨 **Perche' il pannello e' brandizzato e quello di piattaforma no.**
 * Il gym_admin deve vedere il proprio marchio, non il nostro: e' meta' del
 * valore percepito di un prodotto white-label. Il pannello `/god` invece resta
 * volutamente neutro e di un colore diverso, cosi' chi amministra entrambi
 * capisce a colpo d'occhio dove sta agendo prima di cancellare qualcosa.
 *
 * ⚠️ **Va risolto a ogni richiesta, non alla registrazione del pannello.**
 * `PanelProvider::panel()` gira una volta sola, quando ancora non c'e' nessun
 * utente autenticato: leggere il tenant li' significherebbe applicare i colori
 * della prima palestra che apre il pannello a **tutte** le altre. Per questo la
 * tinta si calcola dentro un `boot()` del pannello, che gira per richiesta.
 */
final class PanelBranding
{
    /** Il colore di riserva quando la palestra non ne ha uno valido. */
    public const DEFAULT_PRIMARY = '#0F766E';

    /**
     * La tavolozza Filament a partire dal colore della palestra.
     *
     * @return array<string, array<int, string>|string>
     */
    public static function colorsFor(?Tenant $tenant): array
    {
        $primario = self::hexValido($tenant?->color_primary) ?? self::DEFAULT_PRIMARY;
        $accento = self::hexValido($tenant?->color_accent);

        $tavolozza = [
            'primary' => Color::hex($primario),
        ];

        // L'accento diventa il colore «danger»? No: il rosso degli errori non si
        // tocca. L'accento va su `info`, che e' decorativo — un pulsante di
        // conferma color pastello e' una questione di gusto, un avviso di
        // pericolo che non sembra pericoloso e' un guasto.
        if ($accento !== null) {
            $tavolozza['info'] = Color::hex($accento);
        }

        return $tavolozza;
    }

    /**
     * Un esadecimale utilizzabile, o `null`.
     *
     * Filament esplode su un colore malformato, e il colore arriva da un campo
     * che qualcuno compila a mano dal pannello di piattaforma: un refuso li'
     * renderebbe inaccessibile il pannello di quella palestra, cioe' un
     * disservizio per un cliente causato da una virgola.
     */
    public static function hexValido(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }

        $hex = trim($hex);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 ? $hex : null;
    }
}
