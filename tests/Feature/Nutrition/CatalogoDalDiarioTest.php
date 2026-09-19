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
     * 🆕 🚨 **Scrivere nel diario NON mette niente nel catalogo** — D7,
     * 19/09/2026.
     *
     * ⛔ Qui c'erano sette test che proteggevano la regola opposta, del 17/08:
     * *«ogni voce di diario alimenta il catalogo»*, privata finche' una seconda
     * persona non scriveva lo stesso nome. 📌 Il committente l'ha rovesciata:
     * *«io posso pure creare un alimento "merda di piccione" ma questo non deve
     * finire sul server: lo devo solo vedere io»* · *«gli alimenti di un utente
     * restano i suoi e li vede solo lui perché stanno sul suo telefono»*.
     *
     * 💡 Il diario sta gia' sul telefono (Parte I), e l'app cerca gli alimenti
     * suoi li'. Questo test tiene chiusa la porta del server — che l'app non
     * usa piu', ma un'app vecchia si'.
     */
    #[Test]
    public function writing_in_the_diary_does_not_put_anything_in_the_catalogue(): void
    {
        $uno = $this->iscritto('uno@alfa.test');
        $due = $this->iscritto('due@alfa.test');

        $voce = $this->scrivi($uno, 'Merda di piccione');
        $this->scrivi($due, 'Merda di piccione');

        $this->assertSame(0, Food::query()->count(), 'Nessun alimento doveva nascere dal diario.');
        $this->assertNull($voce->fresh()->food_id);
    }

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
