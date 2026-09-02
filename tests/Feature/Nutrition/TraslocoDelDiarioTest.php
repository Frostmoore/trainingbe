<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Enums\UserRole;
use App\Models\FoodEntry;
use App\Models\FoodFavorite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il diario trasloca sul telefono — Parte I, I3.
 *
 * ══ 📌 PERCHE' ═══════════════════════════════════════════════════════════
 *
 * 📌 Regola R3: *«tutto cio' che e' anche lontanamente sensibile resta sul
 * telefono»*. 🚨 `food_entries` era l'ultima tabella grossa di dati personali
 * rimasta qui.
 *
 * ══ 🚨 COSA DIFENDE QUESTO FILE ══════════════════════════════════════════
 *
 * Un trasloco che consegna **meta'** del diario non da' nessun errore: il
 * telefono scrive quello che riceve, i totali si ricalcolano, e i numeri
 * sembrano veri. ⛔ Mezzo diario e' **peggio** di nessun diario.
 *
 * 💡 Da qui i due conteggi nella risposta: sono l'unica cosa che permette al
 * telefono di **accorgersene**.
 */
final class TraslocoDelDiarioTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'anna@alfa.test');
    }

    private function segna(string $giorno, string $pasto, int $kcal, ?User $di = null): FoodEntry
    {
        return FoodEntry::create([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => ($di ?? $this->iscritto)->getKey(),
            'eaten_at' => Carbon::parse($giorno)->startOfDay(),
            'meal' => $pasto,
            'description' => 'Qualcosa',
            'kcal' => $kcal,
            'protein' => 10,
            'carbs' => 20,
            'fat' => 5,
        ]);
    }

    #[Test]
    public function il_pacchetto_porta_tutto_il_diario(): void
    {
        $this->segna('2026-09-01', 'lunch', 700);
        $this->segna('2026-09-02', 'dinner', 800);

        $dati = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/trasloco/diario')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $dati['quante_voci']);
        $this->assertCount(2, $dati['voci']);

        /*
         * 🚨 **`created_at` viaggia**, ed e' il campo che distingue una cena
         * **programmata** alle 10 del mattino da una mangiata alle 21. Senza,
         * il consiglio del giorno tornerebbe a sbagliare come prima di 3b-AC.
         */
        $this->assertArrayHasKey('created_at', $dati['voci'][0]);

        // 💡 E i valori per 100 g, senza i quali correggere una quantita'
        // vorrebbe dire pagare un gettone per una moltiplicazione.
        $this->assertArrayHasKey('kcal_100', $dati['voci'][0]);
    }

    /**
     * ⛔ **Il diario di un altro non si vede.**
     *
     * 🚨 E' la premessa di tutto: un endpoint che consegna «il diario» senza
     * dire di chi consegnerebbe l'art. 9 di qualcun altro, e lo farebbe in una
     * risposta che sembra giusta.
     */
    #[Test]
    public function il_diario_di_un_altro_non_ci_finisce(): void
    {
        $altro = $this->creaUtente($this->palestra, UserRole::Member, 'altro@alfa.test');

        $this->segna('2026-09-01', 'lunch', 700);
        $this->segna('2026-09-01', 'dinner', 900, di: $altro);

        $dati = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/trasloco/diario')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $dati['quante_voci']);
        $this->assertCount(1, $dati['voci']);
    }

    /** 💡 I preferiti viaggiano con il diario: sono fatti della stessa sostanza. */
    #[Test]
    public function anche_i_preferiti_traslocano(): void
    {
        FoodFavorite::create([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => $this->iscritto->getKey(),
            'description' => 'Colazione tipo',
            'kcal' => 400,
        ]);

        $dati = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/trasloco/diario')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $dati['quanti_preferiti']);
        $this->assertSame('Colazione tipo', $dati['preferiti'][0]['description']);
    }

    /**
     * 🚨 **Il conteggio viene dal database, non dall'array spedito.**
     *
     * ⛔ Contare l'array risponderebbe sempre «tutte», tetto compreso: il
     * telefono confronterebbe un numero **con se stesso** e sarebbe sempre
     * d'accordo. 💡 Cosi' invece, il giorno che il tetto taglia qualcosa, i due
     * numeri non tornano — ed e' esattamente quello che deve succedere.
     */
    #[Test]
    public function i_conteggi_dicono_quante_ce_ne_sono_davvero(): void
    {
        foreach (range(1, 5) as $i) {
            $this->segna('2026-09-01', 'lunch', 100 * $i);
        }

        $dati = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/trasloco/diario')
            ->assertOk()
            ->json('data');

        $this->assertSame(5, $dati['quante_voci']);
        $this->assertSame(
            $dati['quante_voci'],
            count($dati['voci']),
            'Sotto il tetto i due numeri coincidono: e\' quando divergono che il telefono si ferma.',
        );
    }

    /**
     * ⛔ **Non cancella niente.**
     *
     * 🚨 Il server si svuota in I4, con una migration, **dopo** che il trasloco
     * e' stato verificato sul telefono vero. Un endpoint che consegna e cancella
     * nella stessa richiesta perde tutto il giorno che la risposta non arriva a
     * destinazione — ed e' il giorno in cui ci si accorge che la rete esiste.
     */
    #[Test]
    public function consegna_e_non_cancella(): void
    {
        $this->segna('2026-09-01', 'lunch', 700);

        $this->comeApp($this->iscritto)->getJson('/api/v1/trasloco/diario')->assertOk();

        $this->assertSame(1, FoodEntry::withoutGlobalScopes()->count());
    }

    /** ⛔ E senza accesso non si consegna niente. */
    #[Test]
    public function serve_l_accesso(): void
    {
        $this->getJson('/api/v1/trasloco/diario')->assertUnauthorized();
    }
}
