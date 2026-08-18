<?php

declare(strict_types=1);

namespace Tests\Feature\Scoperta;

use App\Enums\UserRole;
use App\Models\Comune;
use App\Models\User;
use App\Services\Scoperta\ChiaveComune;
use App\Services\Scoperta\RicercaComuni;
use App\Services\Scoperta\Vicinanza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * I comuni, la ricerca e la vicinanza — Parte M1, 18/08/2026.
 *
 * 🚨 **I comuni qui si costruiscono a mano, non si importa il file vero.**
 * Ottomila righe a ogni test sarebbero lente e — peggio — legherebbero i test al
 * contenuto di un file che cambia quando l'ISTAT pubblica un elenco nuovo. Un
 * test che fallisce perche' un comune e' stato accorpato non sta segnalando un
 * difetto: sta facendo perdere tempo.
 *
 * 💡 Le coordinate usate sono quelle vere, cosi' le distanze attese sono
 * verificabili contro una mappa invece che contro se stesse.
 */
class ComuniTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Comune $rimini;

    private Comune $pesaro;

    private Comune $milano;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rimini = $this->comune('099014', 'Rimini', 'RN', 'Emilia-Romagna', 44.060249, 12.565599, 150_951);
        $this->pesaro = $this->comune('041044', 'Pesaro', 'PU', 'Marche', 43.909444, 12.913611, 94_705);
        $this->milano = $this->comune('015146', 'Milano', 'MI', 'Lombardia', 45.466794, 9.190347, 1_242_123);

        // A 9,5 km da Rimini: serve a dimostrare che l'ordine e' per distanza.
        $this->comune('099018', 'Santarcangelo di Romagna', 'RN', 'Emilia-Romagna', 44.063889, 12.446944, 21_923);

        // A 114 km: dentro la regione, ma fuori da ogni raggio ragionevole.
        $this->comune('038008', 'Ferrara', 'FE', 'Emilia-Romagna', 44.835297, 11.619865, 132_009);

        // 🚨 Il nome breve che in ordine alfabetico batterebbe Milano.
        $this->comune('083049', 'Milena', 'CL', 'Sicilia', 37.470833, 13.860278, 2_798);

        // Bilingue: serve a dimostrare la ricerca nell'altra lingua.
        $this->comune('021008', 'Bolzano', 'BZ', 'Trentino-Alto Adige', 46.498295, 11.354758, 107_436, 'Bozen');

        // Con l'apostrofo.
        $this->comune('041045', "Sant'Angelo in Vado", 'PU', 'Marche', 43.665278, 12.415000, 4_089);

        // ⚠️ Senza coordinate: e' il caso dei 57 comuni nati dopo il 2018.
        $this->comune('099022', 'Riccione', 'RN', 'Emilia-Romagna', null, null, 35_007);
    }

    // ───────────────────────── la chiave ─────────────────────────

    #[Test]
    public function la_chiave_toglie_accenti_apostrofi_e_maiuscole(): void
    {
        $chiavi = app(ChiaveComune::class);

        $this->assertSame('aglie', $chiavi->da('Agliè'));
        $this->assertSame('sant angelo in vado', $chiavi->da("Sant'Angelo in Vado"));
        $this->assertSame('reggio nell emilia', $chiavi->da("Reggio nell'Emilia"));
        $this->assertSame('groden', $chiavi->da('Gröden'));
    }

    #[Test]
    public function la_chiave_NON_toglie_le_parole_di_riempimento(): void
    {
        /*
         * 🚨 E' la differenza deliberata con `ChiaveAlimento`, ed e' il motivo
         * per cui esiste una seconda classe.
         *
         * ⚠️ Se togliesse `la`, `La Spezia` diventerebbe `spezia`: chi digita
         * «la sp» darebbe «sp», che non e' prefisso di «spezia», e la ricerca
         * smetterebbe di trovare la citta' proprio mentre la si scrive.
         */
        $this->assertSame('la spezia', app(ChiaveComune::class)->da('La Spezia'));
        $this->assertSame('reggio di calabria', app(ChiaveComune::class)->da('Reggio di Calabria'));
    }

    #[Test]
    public function scrittura_e_lettura_usano_la_stessa_normalizzazione(): void
    {
        // 💡 E' la trappola gia' pagata sul catalogo alimenti: due
        // normalizzazioni diverse danno un indice che non trova mai niente.
        $chiavi = app(ChiaveComune::class);

        $this->assertSame($chiavi->da("Sant'Angelo in Vado"), $chiavi->perCercare('SANT ANGELO IN VADO'));
    }

    // ───────────────────────── la ricerca ─────────────────────────

    #[Test]
    public function il_comune_piu_grande_viene_per_primo(): void
    {
        /*
         * 🚨 Il test che giustifica la colonna `popolazione`.
         *
         * In ordine alfabetico `Milena` batte `Milano`, e un selettore di citta'
         * in cui il capoluogo non e' il primo risultato e' un selettore che le
         * persone smettono di usare.
         */
        $trovati = app(RicercaComuni::class)->cerca('mil');

        $this->assertSame('Milano', $trovati->first()->nome);
    }

    #[Test]
    public function chi_comincia_uguale_batte_chi_contiene(): void
    {
        $trovati = app(RicercaComuni::class)->cerca('rimini');

        $this->assertSame('Rimini', $trovati->first()->nome);
    }

    #[Test]
    public function si_cerca_anche_nella_seconda_lingua(): void
    {
        // 💡 Chi vive a Bolzano deve poter scrivere «Bozen».
        $trovati = app(RicercaComuni::class)->cerca('bozen');

        $this->assertCount(1, $trovati);
        $this->assertSame('Bolzano', $trovati->first()->nome);
    }

    #[Test]
    public function si_trova_anche_senza_apostrofo(): void
    {
        $trovati = app(RicercaComuni::class)->cerca('sant angelo');

        $this->assertSame("Sant'Angelo in Vado", $trovati->first()->nome);
    }

    #[Test]
    public function si_trova_anche_da_meta_nome(): void
    {
        /*
         * ⚠️ E' il `LIKE '%x%'`, ammesso qui e vietato sul catalogo alimenti.
         * La differenza e' la dimensione: ottomila righe che non cresceranno mai
         * contro centinaia di migliaia.
         */
        $trovati = app(RicercaComuni::class)->cerca('in vado');

        $this->assertSame("Sant'Angelo in Vado", $trovati->first()->nome);
    }

    #[Test]
    public function fra_due_che_contengono_lo_stesso_pezzo_vince_il_piu_grande(): void
    {
        /*
         * 💡 `angelo` sta dentro sia a `Sant'Angelo in Vado` sia a
         * `Sant-arc-angelo di Romagna`, e nessuno dei due comincia per «angelo»:
         * sono pari sul primo criterio, e decide la popolazione.
         *
         * ⚠️ Questo test e' nato da un mio errore: avevo scritto che `angelo`
         * doveva dare Sant'Angelo, senza accorgermi che la stessa sequenza di
         * lettere e' anche in fondo a Santarcangelo. Il codice era giusto e la
         * premessa del test no. Resta qui perche' fissa il comportamento vero
         * invece di quello che credevo.
         */
        $nomi = app(RicercaComuni::class)->cerca('angelo')->pluck('nome')->all();

        $this->assertSame(['Santarcangelo di Romagna', "Sant'Angelo in Vado"], $nomi);
    }

    #[Test]
    public function sotto_i_due_caratteri_non_si_cerca(): void
    {
        $this->assertCount(0, app(RicercaComuni::class)->cerca('m'));
        $this->assertCount(0, app(RicercaComuni::class)->cerca(''));
        $this->assertCount(0, app(RicercaComuni::class)->cerca('  '));
    }

    #[Test]
    public function i_comuni_spenti_non_si_cercano(): void
    {
        $this->milano->update(['attivo' => false]);

        $trovati = app(RicercaComuni::class)->cerca('milano');

        $this->assertCount(0, $trovati);
    }

    #[Test]
    public function i_caratteri_speciali_di_like_non_restituiscono_tutto(): void
    {
        // ⚠️ Chi digita `%` non sta chiedendo l'elenco completo.
        $this->assertCount(0, app(RicercaComuni::class)->cerca('%%'));
    }

    // ───────────────────────── la vicinanza ─────────────────────────

    #[Test]
    public function il_comune_stesso_e_sempre_il_primo(): void
    {
        /*
         * 🚨 L'errore piu' facile da fare qui sarebbe escluderlo: chi cerca una
         * palestra vicino a Bologna vuole prima di tutto quelle **a Bologna**.
         */
        $vicini = app(Vicinanza::class)->comuniVicini($this->rimini, 40);

        $this->assertSame('Rimini', $vicini->first()->nome);
        $this->assertSame(0.0, $vicini->first()->distanza_km);
    }

    #[Test]
    public function i_vicini_sono_ordinati_per_distanza(): void
    {
        $vicini = app(Vicinanza::class)->comuniVicini($this->rimini, 50);

        $distanze = $vicini->pluck('distanza_km')->all();
        $ordinate = $distanze;
        sort($ordinate);

        $this->assertSame($ordinate, $distanze);
    }

    #[Test]
    public function un_comune_vicino_di_un_altra_regione_compare(): void
    {
        /*
         * 🚨 **Il test che giustifica tutta la scelta delle coordinate.**
         *
         * Rimini ha Pesaro a 32 km e Ferrara a 114. Pesaro e' nelle Marche,
         * Ferrara in Emilia-Romagna. ⚠️ Con l'ordinamento «stessa provincia, poi
         * stessa regione» che il piano proponeva, Pesaro non sarebbe **mai**
         * comparsa e Ferrara sarebbe comparsa prima.
         */
        $vicini = app(Vicinanza::class)->comuniVicini($this->rimini, 50);
        $nomi = $vicini->pluck('nome')->all();

        $this->assertContains('Pesaro', $nomi);
        $this->assertNotContains('Ferrara', $nomi);
    }

    #[Test]
    public function il_raggio_taglia_davvero(): void
    {
        $vicini = app(Vicinanza::class)->comuniVicini($this->rimini, 15);
        $nomi = $vicini->pluck('nome')->all();

        $this->assertContains('Santarcangelo di Romagna', $nomi);  // 9,5 km
        $this->assertNotContains('Pesaro', $nomi);                 // 32,5 km
    }

    #[Test]
    public function la_distanza_e_quella_vera(): void
    {
        // 💡 32,5 km in linea d'aria: verificabile su una mappa, non contro noi
        // stessi.
        $km = app(Vicinanza::class)->distanzaKm($this->rimini, $this->pesaro);

        $this->assertEqualsWithDelta(32.5, $km, 1.0);
    }

    #[Test]
    public function senza_coordinate_la_distanza_e_null_e_non_zero(): void
    {
        /*
         * 🚨 Zero vorrebbe dire «nello stesso posto»: e' la risposta sbagliata
         * piu' credibile che si possa dare, perche' un ordinamento per distanza
         * metterebbe in cima proprio i comuni di cui non si sa niente.
         */
        $riccione = Comune::where('nome', 'Riccione')->firstOrFail();

        $this->assertNull(app(Vicinanza::class)->distanzaKm($this->rimini, $riccione));
    }

    #[Test]
    public function senza_coordinate_si_ripiega_su_provincia_e_regione(): void
    {
        /*
         * ⚠️ Riguarda 57 comuni su 7.896, e senza il ripiego per loro il catalogo
         * sarebbe **vuoto** — cioe' «non c'e' nessuna palestra in Italia», il
         * peggior modo di fallire: silenzioso e plausibile.
         */
        $riccione = Comune::where('nome', 'Riccione')->firstOrFail();

        $vicini = app(Vicinanza::class)->comuniVicini($riccione, 50);
        $nomi = $vicini->pluck('nome')->all();

        $this->assertSame('Riccione', $vicini->first()->nome);
        $this->assertContains('Rimini', $nomi);                     // stessa provincia
        $this->assertContains('Ferrara', $nomi);                    // stessa regione
        $this->assertNotContains('Pesaro', $nomi);                  // altra regione
    }

    #[Test]
    public function i_comuni_spenti_non_compaiono_fra_i_vicini(): void
    {
        Comune::where('nome', 'Santarcangelo di Romagna')->update(['attivo' => false]);

        $nomi = app(Vicinanza::class)->comuniVicini($this->rimini, 50)->pluck('nome')->all();

        $this->assertNotContains('Santarcangelo di Romagna', $nomi);
    }

    #[Test]
    public function il_raggio_ha_un_tetto(): void
    {
        // ⚠️ Serve a non far diventare la vicinanza un modo per farsi restituire
        // l'intera tabella scrivendo un raggio enorme.
        $vicini = app(Vicinanza::class)->comuniVicini($this->rimini, 99_999);
        $nomi = $vicini->pluck('nome')->all();

        $this->assertNotContains('Milano', $nomi);  // 290 km, oltre il tetto di 200
    }

    // ───────────────────────── l'endpoint ─────────────────────────

    #[Test]
    public function la_ricerca_dei_comuni_e_pubblica(): void
    {
        /*
         * 🚨 Pubblica di proposito: l'elenco lo pubblica l'ISTAT e lo scarica
         * chiunque. E serve **prima** che esista un account — il modulo di
         * iscrizione di una palestra chiede la citta' a chi non e' ancora
         * nessuno.
         */
        $this->getJson('/api/v1/comuni?q=milano')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Milano')
            ->assertJsonPath('data.0.esteso', 'Milano (MI)');
    }

    #[Test]
    public function la_ricerca_non_pubblica_la_popolazione(): void
    {
        /*
         * ⚠️ Serve a ordinare, non a essere mostrata: e' un numero vecchio di
         * qualche anno, e pubblicarlo lo farebbe sembrare un dato che teniamo
         * aggiornato.
         */
        $this->getJson('/api/v1/comuni?q=milano')
            ->assertOk()
            ->assertJsonMissingPath('data.0.popolazione');
    }

    #[Test]
    public function meta_parola_non_e_un_errore(): void
    {
        // 💡 Un 422 a meta' parola farebbe lampeggiare un messaggio rosso in un
        // campo che sta funzionando come deve.
        $this->getJson('/api/v1/comuni?q=m')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ───────────────────────── la citta' sul profilo ─────────────────────────

    #[Test]
    public function si_puo_scegliere_la_propria_citta(): void
    {
        $io = $this->utente();

        $this->actingAs($io)
            ->putJson('/api/v1/account/citta', ['comune_id' => $this->milano->id])
            ->assertOk()
            ->assertJsonPath('data.nome', 'Milano');

        $this->assertSame($this->milano->id, $io->fresh()->comune_id);
    }

    #[Test]
    public function la_citta_si_puo_togliere(): void
    {
        /*
         * 🚨 Non e' obbligatoria e non lo diventera'. Chi non vuole dire dove sta
         * perde l'ordinamento per vicinanza — che e' un servizio che gli si
         * offre, **non un pedaggio per entrare**.
         */
        $io = $this->utente();
        $io->forceFill(['comune_id' => $this->milano->id])->save();

        $this->actingAs($io)
            ->putJson('/api/v1/account/citta', ['comune_id' => null])
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertNull($io->fresh()->comune_id);
    }

    #[Test]
    public function non_si_puo_scegliere_un_comune_spento(): void
    {
        // ⚠️ Chi ce l'aveva gia' se lo tiene; sceglierlo di nuovo no. Sono due
        // cose diverse, e questa e' la scelta.
        $this->milano->update(['attivo' => false]);

        $this->actingAs($this->utente())
            ->putJson('/api/v1/account/citta', ['comune_id' => $this->milano->id])
            ->assertStatus(422);
    }

    #[Test]
    public function non_si_puo_scegliere_un_comune_inesistente(): void
    {
        $this->actingAs($this->utente())
            ->putJson('/api/v1/account/citta', ['comune_id' => 999_999])
            ->assertStatus(422);
    }

    #[Test]
    public function la_citta_richiede_l_autenticazione(): void
    {
        $this->putJson('/api/v1/account/citta', ['comune_id' => $this->milano->id])
            ->assertStatus(401);
    }

    // ───────────────────────── aiutanti ─────────────────────────

    private function comune(
        string $codice,
        string $nome,
        string $provincia,
        string $regione,
        ?float $lat,
        ?float $lng,
        int $popolazione,
        ?string $altro = null,
    ): Comune {
        $chiavi = app(ChiaveComune::class);

        return Comune::create([
            'codice' => $codice,
            'nome' => $nome,
            'nome_altro' => $altro,
            'chiave' => $chiavi->da($nome),
            'chiave_altro' => $altro !== null ? $chiavi->da($altro) : null,
            'provincia' => $provincia,
            'provincia_nome' => $nome,
            'regione' => $regione,
            'cap' => null,
            'popolazione' => $popolazione,
            'lat' => $lat,
            'lng' => $lng,
            'attivo' => true,
        ]);
    }

    private function utente(): User
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');

        return $this->creaUtente($palestra, UserRole::Member, 'io@esempio.it');
    }
}
