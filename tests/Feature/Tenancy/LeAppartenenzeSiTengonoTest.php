<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\EsciDaUnaPalestra;
use App\Services\Tenancy\InvitiInPalestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🏠 **Le appartenenze si tengono da sole** — F2.3/F2.4 di
 * `memory/plan_spazi_personali.md`, 27/09/2026.
 *
 * ══ 🔑 PERCHE' QUESTI TEST ESISTONO ═══════════════════════════════════════
 *
 * ⚠️ La tabella `appartenenze` è stata riempita **una volta** da una
 * migrazione. 🚨 Una descrizione che si scrive una volta e poi nessuno
 * aggiorna diventa **una bugia** al primo movimento — e una bugia silenziosa,
 * perché nessuno la rilegge.
 *
 * 💡 Qui si verifica che si mantenga viva da sola: entrando, uscendo, e
 * cambiando palestra.
 */
class LeAppartenenzeSiTengonoTest extends TestCase
{
    use RefreshDatabase;
    use CreaAmbiente;

    /** 🎯 Entrando in una palestra nasce l'appartenenza. */
    #[Test]
    public function entrando_in_palestra_nasce_l_appartenenza(): void
    {
        [$persona, $palestra, $capo] = $this->unaPersonaEUnaPalestra('Olimpo', 'olimpo');

        self::assertSame(0, $this->vive($persona->getKey()), 'Parte gia con delle appartenenze.');

        app(InvitiInPalestra::class)->accetta($this->invito($palestra, $capo), $persona->fresh());

        self::assertSame(
            1,
            $this->vive($persona->getKey(), 'gym'),
            'Entrando in palestra l\'appartenenza doveva nascere.',
        );
    }

    /**
     * 🎯 **Uscendo si CHIUDE, non sparisce.**
     *
     * ⛔ Cancellare la riga butterebbe via la storia proprio nel momento in cui
     * diventa interessante: è quella che dice da quando a quando qualcuno ha
     * pagato per te.
     */
    #[Test]
    public function uscendo_l_appartenenza_si_chiude_ma_resta(): void
    {
        [$persona, $palestra, $capo] = $this->unaPersonaEUnaPalestra('Ares', 'ares');

        app(InvitiInPalestra::class)->accetta($this->invito($palestra, $capo), $persona->fresh());
        app(EsciDaUnaPalestra::class)($persona->fresh());

        self::assertSame(
            0,
            $this->vive($persona->getKey(), 'gym'),
            'Uscito, non doveva restare nessuna appartenenza viva.',
        );

        self::assertSame(
            1,
            DB::table('appartenenze')
                ->where('user_id', $persona->getKey())
                ->where('tipo', 'gym')
                ->whereNotNull('finita_il')
                ->count(),
            '🚨 La riga doveva restare, chiusa: la storia non si cancella.',
        );
    }

    /**
     * ⚖️ **Cambiando palestra se ne ha sempre e solo una viva.**
     *
     * 🚨 È il vincolo del database visto dall'alto: se `entra()` non chiudesse
     * la precedente, la riga nuova verrebbe **rifiutata** e il cambio palestra
     * fallirebbe con un errore incomprensibile.
     */
    #[Test]
    public function cambiando_palestra_ne_resta_viva_una_sola(): void
    {
        [$persona, $prima, $capo1] = $this->unaPersonaEUnaPalestra('Prima', 'prima');
        [, $seconda, $capo2] = $this->unaPersonaEUnaPalestra('Seconda', 'seconda', false);

        app(InvitiInPalestra::class)->accetta($this->invito($prima, $capo1), $persona->fresh());
        app(InvitiInPalestra::class)->accetta(
            $this->invito($seconda, $capo2),
            $persona->fresh(),
            true, // lascia la precedente
        );

        self::assertSame(
            1,
            $this->vive($persona->getKey(), 'gym'),
            'Con due palestre vive il vincolo del database avrebbe rifiutato la riga.',
        );

        self::assertSame(
            $seconda->getKey(),
            DB::table('appartenenze')
                ->where('user_id', $persona->getKey())
                ->where('tipo', 'gym')
                ->whereNull('finita_il')
                ->value('tenant_id'),
            'La viva deve essere la palestra NUOVA.',
        );
    }

    /** Quante appartenenze vive ha questa persona. */
    private function vive(int $userId, ?string $tipo = null): int
    {
        return DB::table('appartenenze')
            ->where('user_id', $userId)
            ->when($tipo !== null, fn ($q) => $q->where('tipo', $tipo))
            ->whereNull('finita_il')
            ->count();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Tenant, 2: \App\Models\User} */
    private function unaPersonaEUnaPalestra(string $nome, string $slug, bool $conPersona = true): array
    {
        $palestra = $this->creaPalestra($nome, $slug);
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@'.$slug.'.test');

        $persona = $conPersona
            ? app(CreaTenantPersonale::class)(
                'Persona '.$nome,
                'persona-'.$slug.'@prova.test',
                ['password' => TestCase::FAKE_PASSWORD],
                UserRole::FreeUser,
            )
            : $capo;

        return [$persona, $palestra, $capo];
    }

    private function invito(\App\Models\Tenant $palestra, \App\Models\User $capo): string
    {
        return $this->ctx()->runAs(
            $palestra,
            fn () => app(InvitiInPalestra::class)->invita($palestra, $capo),
        )->token;
    }
}
