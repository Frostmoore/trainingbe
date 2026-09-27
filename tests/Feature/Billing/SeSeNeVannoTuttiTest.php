<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Services\Billing\PianoAttivo;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\EsciDaUnaPalestra;
use App\Services\Tenancy\InvitiInPalestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🏠 **Se se ne vanno tutti, resto io** — 27/09/2026.
 *
 * 📌 Il committente, punto 5 della sua specifica: *«se anche tutti si
 * dovessero disiscrivere, cancellare, disintegrare l'account, comunque devo
 * poter accedere e se avevo un abbonamento individuale attivo prima di
 * iscrivermi a uno di questi, deve ripartire quello, sennò semplicemente devo
 * accedere senza abbonamento»*.
 *
 * ⚠️ **Questo file non aggiunge comportamento: lo verifica.** Il meccanismo
 * della pausa (`LaPausaDellAbbonamento`) esisteva già ed `EsciDaUnaPalestra`
 * lo richiama. 🚨 Ma «esiste il codice» e «fa quello che il committente ha
 * chiesto» sono due affermazioni diverse, e solo la seconda conta: senza un
 * test che le leghi, la prima si può dire e restare falsa.
 */
class SeSeNeVannoTuttiTest extends TestCase
{
    use RefreshDatabase;
    use CreaAmbiente;

    /**
     * 🎯 **Il caso che il committente ha chiesto.**
     *
     * Aveva il **suo** abbonamento, entra in una palestra (e il suo va in
     * pausa, perché altrimenti pagherebbe due volte), poi esce.
     * 🔑 L'atteso: il **suo** riparte.
     */
    #[Test]
    public function uscendo_dalla_palestra_riparte_il_mio_abbonamento(): void
    {
        $persona = app(CreaTenantPersonale::class)(
            'Chi Paga Da Se',
            'da-se@prova.test',
            ['password' => TestCase::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        $mio = PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $persona->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::PLUS)->firstOrFail()->id,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ]);

        self::assertTrue(
            app(PianoAttivo::class)->eAbbonato($persona->fresh()),
            'Parte già non abbonato: il caso non prova niente.',
        );

        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@olimpo.test');

        app(InvitiInPalestra::class)->accetta(
            $this->invitoPer($palestra, $capo),
            $persona->fresh(),
        );

        // ⏸️ Entrando, il proprio si mette in pausa: due coperture insieme
        //    vorrebbero dire pagare due volte la stessa cosa.
        self::assertNotNull(
            $mio->fresh()->in_pausa_dal,
            'Entrando in palestra il proprio abbonamento doveva andare in pausa.',
        );

        // 🚪 E adesso se ne va.
        app(EsciDaUnaPalestra::class)($persona->fresh());

        self::assertNull(
            $mio->fresh()->in_pausa_dal,
            '🎯 Uscito dalla palestra, il MIO abbonamento deve ripartire.',
        );

        self::assertTrue(
            app(PianoAttivo::class)->eAbbonato($persona->fresh()),
            'Uscito dalla palestra resta abbonato: l\'abbonamento era suo.',
        );
    }

    /**
     * ⚠️ **E chi non aveva niente entra lo stesso, da gratuito.**
     *
     * 📌 *«sennò semplicemente devo accedere senza abbonamento»*.
     *
     * 🚨 Il punto che conta non è l'abbonamento: è che **si accede**. ⛔ Fino
     * al 26/09 una palestra sospesa chiudeva fuori dal proprio account, e
     * questo test è la forma positiva di quella regola.
     */
    #[Test]
    public function chi_non_aveva_niente_esce_e_resta_dentro_da_gratuito(): void
    {
        $persona = app(CreaTenantPersonale::class)(
            'Senza Niente',
            'senza@prova.test',
            ['password' => TestCase::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        $palestra = $this->creaPalestra('Ares', 'ares');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@ares.test');

        app(InvitiInPalestra::class)->accetta(
            $this->invitoPer($palestra, $capo),
            $persona->fresh(),
        );

        app(EsciDaUnaPalestra::class)($persona->fresh());

        $dopo = $persona->fresh();

        self::assertNotNull($dopo, 'L\'account deve esistere ancora.');
        self::assertFalse(
            app(PianoAttivo::class)->eAbbonato($dopo),
            'Non aveva niente: esce da gratuito, e va bene così.',
        );
        self::assertSame(
            Plan::FREE,
            app(PianoAttivo::class)->livello($dopo),
            'Il livello di chi non ha niente è `free`, che è un livello e non un\'assenza.',
        );
    }

    /** Un invito valido per questa palestra. */
    private function invitoPer(\App\Models\Tenant $palestra, \App\Models\User $capo): string
    {
        return $this->ctx()->runAs(
            $palestra,
            fn () => app(InvitiInPalestra::class)->invita($palestra, $capo),
        )->token;
    }
}
