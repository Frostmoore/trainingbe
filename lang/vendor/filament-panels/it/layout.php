<?php

declare(strict_types=1);

/*
 * 🈯 **Le tre etichette che Filament non traduce in italiano** — 20/09/2026.
 *
 * ⛔ Nel pannello comparivano **le chiavi grezze**, non il testo:
 * `filament-panels::layout.topbar.label`, `...skip_to_content.label`,
 * `...navigation.label`. Le legge chi usa il pannello — visto nel giro del
 * 19/09 (U4 del referto).
 *
 * 🚨 **Non è un difetto nostro, ed è per questo che serviva questo file**: il
 * pacchetto italiano di `filament/filament` non ha quelle chiavi (le ha solo
 * `en`), e con `APP_FALLBACK_LOCALE` diverso da `en` non c'è niente su cui
 * ripiegare: Laravel stampa la chiave.
 *
 * 💡 Laravel **sovrappone** questo file a quello del pacchetto (non lo
 * sostituisce): qui stanno solo le chiavi che mancano. ⚠️ Aggiornando Filament
 * vale la pena ricontrollare: se un giorno le traduce lui, questo file diventa
 * inutile e si toglie.
 *
 * 💡 Sono etichette che si **sentono** più di quanto si vedano: le legge il
 * lettore di schermo, e «filament-panels::layout.navigation.label» letto ad
 * alta voce non vuol dire niente.
 */

return [
    'skip_to_content' => [
        'label' => 'Vai al contenuto',
    ],

    'navigation' => [
        'label' => 'Menu laterale',
    ],

    'topbar' => [
        'label' => 'Barra in alto',
    ],
];
