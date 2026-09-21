<?php

declare(strict_types=1);

namespace Tests\Feature\Scoperta;

use App\Models\Comune;
use App\Services\Scoperta\ChiaveComune;
use App\Services\Scoperta\RicercaComuni;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 🌍 **I comuni del mondo** — 23/09/2026, fase F6.1.
 *
 * 📌 Il committente: *«Facciamo stessa tabella ma mondiale. E deve
 * funzionare.»*
 *
 * ══ 📌 COSA DIMOSTRA ═════════════════════════════════════════════════════
 *
 * I tre difetti che sono comparsi **sul database vero** diventando mondiali,
 * uno per test — e le due regole che li hanno aggiustati.
 *
 * | Si scriveva | Usciva per primo | Adesso |
 * |---|---|---|
 * | `bolo` | *Bolo*, Filippine | **Bologna** |
 * | `emilia` | *Emiliano Zapata*, Messico | **Reggio nell'Emilia** |
 * | `munch` | *Münchenstein*, Svizzera | **Monaco di Baviera** |
 *
 * ⚠️ **Le città si costruiscono a mano**, come in `ComuniTest`: importare il
 * mondo vero sarebbe duecentomila righe a ogni test, e legherebbe i test al
 * contenuto di un dump che GeoNames aggiorna ogni notte.
 */
