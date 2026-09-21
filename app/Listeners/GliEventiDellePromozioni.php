<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\EventoDiPromozione;
use App\Models\InvitoInPalestra;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Promozioni\GliEventi;

/**
 * ⚡ **Dove nascono gli eventi delle promozioni che non passano da Stripe** —
 * 24/09/2026, P6 di `plan_promozioni.md`.
 *
 * Agganciati ai **modelli** e non ai controller, di proposito: una persona si
 * registra dall'app, dal social, dal sito, da un invito di palestra o di
 * trainer — cinque strade. ⛔ Un aggancio per strada vorrebbe dire che la sesta,
 * quando arriverà, non regalerà niente senza che nessuno se ne accorga.
 *
 * | Modello | Quando | Evento |
 * |---|---|---|
 * | `User` | `created` | `registrazione` |
 * | `InvitoInPalestra` | `used_at` scritto | `ingresso_in_palestra` (a chi entra) + `invito_accettato` (a chi aveva invitato) |
 * | `TrainerInvite` | `used_at` scritto | `invito_accettato` (al trainer) |
 *
 * ⚠️ `GliEventi::succede()` aspetta il commit e non lancia mai: registrare una
 * persona non deve fallire per colpa di una promozione.
 */
final class GliEventiDellePromozioni
{
    public static function registra(): void
    {
        User::created(static function (User $utente): void {
            app(GliEventi::class)->succede(EventoDiPromozione::Registrazione, $utente, (string) $utente->getKey());
        });

        InvitoInPalestra::updated(static function (InvitoInPalestra $invito): void {
            if (! $invito->wasChanged('used_at') || $invito->used_at === null || $invito->accepted_by === null) {
                return;
            }

            $eventi = app(GliEventi::class);
            $entrato = User::withoutGlobalScopes()->find($invito->accepted_by);
            $invitante = $invito->invitato_da !== null ? User::withoutGlobalScopes()->find($invito->invitato_da) : null;

            if ($entrato !== null) {
                $eventi->succede(EventoDiPromozione::IngressoInPalestra, $entrato, 'invito-palestra:'.$invito->getKey());
            }

            if ($invitante !== null) {
                $eventi->succede(EventoDiPromozione::InvitoAccettato, $invitante, 'invito-palestra:'.$invito->getKey());
            }
        });

        TrainerInvite::updated(static function (TrainerInvite $invito): void {
            if (! $invito->wasChanged('used_at') || $invito->used_at === null) {
                return;
            }

            $trainer = User::withoutGlobalScopes()->find($invito->trainer_id);

            if ($trainer !== null) {
                app(GliEventi::class)->succede(EventoDiPromozione::InvitoAccettato, $trainer, 'invito-trainer:'.$invito->getKey());
            }
        });
    }
}
