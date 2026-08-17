<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Enums\UserRole;
use App\Models\Food;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Nutrition\Catalogo\ScritturaABlocchi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Dal diario al catalogo, e ritorno — Parte L, 17/08/2026.
 *
 * 🚨 **È la parte che rende vivo il catalogo.** Senza, resta un archivio
 * precaricato che non impara niente da chi lo usa.
 */
class CatalogoDalDiarioTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
    }

    private const POLLO = [
        'kcal_100' => 100.0,
        'protein_100' => 23.3,
        'carbs_100' => 0.0,
        'fat_100' => 0.8,
    ];

    /**
     * 🚨 **Un solo aggancio copre tutti i punti di scrittura.**
     *
     * Le voci di diario si scrivono in quattro posti diversi. ⚠️ Agganciarli
     * uno per uno vorrebbe dire dimenticarne uno al primo punto nuovo — e non
     * fallirebbe niente: quegli alimenti semplicemente non entrerebbero nel
     * catalogo, e non se ne accorgerebbe nessuno.
     */
    #[Test]
    public function writing_in_the_diary_puts_the_food_in_the_catalogue(): void
    {
        $utente = $this->iscritto();

        $voce = $this->scrivi($utente, 'Petto di pollo');

        $alimento = Food::query()->sole();

        $this->assertSame('Petto di pollo', $alimento->nome);
        $this->assertSame(100.0, $alimento->kcal_100);

        // 💡 E la voce di diario resta collegata: è da lì che si contano le
        // conferme e si ordinano i suggerimenti.
        $this->assertSame($alimento->getKey(), $voce->fresh()->food_id);
    }

    /**
     * 🚨 **Privato finché non lo scrive una seconda persona.**
     *
     * ⚠️ Il nome è testo libero: prima o poi qualcuno scriverà qualcosa di
     * personale, e in un catalogo pubblico immediato finirebbe nel suggerimento
     * di sconosciuti. È il tipo di fuga che non si ritira.
     */
    #[Test]
    public function a_food_stays_private_until_a_second_person_writes_it(): void
    {
        $primo = $this->iscritto('primo@alfa.test');

        $this->scrivi($primo, 'Petto di pollo');

        $this->assertFalse(Food::query()->sole()->pubblico);

        // ⚠️ Lo stesso utente che lo riscrive **non** conta come seconda
        // persona: altrimenti la soglia sarebbe un modo per pubblicarsi da soli
        // quello che si vuole, scrivendolo due giorni di fila.
        $this->scrivi($primo, 'Petto di pollo');

        $alimento = Food::query()->sole();
        $this->assertFalse($alimento->pubblico);
        $this->assertSame(1, $alimento->conferme);
        $this->assertSame(2, $alimento->usi);

        $secondo = $this->iscritto('secondo@alfa.test');
        $this->scrivi($secondo, 'Petto di pollo');

        $this->assertTrue(Food::query()->sole()->fresh()->pubblico);
    }

    /**
     * 💡 **Chi l'ha scritto lo rivede subito**, anche se è ancora privato.
     *
     * ⚠️ Senza, chi inserisce un alimento nuovo lo vedrebbe sparire e lo
     * riscriverebbe da capo ogni volta — e il catalogo non arriverebbe mai a
     * due conferme, perché la stessa persona non conta due volte.
     */
    #[Test]
    public function you_always_see_your_own_foods_even_when_private(): void
    {
        $mio = $this->iscritto('mio@alfa.test');
        $altro = $this->iscritto('altro@alfa.test');

        $this->scrivi($mio, 'Frullato della nonna');

        $this->assertCount(1, Food::query()->visibiliA($mio)->get());
        $this->assertCount(0, Food::query()->visibiliA($altro)->get());
    }

    /**
     * 🚨 **Un utente non sovrascrive mai il CREA**, per quanto sia convinto.
     *
     * È la gerarchia decisa dal committente: i risultati qualificati battono
     * quelli non qualificati.
     */
    #[Test]
    public function a_user_cannot_overwrite_an_official_food(): void
    {
        Food::query()->create([
            'chiave' => 'petto pollo',
            'nome' => 'Petto di pollo',
            'kcal_100' => 100.0, 'protein_100' => 23.3, 'carbs_100' => 0.0, 'fat_100' => 0.8,
            'fonte' => 'CREA:106140',
            'note' => 'Fonte: CREA',
            'pubblico' => true,
            'conferme' => 2,
        ]);

        $this->scrivi($this->iscritto(), 'Petto di pollo', [
            'kcal_100' => 700.0, 'protein_100' => 1.0, 'carbs_100' => 1.0, 'fat_100' => 76.0,
        ]);

        $alimento = Food::query()->sole();

        $this->assertSame(100.0, $alimento->kcal_100);
        $this->assertSame('CREA:106140', $alimento->fonte);
        $this->assertStringContainsString('CREA', (string) $alimento->note);

        // 💡 Ma l'uso si conta lo stesso: serve a ordinare i suggerimenti.
        $this->assertSame(1, $alimento->usi);
    }

    /** ⚠️ Quello che non passa il filtro non entra, e non è un errore. */
    #[Test]
    public function an_entry_without_complete_macros_does_not_enter(): void
    {
        $this->scrivi($this->iscritto(), 'Roba mangiata', [
            'kcal_100' => 200.0, 'protein_100' => null, 'carbs_100' => 10.0, 'fat_100' => 5.0,
        ]);

        $this->assertSame(0, Food::query()->count());
    }

    /**
     * 💡 **I macro per 100 g si ricavano dai totali e dai grammi.**
     *
     * ⚠️ Chi scrive un pasto a mano inserisce spesso i totali della porzione.
     * Buttare via quelle voci vorrebbe dire perdere proprio gli inserimenti
     * manuali completi, che sono i migliori che abbiamo.
     */
    #[Test]
    public function totals_plus_grams_are_enough(): void
    {
        $utente = $this->iscritto();

        FoodEntry::query()->create([
            'tenant_id' => $utente->tenant_id,
            'user_id' => $utente->getKey(),
            'eaten_at' => now(),
            'meal' => 'lunch',
            'description' => 'Petto di pollo',
            'grams' => 200.0,
            'kcal' => 200.0,
            'protein' => 46.6,
            'carbs' => 0.0,
            'fat' => 1.6,
        ]);

        $alimento = Food::query()->sole();

        $this->assertSame(100.0, $alimento->kcal_100);
        $this->assertSame(23.3, $alimento->protein_100);
    }

    /**
     * 🚨 **Il catalogo non può far fallire il diario.**
     *
     * Perdere quello che una persona ha appena registrato per colpa di una
     * funzione accessoria sarebbe il difetto peggiore possibile qui.
     */
    #[Test]
    public function a_broken_catalogue_does_not_lose_the_meal(): void
    {
        $utente = $this->iscritto();

        // Un nome impossibile da normalizzare in una chiave utile, macro
        // assurdi, e una descrizione che sembra un pasto: tutto ciò che può
        // far dire di no al catalogo, insieme.
        $voce = $this->scrivi($utente, 'roba con pane e olio e sale', [
            'kcal_100' => 5000.0, 'protein_100' => 900.0, 'carbs_100' => 900.0, 'fat_100' => 900.0,
        ]);

        $this->assertSame(0, Food::query()->count());
        $this->assertNotNull($voce->fresh());
        $this->assertNull($voce->fresh()->food_id);
    }

    // ═════════════════ la scrittura a blocchi ═════════════════

    /**
     * \U0001F6A8 **Un'importazione non azzera quante volte un alimento e' stato scelto.**
     *
     * ── Il difetto che questo test esiste per fermare ────────────────
     *
     * `usi` e' il conteggio che decide **l'ordine dei suggerimenti**. ⚠️ Se un
     * `upsert` lo riscrivesse, dopo ogni importazione i suggerimenti
     * tornerebbero in ordine alfabetico — senza nessun errore, senza niente nei
     * log, e senza che nessuno colleghi la cosa all'importazione della notte
     * prima.
     *
     * \U0001F4A1 E' il motivo per cui `usi` **non** e' nell'elenco delle colonne
     * aggiornabili di `ScritturaABlocchi`.
     */
    #[Test]
    public function an_import_never_resets_how_often_a_food_was_chosen(): void
    {
        $alimento = Food::query()->create([
            'chiave' => 'petto pollo',
            'nome' => 'Petto di pollo',
            'kcal_100' => 100.0, 'protein_100' => 23.3, 'carbs_100' => 0.0, 'fat_100' => 0.8,
            'usi' => 417,
            'conferme' => 2,
            'pubblico' => true,
        ]);

        $scrittura = new ScritturaABlocchi;

        $scrittura->aggiungi([
            'chiave' => 'petto pollo',
            'nome' => 'Petto di pollo (aggiornato)',
            'marca' => null,
            'kcal_100' => 105.0, 'protein_100' => 24.0, 'carbs_100' => 0.0, 'fat_100' => 1.0,
            'basis' => 'g',
            'origine' => Food::ORIGINE_MANUALE,
            'fonte' => 'OFF:123',
            'note' => 'Fonte: Open Food Facts',
            'codice_a_barre' => null,
            'immagine_url' => null,
            'immagine_piccola_url' => null,
            'pubblico' => true,
            'conferme' => 2,
            'usi' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scrittura->scarica();

        $alimento->refresh();

        // I dati si aggiornano...
        $this->assertSame('Petto di pollo (aggiornato)', $alimento->nome);
        $this->assertSame(105.0, $alimento->kcal_100);

        // \U0001F6A8 ...ma il conteggio degli usi resta intatto.
        $this->assertSame(417, $alimento->usi);
    }

    /**
     * ⚠️ **Due righe con la stessa chiave nello stesso blocco.**
     *
     * Succede davvero: lo stesso file contiene varianti di formato dello
     * stesso prodotto — stesso nome, stessa marca, codici a barre diversi.
     * \U0001F6A8 Passate insieme a un `upsert`, MySQL non sa quale tenere e fa
     * fallire **l'intero blocco**: mille prodotti persi per un doppione.
     */
    #[Test]
    public function two_rows_with_the_same_key_do_not_break_the_batch(): void
    {
        $scrittura = new ScritturaABlocchi;

        foreach (['primo', 'secondo'] as $i => $nome) {
            $scrittura->aggiungi([
                'chiave' => 'stessa chiave',
                'nome' => $nome,
                'marca' => null,
                'kcal_100' => 100.0 + $i, 'protein_100' => 1.0, 'carbs_100' => 1.0, 'fat_100' => 1.0,
                'basis' => 'g',
                'origine' => Food::ORIGINE_MANUALE,
                'fonte' => 'OFF:'.$i,
                'note' => null,
                'codice_a_barre' => null,
                'immagine_url' => null,
                'immagine_piccola_url' => null,
                'pubblico' => true,
                'conferme' => 2,
                'usi' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $scrittura->scarica();

        // \U0001F4A1 Una riga sola, ed e' l'ultima arrivata: lo stesso esito che
        // dava la scrittura una-per-volta.
        $this->assertSame(1, Food::query()->count());
        $this->assertSame('secondo', Food::query()->sole()->nome);
    }

    /**
     * \U0001F6A8 **L'ultimo blocco e' quasi sempre incompleto.**
     *
     * ⚠️ Senza la chiamata finale a `scarica()` si perderebbero fino a mille
     * prodotti — in silenzio, perche' nessuno fallisce.
     */
    #[Test]
    public function what_is_left_in_the_batch_is_not_lost(): void
    {
        $scrittura = new ScritturaABlocchi;

        $scrittura->aggiungi([
            'chiave' => 'mela',
            'nome' => 'Mela',
            'marca' => null,
            'kcal_100' => 52.0, 'protein_100' => 0.3, 'carbs_100' => 13.8, 'fat_100' => 0.2,
            'basis' => 'g',
            'origine' => Food::ORIGINE_MANUALE,
            'fonte' => 'OFF:1',
            'note' => null,
            'codice_a_barre' => null,
            'immagine_url' => null,
            'immagine_piccola_url' => null,
            'pubblico' => true,
            'conferme' => 2,
            'usi' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Una riga sola non riempie il blocco da mille: finche' non si scarica,
        // in banca dati non c'e' niente.
        $this->assertSame(0, Food::query()->count());

        $scrittura->scarica();

        $this->assertSame(1, Food::query()->count());
        $this->assertSame(1, $scrittura->scritte());
    }

    // ───────────────────────── aiutanti ─────────────────────────

    private function iscritto(string $email = 'iscritto@alfa.test'): User
    {
        return $this->creaUtente($this->alfa, UserRole::Member, $email);
    }

    /** @param  array<string, float|null>|null  $per100 */
    private function scrivi(User $utente, string $nome, ?array $per100 = null): FoodEntry
    {
        return FoodEntry::query()->create([
            'tenant_id' => $utente->tenant_id,
            'user_id' => $utente->getKey(),
            'eaten_at' => now(),
            'meal' => 'lunch',
            'description' => $nome,
            ...($per100 ?? self::POLLO),
        ]);
    }
}
