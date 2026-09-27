<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔒 **Chi entra in quale pannello** — voce 63.5/63.6, 27/09/2026.
 *
 * ══ 📌 LE DUE REGOLE, CON LE PAROLE DEL COMMITTENTE ═══════════════════════
 *
 * > *«NESSUNO deve NON POTER ACCEDERE al suo account. NESSUNO deve POTER
 * > ACCEDERE all'account di qualcun altro. Nessun utente semplice deve vedere
 * > alcun pannello che non sia solo ed esclusivamente il suo.»*
 *
 * 🚨 **Le due tirano in direzioni opposte, ed è lì che nascono i difetti.**
 * ⛔ Il 27/09 alle 2 di notte il rimedio sbagliato che era stato scritto
 * bloccava il super admin *«con un messaggio chiaro»*: risolveva la seconda
 * violando la prima.
 *
 * 💡 La formulazione che le tiene insieme: **si entra sempre; quello che si
 * vede dipende da chi sei.**
 *
 * ══ ⚠️ SI PROVA TENTANDO, NON GUARDANDO ══════════════════════════════════
 *
 * ⛔ Un test che controlla che «il menù non mostri la voce» non prova niente:
 * il menù è cosmesi, e l'indirizzo lo digita chiunque. 💡 Qui si chiede
 * `canAccessPanel()`, che è la porta vera.
 */
class ChiEntraNeiPannelliTest extends TestCase
{
    use RefreshDatabase;
    use CreaAmbiente;

    /** 🎯 Il super admin entra in `/god`. È l'unico. */
    #[Test]
    public function il_super_admin_entra_nel_pannello_god(): void
    {
        $god = $this->creaSuperAdmin('god@prova.test');

        self::assertTrue(
            $god->canAccessPanel(Filament::getPanel('god')),
            'Il super admin deve entrare nel suo pannello.',
        );
    }

    /**
     * 🔒 **Nessun altro ruolo entra in `/god`.**
     *
     * ⚠️ Si provano **tutti** i ruoli, non uno d'esempio: il giorno che se ne
     * aggiunge uno nuovo, questo test non lo coprirebbe da solo — ma almeno
     * copre tutti quelli che esistono oggi.
     */
    #[Test]
    public function nessun_altro_ruolo_entra_nel_pannello_god(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa');

        $ruoli = [
            UserRole::GymAdmin,
            UserRole::Trainer,
            UserRole::Member,
            UserRole::FreeTrainer,
            UserRole::FreeUser,
            UserRole::Nutrizionista,
        ];

        foreach ($ruoli as $ruolo) {
            $utente = $this->creaUtente(
                $palestra,
                $ruolo,
                strtolower($ruolo->value).'@alfa.test',
            );

            self::assertFalse(
                $utente->canAccessPanel(Filament::getPanel('god')),
                "⛔ Un {$ruolo->value} è entrato nel pannello god.",
            );
        }
    }

