<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Console\Commands\PotaIRegistri;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🧹 **Si tiene finché serve a funzionare** — 20/09/2026.
 *
 * 📌 Il committente: *«tempi di conservazione solo finché serve per funzionare»*.
 *
 * 🚨 Questo test esiste perché un termine di conservazione **scritto e non
 * applicato** è peggio di un termine lungo: l'informativa direbbe una cosa che
 * non succede.
 */
class IRegistriSiPotanoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $persona;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra();
        $this->persona = $this->creaUtente($this->palestra, UserRole::Member, 'mario@esempio.test');
    }

    #[Test]
    public function il_vecchio_se_ne_va_e_il_recente_resta(): void
    {
        $this->registro(now()->subMonths(PotaIRegistri::MESI_REGISTRO + 1), 'vecchia');
        $this->registro(now()->subMonth(), 'recente');

        $this->consumo(now()->subMonths(PotaIRegistri::MESI_CONSUMI + 1));
        $this->consumo(now()->subMonths(2));

        $this->artisan('registri:pota')->assertSuccessful();

        $this->assertSame(['recente'], DB::table('audit_logs')->pluck('action')->all());
        $this->assertSame(1, DB::table('ai_usage_logs')->count());
    }

    /** 💡 `--dry-run` conta e basta: serve a guardare prima di buttare. */
    #[Test]
    public function la_prova_non_butta_niente(): void
    {
        $this->registro(now()->subYears(3), 'antica');

        $this->artisan('registri:pota', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    /**
     * 🚨 **Gli importi non si toccano.**
     *
     * ⚠️ `addebiti_mensili` e `ai_credit_movements` sono i **totali** su cui si
     * fattura e il saldo dei gettoni: potarli vorrebbe dire perdere il conto
     * dei soldi, e l'obbligo dei dieci anni riguarda loro, non il dettaglio.
     */
    #[Test]
    public function i_soldi_restano(): void
    {
        DB::table('ai_credit_movements')->insert([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => $this->persona->getKey(),
            'delta' => 100,
            'saldo_dopo' => 100,
            'causale' => 'acquisto',
            'created_at' => now()->subYears(4),
            'updated_at' => now()->subYears(4),
        ]);

        $this->artisan('registri:pota')->assertSuccessful();

        $this->assertSame(1, DB::table('ai_credit_movements')->count());
    }

    private function registro(Carbon $quando, string $azione): void
    {
        DB::table('audit_logs')->insert([
            'tenant_id' => $this->palestra->getKey(),
            'actor_id' => $this->persona->getKey(),
            'action' => $azione,
            'created_at' => $quando,
        ]);
    }

    private function consumo(Carbon $quando): void
    {
        DB::table('ai_usage_logs')->insert([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => $this->persona->getKey(),
            'provider' => 'anthropic',
            'model' => 'claude',
            'feature' => 'consiglio',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'success' => true,
            'created_at' => $quando,
        ]);
    }
}