final class IComuniDelMondoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 🇮🇹 L'Italia, dall'ISTAT.
        $this->citta('IT', '037006', 'Bologna', 'BO', 'Bologna', 'Emilia-Romagna', 390_625);
        $this->citta('IT', '035033', "Reggio nell'Emilia", 'RE', 'Reggio Emilia', 'Emilia-Romagna', 170_000);
        $this->citta('IT', '015146', 'Milano', 'MI', 'Milano', 'Lombardia', 1_242_123);
        $this->citta('IT', '019059', 'Moscazzano', 'CR', 'Cremona', 'Lombardia', 800);

        // 🌍 Il resto del mondo, da GeoNames.
        $this->citta('PH', '1724', 'Bolo', 'PH', 'Calabarzon', 'Filippine', 3_000, geonameid: 1724);
        $this->citta('MX', '3531', 'Emiliano Zapata', 'MX', 'Morelos', 'Messico', 20_000, geonameid: 3531);
        $this->citta('CH', '2659', 'Münchenstein', 'CH', 'Basilea Campagna', 'Svizzera', 12_000, geonameid: 2659);
        $this->citta('DE', '2867714', 'Munich', 'DE', 'Baviera', 'Germania', 1_260_391,
            geonameid: 2867714, altro: 'Monaco di Baviera', locale: 'München');
        $this->citta('RU', '524901', 'Moscow', 'RU', 'Moscow', 'Russia', 10_381_222,
            geonameid: 524901, altro: 'Mosca', locale: 'Москва');
        $this->citta('PL', '3093133', 'Łódź', 'PL', 'Łódź', 'Polonia', 768_755, geonameid: 3093133);
        $this->citta('ML', '2451478', 'San', 'ML', 'Ségou', 'Mali', 200_000, geonameid: 2451478);
        $this->citta('IT', '008055', 'Sanremo', 'IM', 'Imperia', 'Liguria', 55_000);
    }

    private function cerca(string $testo): array
    {
        return app(RicercaComuni::class)
            ->cerca($testo)
            ->map(fn (Comune $c): string => $c->nome)
            ->all();
    }

    // ───────────────────────── i tre difetti ─────────────────────────

    /**
     * 🚨 **«bolo» → Bologna, non un villaggio filippino.**
     *
     * ⛔ Il nome esatto straniero salta in cima solo se la città è grande: un
     * villaggio che si chiama *esattamente* così non passa davanti a Bologna.
     */
    #[Test]
    public function bolo_trova_bologna_per_prima(): void
    {
        $this->assertSame('Bologna', $this->cerca('bolo')[0]);
    }

    /**
     * 🚨 **«emilia» → Reggio nell'Emilia, non il Messico.**
     *
     * 💡 Il «contiene» si cerca nel paese di chi cerca, e viene prima degli
     * «inizia con» stranieri.
     */
    #[Test]
    public function emilia_trova_reggio_nell_emilia(): void
    {
        $this->assertSame("Reggio nell'Emilia", $this->cerca('emilia')[0]);
    }

    /**
     * 🚨 **«munch» → Monaco di Baviera**, che GeoNames chiama `Munich`.
     *
     * 💡 Si cerca anche sul nome **locale** (`München`) e su quello
     * **italiano**.
     */
    #[Test]
    public function si_trova_col_nome_locale_e_con_quello_italiano(): void
    {
        $this->assertSame('Munich', $this->cerca('munch')[0]);
        $this->assertSame('Munich', $this->cerca('monaco di')[0]);
    }

    // ───────────────────────── le regole ─────────────────────────

    /**
     * 🏙️ **Un nome intero di una città grande vince** — «mosca» → Mosca,
     * non *Moscazzano*.
     */
    #[Test]
    public function il_nome_intero_di_una_grande_citta_vince(): void
    {
        $this->assertSame('Moscow', $this->cerca('mosca')[0]);
    }

    /**
     * ⚠️ **Ma non sotto le quattro lettere**: «san» è l'inizio di Sanremo, non
     * il nome della città del Mali che si chiama esattamente così.
     */
    #[Test]
    public function tre_lettere_non_sono_un_nome_intero(): void
    {
        $this->assertSame('Sanremo', $this->cerca('san')[0]);
    }

    /**
     * 🔤 **Le lettere di tutta Europa** — «lodz» trova Łódź.
     *
     * ⛔ La tabella di prima conosceva solo le lettere italiane, tedesche e
     * francesi: `Łódź` diventava `łodź`, e sulla tastiera italiana la `ł` non
     * c'è.
     */
    #[Test]
    public function lodz_trova_lodz(): void
    {
        $this->assertSame('Łódź', $this->cerca('lodz')[0]);

        $chiavi = app(ChiaveComune::class);
        $this->assertSame('izmir', $chiavi->da('İzmir'));
        $this->assertSame('brasov', $chiavi->da('Brașov'));
        $this->assertSame('ha noi', $chiavi->da('Hà Nội'));
    }

    /** 🌍 Il paese di chi cerca viene prima: un tedesco che scrive «mu» vede prima la Germania. */
    #[Test]
    public function il_paese_di_chi_cerca_viene_prima(): void
    {
        $risultati = app(RicercaComuni::class)->cerca('mun', paese: 'DE');

        $this->assertSame('Munich', $risultati->first()->nome);
    }

    // ───────────────────────── come si scrive ─────────────────────────

    /**
     * 🗣️ **Il nome italiano, la regione, il paese** — e la regione si tace se
     * è la città stessa.
     */
    #[Test]
    public function esteso_parla_italiano_fuori_dall_italia(): void
    {
        $this->assertSame('Bologna (BO)', Comune::where('nome', 'Bologna')->sole()->esteso());
        $this->assertSame('Monaco di Baviera, Baviera (Germania)', Comune::where('nome', 'Munich')->sole()->esteso());

        // ⛔ Non «Mosca, Moscow (Russia)»: la regione di Mosca è Mosca.
        $this->assertSame('Mosca (Russia)', Comune::where('nome', 'Moscow')->sole()->esteso());
    }

    // ───────────────────────── i due import ─────────────────────────

    /**
     * 🚨 **I due import non si spengono a vicenda.**
     *
     * ⛔ Prima di F6.1 `comuni:importa` spegneva tutto quello che non era nel
     * file ISTAT: da mondiali, avrebbe spento **duecentomila città** del resto
     * del mondo, in silenzio.
     */
    #[Test]
    public function l_import_italiano_non_spegne_il_mondo(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'comuni');
        file_put_contents($file, json_encode([
            'c' => '037006', 'n' => 'Bologna', 'a' => null, 'p' => 'BO', 'pn' => 'Bologna',
            'r' => 'Emilia-Romagna', 'cap' => '40121', 'pop' => 390_625, 'lat' => 44.49, 'lng' => 11.34,
        ])."\n");

        Artisan::call('comuni:importa', ['--file' => $file]);

        $this->assertSame(
            0,
            Comune::query()->where('paese', '!=', 'IT')->where('attivo', false)->count(),
            'L\'import italiano ha spento delle città straniere.',
        );

        // 💡 E gli italiani che non erano nel file, invece, sì: è il suo mestiere.
        $this->assertFalse(Comune::where('nome', 'Milano')->sole()->attivo);

        @unlink($file);
    }

    /** 🌍 E quello del mondo non tocca l'Italia, nemmeno se il file la contenesse. */
    #[Test]
    public function l_import_del_mondo_non_tocca_l_italia(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mondo').'.gz';
        $gz = gzopen($file, 'wb');
        gzwrite($gz, json_encode(['g' => 999, 'n' => 'Milano finta', 'p' => 'IT', 'r' => 'Italia'])."\n");
        gzwrite($gz, json_encode(['g' => 2867714, 'n' => 'Munich', 'it' => 'Monaco di Baviera', 'l' => 'München',
            'p' => 'DE', 'pn' => 'Baviera', 'r' => 'Germania', 'pop' => 1_260_391, 'lat' => 48.13, 'lng' => 11.57])."\n");
        gzclose($gz);

        Artisan::call('comuni:importa-mondo', ['--file' => $file]);

        $this->assertFalse(Comune::where('nome', 'Milano finta')->exists(), 'Una riga italiana è entrata da GeoNames.');
        $this->assertTrue(Comune::where('nome', 'Milano')->sole()->attivo, 'L\'import del mondo ha spento un comune italiano.');

        @unlink($file);
    }

    // ───────────────────────── aiuti ─────────────────────────

    private function citta(
        string $paese,
        string $codice,
        string $nome,
        string $provincia,
        string $provinciaNome,
        string $regione,
        int $popolazione,
        ?int $geonameid = null,
        ?string $altro = null,
        ?string $locale = null,
    ): void {
        $chiavi = app(ChiaveComune::class);

        DB::table('comuni')->insert([
            'codice' => $codice,
            'paese' => $paese,
            'geonameid' => $geonameid,
            'nome' => $nome,
            'nome_altro' => $altro,
            'nome_locale' => $locale,
            'chiave' => $chiavi->da($nome),
            'chiave_altro' => $altro !== null ? $chiavi->da($altro) : null,
            'chiave_locale' => $locale !== null ? $chiavi->da($locale) : null,
            'provincia' => $provincia,
            'provincia_nome' => $provinciaNome,
            'regione' => $regione,
            'popolazione' => $popolazione,
            'lat' => 45.0,
            'lng' => 10.0,
            'attivo' => true,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }
}
