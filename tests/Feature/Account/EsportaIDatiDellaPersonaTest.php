<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Account\EsportaIDatiDellaPersona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 📦 «I miei dati» — artt. 15 e 20 GDPR, 19/09/2026.
 */
class EsportaIDatiDellaPersonaTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    /** Le colonne che dicono «questa riga è di una persona». */
    private const COLONNE_DELLA_PERSONA = ['user_id', 'member_id', 'trainer_id', 'sender_id', 'actor_id', 'created_by'];

    /** Tabelle tecniche, senza dati di nessuno da consegnare. */
    private const INFRASTRUTTURA = ['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs'];

    private Tenant $alfa;

    private User $mario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa');
        $this->mario = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    #[Test]
    public function si_scarica_solo_da_autenticati(): void
    {
        $this->getJson('/api/v1/account/export')->assertUnauthorized();
    }

    #[Test]
    public function ci_sono_l_account_e_le_sue_righe(): void
    {
        $dati = $this->comeApp($this->mario)
            ->getJson('/api/v1/account/export')
            ->assertOk()
            ->json('data');

        $this->assertSame('mario@alfa.test', $dati['account']['email']);
        // ⛔ Il diario non c'è più fra le tabelle (26/09/2026): sta sul telefono.
        $this->assertArrayNotHasKey('food_entries', $dati['tabelle']);
        $this->assertContains('member', array_column($dati['ruoli'], 'ruolo'));
        $this->assertNotEmpty($dati['avvertenze']);
    }

    /** 🚨 Password, token delle notifiche, chiavi: in un file scaricato aprirebbero l'account. */
    #[Test]
    public function password_e_token_non_escono(): void
    {
        DB::table('device_tokens')->insert([
            'tenant_id' => $this->alfa->id, 'user_id' => $this->mario->id,
            'token' => 'fcm-segreto-di-prova', 'platform' => 'android',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $grezzo = $this->comeApp($this->mario)
            ->getJson('/api/v1/account/export')
            ->assertOk()
            ->getContent();

        $dati = json_decode($grezzo, true)['data'];

        $this->assertArrayNotHasKey('password', $dati['account']);
        $this->assertArrayNotHasKey('remember_token', $dati['account']);
        $this->assertSame('android', $dati['tabelle']['device_tokens'][0]['platform']);
        $this->assertStringNotContainsString('fcm-segreto-di-prova', $grezzo);
    }

    /** ⛔ I dati di un altro non ci finiscono, nemmeno nella stessa palestra. */
    #[Test]
    public function i_dati_degli_altri_restano_fuori(): void
    {
        $luigi = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');
        $this->pasto($luigi, 'La cena di Luigi');

        $grezzo = $this->comeApp($this->mario)
            ->getJson('/api/v1/account/export')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('La cena di Luigi', $grezzo);
        $this->assertStringNotContainsString('luigi@alfa.test', $grezzo);
    }

    /**
     * 🚨 **Ogni tabella legata a una persona ha una regola.**
     *
     * Una tabella nata domani con un `user_id` deve finire in `ESPORTATE` o in
     * `ESCLUSE`, col motivo: senza, l'esportazione diventerebbe incompleta in
     * silenzio — e nessuno se ne accorgerebbe, perché un dato che manca non
     * dà errore.
     */
    #[Test]
    public function ogni_tabella_legata_a_una_persona_ha_una_regola(): void
    {
        $segnaposti = implode(',', array_fill(0, count(self::COLONNE_DELLA_PERSONA), '?'));

        $tabelle = array_map(
            static fn (object $r): string => (string) $r->tabella,
            DB::select(
                'select distinct table_name as tabella from information_schema.columns '
                ."where table_schema = database() and column_name in ({$segnaposti})",
                self::COLONNE_DELLA_PERSONA,
            ),
        );

        $senzaRegola = array_values(array_diff(
            $tabelle,
            array_keys(EsportaIDatiDellaPersona::ESPORTATE),
            array_keys(EsportaIDatiDellaPersona::ESCLUSE),
            self::INFRASTRUTTURA,
        ));

        sort($senzaRegola);

        $this->assertSame([], $senzaRegola, 'Tabelle legate a una persona senza una regola per l\'esportazione: '
            .implode(', ', $senzaRegola).'. Vanno in EsportaIDatiDellaPersona::ESPORTATE o ::ESCLUSE, col motivo.');
    }
}
