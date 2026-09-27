<?php

declare(strict_types=1);

namespace Tests\Feature\Accesso;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 💬 **I messaggi dicono il vero** — 63.E, 27/09/2026.
 *
 * ══ 🚨 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * ⛔ **Due dei tre guasti del 26–27/09 mostravano una diagnosi FALSA:**
 *
 * | Cosa diceva | A chi | La verità |
 * |---|---|---|
 * | «la tua palestra è sospesa» | a chi **non ha palestre** | il suo spazio personale non esisteva |
 * | «non riusciamo a caricare il tuo spazio» | al super admin | non l'aveva **mai** avuto |
 *
 * 🚨 **Un messaggio sbagliato costa più di un errore muto**: manda la persona a
 * cercare una causa che non c'è, e chi l'ha scritto a cercare nel posto sbagliato.
 * 💡 Il committente ci ha perso una notte, e io con lui.
 *
 * ══ 🔑 COSA LEGA UN CODICE ALLA SUA CAUSA ══════════════════════════════════
 *
 * L'app sceglie **cosa mostrare** in base al campo `code` della risposta, non al
 * testo. ⛔ Quindi un codice che esce per la causa sbagliata è una schermata
 * sbagliata — e nessun test lo vedeva.
 */
class IMessaggiDiconoIlVeroTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🎯 **`tenant_inactive` esce SOLO se lo spazio è davvero inattivo.**
     *
     * ⚠️ Si prova su una rotta finanziata dalla palestra (`ai/usage`), perché sono
     * le sole che pretendono lo spazio attivo. 💡 Le altre — allenamenti, diario,
     * backup — non lo pretendono, ed è il senso della decisione del 26/09: *«perde
     * solo ciò che la palestra paga»*.
     */
    #[Test]
    public function tenant_inactive_esce_solo_con_lo_spazio_davvero_sospeso(): void
    {
        $palestra = $this->creaPalestra('Attiva', 'attiva-vero');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@attiva-vero.test');

        /* ✅ Il controllo: con lo spazio attivo quel codice NON esce. */
        $risposta = $this->actingAs($iscritto->fresh(), 'sanctum')->getJson('/api/v1/ai/usage');

        self::assertNotSame(
            'tenant_inactive',
            $risposta->json('code'),
            '🚨 Dice «abbonamento non attivo» a chi ha lo spazio attivo.',
        );

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->actingAs($iscritto->fresh(), 'sanctum')
            ->getJson('/api/v1/ai/usage')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_inactive');
    }

    /**
     * 🚨 **E `tenant_inactive` NON esce a chi non ha nessuno spazio sospeso.**
     *
     * ⛔ È il primo dei due guasti: *«mi dice che la mia palestra è sospesa»*
     * detto a chi non ha palestre. ⚠️ Il codice giusto per «non ho trovato il tuo
     * spazio» è `tenant_missing`, ed è un'altra cosa: una richiede di pagare,
     * l'altra è un difetto nostro.
     */
    #[Test]
    public function a_chi_non_ha_palestre_non_si_dice_che_la_palestra_e_sospesa(): void
    {
        $persona = app(CreaTenantPersonale::class)(
            'Senza Palestra',
            'senza@palestra.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        $risposta = $this->actingAs($persona->fresh(), 'sanctum')->getJson('/api/v1/ai/usage');

        self::assertNotSame(
            'tenant_inactive',
            $risposta->json('code'),
            '🚨 A chi ha solo uno spazio personale attivo si dice che la palestra è sospesa. '
            .'⛔ È il guasto del 26/09: una diagnosi falsa manda a cercare una causa che non c\'è.',
        );
    }

    /**
     * 🦸 **E al super admin non si dice né l'una né l'altra cosa.**
     *
     * ⛔ È il secondo guasto: *«non riusciamo a caricare il tuo spazio»* a chi non
     * l'aveva mai avuto. ⚠️ Dal 27/09 ne ha uno personale, quindi non c'è più
     * niente da non trovare — e questo test è quello che lo tiene vero.
     */
    #[Test]
    public function al_super_admin_non_si_dice_che_manca_il_suo_spazio(): void
    {
        $god = $this->creaSuperAdmin('god@messaggi.test');

        $risposta = $this->actingAs($god->fresh(), 'sanctum')->getJson('/api/v1/ai/usage');

        self::assertNotContains(
            $risposta->json('code'),
            ['tenant_missing', 'tenant_inactive'],
            '🚨 Al super admin si dice che il suo spazio manca o è sospeso.',
        );
    }

    /**
     * ⚖️ **E le rotte che NON dipendono dalla palestra non usano mai quel codice.**
     *
     * 🔑 È la metà che protegge la decisione del 26/09: con la palestra sospesa si
     * tiene tutto ciò che è proprio. ⛔ Se una di queste rotte cominciasse a
     * rispondere `tenant_inactive`, la persona si vedrebbe chiedere di pagare per
     * guardare **i propri** dati.
     */
    #[Test]
    public function le_rotte_proprie_non_dicono_mai_di_pagare(): void
    {
        $palestra = $this->creaPalestra('Sospesa Messaggi', 'sospesa-messaggi');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@sospesa-messaggi.test');

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        foreach (['/api/v1/me', '/api/v1/workout-plans'] as $rotta) {
            $risposta = $this->actingAs($iscritto->fresh(), 'sanctum')->getJson($rotta);

            self::assertNotSame(
                'tenant_inactive',
                $risposta->json('code'),
                "🚨 {$rotta} chiede di pagare per i dati della persona. "
                .'⛔ «Perde solo ciò che la palestra paga» — il committente, 26/09.',
            );
        }

        self::assertInstanceOf(Tenant::class, $palestra->fresh());
    }
}
