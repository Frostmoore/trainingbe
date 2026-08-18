<?php

declare(strict_types=1);

namespace Tests\Feature\Scoperta;

use App\Enums\UserRole;
use App\Models\Comune;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Scoperta\ChiaveComune;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il catalogo pubblico — Parte M2, 18/08/2026.
 *
 * 🚨 **La correzione del committente che questo file difende**: il catalogo lo
 * vedono **tutti**, non solo gli abbonati. Chiuderlo lo nasconderebbe a chi non
 * e' ancora cliente — cioe' alle persone che deve raggiungere — e ridurrebbe il
 * pubblico di chi paga la pubblicita' ai soli abbonati, che sono gia' dentro.
 */
class CatalogoPubblicoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Comune $rimini;

    private Comune $pesaro;

    private Comune $milano;

    private Tenant $palestraRimini;

    private Tenant $palestraMilano;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rimini = $this->comune('099014', 'Rimini', 'RN', 'Emilia-Romagna', 44.060249, 12.565599);
        $this->pesaro = $this->comune('041044', 'Pesaro', 'PU', 'Marche', 43.909444, 12.913611);
        $this->milano = $this->comune('015146', 'Milano', 'MI', 'Lombardia', 45.466794, 9.190347);

        $this->palestraRimini = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->palestraMilano = $this->creaPalestra('Ares', 'ares', 'ARES2345');
    }

    // ───────────────────────── il vincolo XOR ─────────────────────────

    #[Test]
    public function una_scheda_non_puo_essere_di_una_palestra_E_di_un_trainer(): void
    {
        /*
         * 🚨 Il vincolo sta nel **database**, non nel modello.
         *
         * ⚠️ Un controllo scritto solo in PHP vale finche' tutti passano dal
         * modello, e non ci passano: le migrazioni di manutenzione, gli import,
         * un `DB::table()` scritto di fretta. Una scheda con tutti e due i campi
         * pieni non sarebbe sbagliata in modo visibile — funzionerebbe, e
         * comincerebbe a mostrare la palestra quando qualcuno cerca il trainer.
         */
        $trainer = $this->creaUtente($this->palestraRimini, UserRole::FreeTrainer, 'tizio@esempio.it');

        $this->expectException(QueryException::class);

        ProfiloPubblico::create([
            'tenant_id' => $this->palestraRimini->id,
            'user_id' => $trainer->id,
            'comune_id' => $this->rimini->id,
            'titolo' => 'Impossibile',
            'visibile' => true,
        ]);
    }

    #[Test]
    public function una_scheda_deve_essere_di_qualcuno(): void
    {
        $this->expectException(QueryException::class);

        ProfiloPubblico::create([
            'tenant_id' => null,
            'user_id' => null,
            'comune_id' => $this->rimini->id,
            'titolo' => 'Di nessuno',
            'visibile' => true,
        ]);
    }

    #[Test]
    public function una_palestra_non_puo_avere_due_schede(): void
    {
        // 🚨 Senza l'unicita' la stessa palestra comparirebbe tre volte nella
        // stessa ricerca: un modo per occupare i risultati degli altri senza
        // pagare la pubblicita'.
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Prima');

        $this->expectException(QueryException::class);

        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Seconda');
    }

    // ───────────────────────── chi lo vede ─────────────────────────

    #[Test]
    public function il_catalogo_lo_vede_anche_chi_NON_e_autenticato(): void
    {
        /*
         * 🚨 **Il test che difende la correzione del committente.**
         *
         * Se un giorno qualcuno rimettesse `auth:sanctum` su questa rotta —
         * sembra prudente, e sarebbe un errore — questo test diventerebbe rosso.
         */
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo Rimini');

        $this->getJson('/api/v1/catalogo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titolo', 'Olimpo Rimini');
    }

    #[Test]
    public function le_schede_non_visibili_non_compaiono(): void
    {
        // ⚠️ Qui l'isolamento lo fa `visibile`, non un global scope: questa e'
        // l'unica tabella del progetto senza `BelongsToTenant`, e non c'e' nessuna
        // rete sotto.
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Spenta', visibile: false);

        $this->getJson('/api/v1/catalogo')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function una_scheda_non_visibile_risponde_404_e_non_403(): void
    {
        /*
         * 🚨 Un `403` confermerebbe che quella scheda **esiste** — cioe'
         * permetterebbe di scoprire, provando gli identificativi uno per uno,
         * quali palestre sono iscritte ma non pubblicate. E' un'informazione
         * commerciale di qualcun altro.
         */
        $scheda = $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Spenta', visibile: false);

        $this->getJson("/api/v1/catalogo/{$scheda->id}")->assertStatus(404);
    }

    // ───────────────────────── l'ordine ─────────────────────────

    #[Test]
    public function chi_e_autenticato_vede_prima_quelle_vicine(): void
    {
        /*
         * 🚨 **Il test che ha trovato un difetto vero.**
         *
         * Questa rotta e' fuori da `auth:sanctum`, e il guard predefinito e'
         * `web` — cioe' sessione con cookie. ⚠️ Con `$request->user()` un'app che
         * si presenta col token `Bearer` risultava **anonima**: il catalogo
         * funzionava, restituiva risultati, non dava errori — e non ordinava mai
         * per vicinanza. Nessuna eccezione, nessun log.
         *
         * 💡 `Sanctum::actingAs()` riproduce esattamente quel caso, ed e' per
         * questo che il difetto e' emerso qui e non a mano.
         */
        $this->schedaPalestra($this->palestraMilano, $this->milano, 'Ares Milano');
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo Rimini');

        $io = $this->creaUtente($this->palestraRimini, UserRole::Member, 'io@esempio.it');
        $io->forceFill(['comune_id' => $this->rimini->id])->save();

        Sanctum::actingAs($io);

        $risposta = $this->getJson('/api/v1/catalogo')->assertOk();

        $this->assertSame('Olimpo Rimini', $risposta->json('data.0.titolo'));
        $this->assertSame(0.0, $risposta->json('data.0.distanza_km'));
    }

    #[Test]
    public function senza_citta_l_ordine_e_alfabetico_e_stabile(): void
    {
        /*
         * ⚠️ **Non** a caso e **non** per data: un ordine instabile farebbe
         * cambiare i risultati a ogni ricarica, e chi paga la pubblicita' avrebbe
         * ragione a chiedere perche'.
         */
        $this->schedaPalestra($this->palestraMilano, $this->milano, 'Ares Milano');
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo Rimini');

        $nomi = $this->getJson('/api/v1/catalogo')->assertOk()->json('data.*.titolo');

        $this->assertSame(['Ares Milano', 'Olimpo Rimini'], $nomi);
    }

    #[Test]
    public function la_distanza_esce_solo_a_chi_ha_detto_dove_sta(): void
    {
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo Rimini');

        $this->getJson('/api/v1/catalogo')
            ->assertOk()
            ->assertJsonPath('data.0.distanza_km', null);
    }

    // ───────────────────────── la ricerca ─────────────────────────

    #[Test]
    public function si_cerca_nel_titolo_e_nella_descrizione(): void
    {
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo', descrizione: 'Sala pesi e functional');
        $this->schedaPalestra($this->palestraMilano, $this->milano, 'Ares', descrizione: 'Solo crossfit');

        $this->getJson('/api/v1/catalogo?q=functional')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titolo', 'Olimpo');
    }

    #[Test]
    public function cercare_il_nome_di_una_citta_trova_chi_ci_sta(): void
    {
        /*
         * 💡 Chi scrive «Rimini» in un campo di ricerca sta cercando **le
         * palestre a Rimini**, non una palestra che si chiama Rimini. Senza
         * questo, otterrebbe zero risultati e concluderebbe che non ce ne sono.
         */
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo');
        $this->schedaPalestra($this->palestraMilano, $this->milano, 'Ares');

        $this->getJson('/api/v1/catalogo?q=rimini')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titolo', 'Olimpo');
    }

    // ───────────────────────── il destinatario ─────────────────────────

    #[Test]
    public function per_una_palestra_il_destinatario_e_il_proprietario(): void
    {
        /*
         * 🚨 **Non un dipendente designato**, e per due ragioni che si tengono
         * insieme: la chat ha **una chiave pubblica per persona**, quindi «lo
         * staff» vorrebbe dire smettere di cifrare punto a punto; e chi risponde
         * a «come ci si iscrive» sta trattando un cliente, non facendo una cosa
         * tecnica.
         */
        $proprietario = $this->creaUtente($this->palestraRimini, UserRole::GymAdmin, 'capo@olimpo.it');
        $this->creaUtente($this->palestraRimini, UserRole::Trainer, 'dipendente@olimpo.it');

        $scheda = $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo');

        $this->assertTrue($proprietario->is($scheda->destinatario()));
    }

    #[Test]
    public function per_un_trainer_indipendente_il_destinatario_e_lui_stesso(): void
    {
        $trainer = $this->creaUtente($this->palestraRimini, UserRole::FreeTrainer, 'tizio@esempio.it');

        $scheda = ProfiloPubblico::create([
            'user_id' => $trainer->id,
            'comune_id' => $this->rimini->id,
            'titolo' => 'Tizio, personal trainer',
            'visibile' => true,
        ]);

        $this->assertTrue($trainer->is($scheda->destinatario()));
    }

    #[Test]
    public function una_palestra_senza_proprietario_attivo_non_e_contattabile(): void
    {
        /*
         * 💡 L'app deve poterlo dire **prima** che qualcuno scriva un messaggio
         * che non arriverebbe da nessuna parte.
         */
        $capo = $this->creaUtente($this->palestraRimini, UserRole::GymAdmin, 'capo@olimpo.it');
        $capo->forceFill(['is_active' => false])->save();

        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo');

        $this->getJson('/api/v1/catalogo')
            ->assertOk()
            ->assertJsonPath('data.0.contattabile', false);
    }

    // ───────────────────────── cosa NON esce ─────────────────────────

    #[Test]
    public function il_catalogo_NON_pubblica_gli_id_dei_proprietari(): void
    {
        /*
         * 🚨 Per aprire una conversazione l'app manda **l'id della scheda**, e il
         * server risolve da solo il destinatario. ⚠️ Pubblicare qui
         * l'identificativo del titolare vorrebbe dire dare a chiunque, senza
         * autenticazione, l'elenco degli id di tutti i titolari di palestra — un
         * pezzo che non serve all'app e che serve a chi vuole provare a
         * indovinare qualcos'altro.
         */
        $this->creaUtente($this->palestraRimini, UserRole::GymAdmin, 'capo@olimpo.it');
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo');

        $risposta = $this->getJson('/api/v1/catalogo')->assertOk();

        $this->assertArrayNotHasKey('user_id', $risposta->json('data.0'));
        $this->assertArrayNotHasKey('tenant_id', $risposta->json('data.0'));
        $this->assertArrayNotHasKey('destinatario', $risposta->json('data.0'));
    }

    #[Test]
    public function l_etichetta_sponsorizzato_c_e_sempre_anche_quando_e_falsa(): void
    {
        /*
         * 🚨 Un campo che compare solo quando vale `true` e' un campo che l'app
         * puo' dimenticarsi di leggere senza che niente si rompa — finche' un
         * giorno qualcuno paga e nessuno lo etichetta. Presentare a pagamento
         * qualcosa che sembra un risultato di ricerca e' **pubblicita' occulta**.
         */
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo');

        $this->getJson('/api/v1/catalogo')
            ->assertOk()
            ->assertJsonPath('data.0.sponsorizzato', false);
    }

    #[Test]
    public function nell_elenco_la_descrizione_e_tagliata(): void
    {
        // 💡 E' testo libero: una scheda con duemila parole occuperebbe da sola
        // tutta la risposta.
        $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo', descrizione: str_repeat('a', 500));

        $elenco = $this->getJson('/api/v1/catalogo')->assertOk()->json('data.0.descrizione');

        $this->assertLessThanOrEqual(210, mb_strlen((string) $elenco));
    }

    #[Test]
    public function nella_scheda_singola_la_descrizione_e_intera(): void
    {
        $scheda = $this->schedaPalestra($this->palestraRimini, $this->rimini, 'Olimpo', descrizione: str_repeat('a', 500));

        $this->getJson("/api/v1/catalogo/{$scheda->id}")
            ->assertOk()
            ->assertJsonPath('data.descrizione', str_repeat('a', 500));
    }

    #[Test]
    public function il_limite_ha_un_tetto(): void
    {
        // ⚠️ Senza, `?limite=100000` sarebbe un modo per scaricare tutto.
        $this->getJson('/api/v1/catalogo?limite=100000')->assertStatus(422);
    }

    // ───────────────────────── aiutanti ─────────────────────────

    private function comune(string $codice, string $nome, string $prov, string $reg, float $lat, float $lng): Comune
    {
        return Comune::create([
            'codice' => $codice,
            'nome' => $nome,
            'chiave' => app(ChiaveComune::class)->da($nome),
            'provincia' => $prov,
            'provincia_nome' => $nome,
            'regione' => $reg,
            'popolazione' => 100_000,
            'lat' => $lat,
            'lng' => $lng,
            'attivo' => true,
        ]);
    }

    private function schedaPalestra(
        Tenant $palestra,
        Comune $dove,
        string $titolo,
        ?string $descrizione = null,
        bool $visibile = true,
    ): ProfiloPubblico {
        return ProfiloPubblico::create([
            'tenant_id' => $palestra->id,
            'comune_id' => $dove->id,
            'titolo' => $titolo,
            'descrizione' => $descrizione,
            'visibile' => $visibile,
        ]);
    }
}