    /**
     * 🚨 **E NEMMENO chi ha un ruolo CHIAMATO `super_admin`.**
     *
     * ⛔ È il caso più pericoloso di tutti, ed è plausibile: i ruoli dentro una
     * palestra li crea chi la gestisce. Se il pannello god guardasse i **ruoli**
     * invece della **colonna**, a un titolare basterebbe crearsi un ruolo con
     * quel nome per entrare nella piattaforma di tutti.
     *
     * 💡 `isSuperAdmin()` legge `is_super_admin`, che **non è fillable** di
     * proposito: si concede solo da seeder o da migrazione, mai da una
     * richiesta.
     */
    #[Test]
    public function un_ruolo_chiamato_super_admin_non_apre_il_pannello_god(): void
    {
        $palestra = $this->creaPalestra('Furba', 'furba');
        $furbo = $this->creaUtente($palestra, UserRole::GymAdmin, 'furbo@furba.test');

        $this->ctx()->runAs($palestra, function () use ($palestra, $furbo): void {
            Role::create([
                'name' => 'super_admin',
                'guard_name' => 'web',
                'tenant_id' => $palestra->getKey(),
            ]);

            $furbo->unsetRelation('roles');
            $furbo->assignRole('super_admin');
        });

        /*
         * 🚨 **La precondizione, senza la quale il test è VACUO.**
         *
         * ⛔ Scoperto il 27/09: rimettendo il difetto (il pannello god che
         * guarda anche il **ruolo**) il test restava **verde**, perché il
         * ruolo non risultava assegnato e quindi non c'era niente da
         * intercettare. 💡 Un test che non parte dalla situazione che dice
         * di provare non prova niente.
         */
        $this->ctx()->runAs($palestra, function () use ($furbo): void {
            $fresco = \App\Models\User::withoutGlobalScopes()->findOrFail($furbo->getKey());
            $fresco->unsetRelation('roles');

            self::assertTrue(
                $fresco->hasRole('super_admin'),
                'Il ruolo non è stato assegnato: il test non proverebbe niente.',
            );
        });

        /*
         * 🚨 **Si prova DENTRO il contesto della palestra, non fuori.**
         *
         * ⛔ Fuori dal contesto `hasRole()` risponde sempre `false` — i ruoli
         * spatie sono legati al tenant — quindi provare lì significa provare
         * il caso **innocuo** e saltare quello pericoloso.
         *
         * 💡 Quando Filament risolve il pannello, il contesto **c'è**: è
         * quella la situazione in cui un controllo sbagliato aprirebbe la
         * porta. Scoperto il 27/09 rimettendo il difetto e vedendo il test
         * restare verde.
         */
        $this->ctx()->runAs($palestra, function () use ($furbo): void {
            $fresco = \App\Models\User::withoutGlobalScopes()->findOrFail($furbo->getKey());
            $fresco->unsetRelation('roles');

            self::assertFalse(
                $fresco->canAccessPanel(Filament::getPanel('god')),
                '🚨 Un ruolo chiamato super_admin ha aperto il pannello della piattaforma.',
            );
        });

        self::assertFalse(
            $furbo->fresh()->canAccessPanel(Filament::getPanel('god')),
            '🚨 E nemmeno fuori dal contesto.',
        );
    }

    /** 🔒 Un iscritto non entra in nessun pannello. */
    #[Test]
    public function un_iscritto_non_entra_in_nessun_pannello(): void
    {
        $palestra = $this->creaPalestra('Beta', 'beta');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@beta.test');

        foreach (['god', 'admin'] as $pannello) {
            self::assertFalse(
                $iscritto->canAccessPanel(Filament::getPanel($pannello)),
                "⛔ Un iscritto è entrato nel pannello {$pannello}.",
            );
        }
    }

    /**
     * 🔒 **Un account spento non entra da nessuna parte**, qualunque ruolo
     * avesse. ⚠️ È il controllo che si dimentica: disattivare qualcuno e
     * lasciargli la porta aperta è peggio che non disattivarlo, perché chi
     * l'ha fatto crede di averlo chiuso fuori.
     */
    #[Test]
    public function un_account_spento_non_entra_in_nessun_pannello(): void
    {
        $palestra = $this->creaPalestra('Gamma', 'gamma');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@gamma.test');

        self::assertTrue(
            $capo->canAccessPanel(Filament::getPanel('admin')),
            'Parte già senza accesso: il caso non prova niente.',
        );

        $capo->forceFill(['is_active' => false])->save();

        foreach (['god', 'admin'] as $pannello) {
            self::assertFalse(
                $capo->fresh()->canAccessPanel(Filament::getPanel($pannello)),
                "⛔ Un account spento è entrato nel pannello {$pannello}.",
            );
        }
    }

