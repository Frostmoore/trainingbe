<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Members\Pages\EditMember;
use App\Filament\Gym\Resources\Members\Pages\ListMembers;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\UnisciAUnaPalestra;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🚨 **La palestra disiscrive, non cancella** — D4, giro del 18/09/2026.
 *
 * ⛔ Il pannello aveva solo «Elimina» (singolo e in blocco), che cancellava
 * l'**account** della persona. 📌 Il committente: *«Una palestra deve poter AL
 * MASSIMO disiscrivere un allievo! Non deve poter cancellare del tutto il suo
 * account, anche perche' l'account personale non dipende in alcun modo dalla
 * palestra»*.
 */
class DisiscrivereNonCancellaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User, 2: User} */
    private function palestraConIscritto(): array
    {
        $palestra = $this->creaPalestra();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'titolare@esempio.test');

        $persona = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant($persona, $palestra, UserRole::Member);

        return [$palestra, $titolare, $persona->fresh()];
    }

    #[Test]
    public function disiscrivere_riporta_la_persona_nel_suo_spazio_e_non_cancella_niente(): void
    {
        [$palestra, $titolare, $persona] = $this->palestraConIscritto();

        filament()->setCurrentPanel('admin');
        $this->actingAs($titolare);
        app(TenantContext::class)->set($palestra);

        Livewire::test(EditMember::class, ['record' => $persona->getKey()])
            ->callAction('disiscrivi');

        $dopo = User::withoutGlobalScopes()->withTrashed()->findOrFail($persona->getKey());

        $this->assertFalse($dopo->trashed(), 'L\'account non doveva essere cancellato.');
        $this->assertNotSame($palestra->getKey(), $dopo->tenant_id, 'Doveva uscire dalla palestra.');
        $this->assertTrue($dopo->tenant->eDi($dopo), 'Doveva tornare in uno spazio suo.');
    }

    /**
     * 🆕 🚨 **La palestra non vede i dati del corpo** — 19/09/2026.
     *
     * ⛔ La scheda dell'iscritto mostrava e faceva modificare sesso, data di
     * nascita, altezza, livello di attivita', obiettivo e peso obiettivo. 📌
     * *«assicurati che non ci scriva niente di personale»*: stanno sul telefono.
     */
    #[Test]
    public function la_scheda_dell_iscritto_non_ha_i_dati_del_corpo(): void
    {
        [$palestra, $titolare, $persona] = $this->palestraConIscritto();

        filament()->setCurrentPanel('admin');
        $this->actingAs($titolare);
        app(TenantContext::class)->set($palestra);

        $pagina = Livewire::test(EditMember::class, ['record' => $persona->getKey()]);

        foreach (['sex', 'birthdate', 'height_cm', 'activity_level', 'goal', 'target_weight_kg'] as $campo) {
            $pagina->assertFormFieldDoesNotExist("profile.{$campo}");
        }

        $pagina->assertFormFieldExists('profile.notes');
    }

    /** ⛔ E «Elimina» non c'e' piu', ne' sulla scheda ne' in blocco. */
    #[Test]
    public function elimina_non_c_e_piu(): void
    {
        [$palestra, $titolare, $persona] = $this->palestraConIscritto();

        filament()->setCurrentPanel('admin');
        $this->actingAs($titolare);
        app(TenantContext::class)->set($palestra);

        Livewire::test(EditMember::class, ['record' => $persona->getKey()])
            ->assertActionDoesNotExist('delete')
            ->assertActionExists('disiscrivi');

        Livewire::test(ListMembers::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
            ->assertActionExists(TestAction::make('disiscrivi')->table()->bulk());
    }
}