    /**
     * ⚠️ **Il titolare di una palestra SOSPESA: oggi NON entra.**
     *
     * 📌 Il committente, 26/09, decidendo cosa succede con una palestra
     * sospesa: *«entra, e vede come pagare»* — perché *«chi non riesce ad
     * entrare non paga, se ne va»*.
     *
     * 🚨 **Questo test documenta lo stato ATTUALE, che contraddice quella
     * decisione**: `canAccessPanel('admin')` pretende
     * `tenant?->isActive() === true`. ⛔ Finché resta così, il titolare che
     * vuole riattivare non ha da dove farlo.
     *
     * 💡 Quando la decisione verrà applicata, questo test va **ribaltato**: è
     * segnato come debito, non come comportamento giusto.
     */
    #[Test]
    public function il_titolare_di_una_palestra_sospesa_ENTRA(): void
    {
        $palestra = $this->creaPalestra('Sospesa', 'sospesa');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@sospesa.test');

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        self::assertTrue(
            $capo->fresh()->canAccessPanel(Filament::getPanel('admin')),
            '🚨 Chi non ha pagato deve poter entrare nel pannello: è da lì che paga. '
            .'«Sennò non mi possono proprio pagare» — il committente, 27/09.',
        );
    }

    /** 🔒 E chi non ha nessun ruolo non entra da nessuna parte. */
    #[Test]
    public function chi_non_ha_ruoli_non_entra_in_nessun_pannello(): void
    {
        $palestra = $this->creaPalestra('Delta', 'delta');
        $nessuno = $this->creaUtente($palestra, UserRole::FreeUser, 'nessuno@delta.test');

        $this->ctx()->runAs($palestra, function () use ($nessuno): void {
            $nessuno->syncRoles([]);
        });

        foreach (['god', 'admin'] as $pannello) {
            self::assertFalse(
                $nessuno->fresh()->canAccessPanel(Filament::getPanel($pannello)),
                "⛔ Chi non ha ruoli è entrato nel pannello {$pannello}.",
            );
        }
    }

    /**
     * 🔑 **E chi smette di essere super admin perde l'accesso SUBITO**, non al
     * prossimo ingresso. ⛔ Un permesso che sopravvive alla revoca è un
     * permesso che non è stato revocato.
     */
    #[Test]
    public function chi_smette_di_essere_super_admin_perde_subito_il_pannello(): void
    {
        $god = $this->creaSuperAdmin('exgod@prova.test');

        self::assertTrue($god->canAccessPanel(Filament::getPanel('god')));

        $god->forceFill(['is_super_admin' => false])->save();

        self::assertFalse(
            $god->fresh()->canAccessPanel(Filament::getPanel('god')),
            '⛔ Ha perso il ruolo e il pannello gli si apre ancora.',
        );
    }

    /**
     * 💳 **E dentro trova come pagare.**
     *
     * ⛔ Farlo entrare e non dirgli niente sarebbe peggio di tenerlo fuori:
     * vedrebbe metà delle cose non funzionare senza sapere perché.
     */
    #[Test]
    public function con_il_servizio_sospeso_il_pannello_mostra_come_pagare(): void
    {
        $palestra = $this->creaPalestra('Morosa', 'morosa');
        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->ctx()->runAs($palestra, function (): void {
            self::assertTrue(
                \App\Filament\Gym\Widgets\AvvisoDaPagare::canView(),
                '🚨 Servizio sospeso e nessun avviso: entra e non sa perché niente funziona.',
            );
        });
    }

    /**
     * ⚠️ **E quando è tutto a posto NON compare.** ⛔ Un avviso che c'è
     * sempre non lo legge nessuno, e quando conta davvero è indistinguibile
     * dallo sfondo.
     */
    #[Test]
    public function con_il_servizio_attivo_l_avviso_non_compare(): void
    {
        $palestra = $this->creaPalestra('InRegola', 'in-regola');

        $this->ctx()->runAs($palestra, function (): void {
            self::assertFalse(\App\Filament\Gym\Widgets\AvvisoDaPagare::canView());
        });
    }
}
