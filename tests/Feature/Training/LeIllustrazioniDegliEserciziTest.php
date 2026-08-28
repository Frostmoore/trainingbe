<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Console\Commands\AttaccaLeIllustrazioni;
use App\Models\Exercise;
use App\Support\Training\IllustrazioniDegliEsercizi;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Il disegno di ogni esercizio — 3b-L, 28/08/2026.
 *
 * 📌 *«Scarica i svg e mettili su tutti gli esercizi»*.
 *
 * ══ 🚨 COSA SI ROMPE IN SILENZIO, E PERCHE' QUESTI TEST ESISTONO ══════════
 *
 * ⛔ Tutti i modi in cui questa roba sbaglia producono **la stessa cosa a
 * schermo**: nessuna immagine. E «nessuna immagine» e' gia' un caso legittimo
 * — sette esercizi non ce l'hanno per davvero, e `Miniatura` disegna il
 * segnaposto senza lamentarsi.
 *
 * 🚨 Quindi un errore di battitura in un nome italiano, un file rinominato o
 * una riga di seeder tolta **non danno nessun errore**: danno un esercizio in
 * meno con la figura, in mezzo a trecento. Nessuno se ne accorge scorrendo.
 *
 * 💡 Ognuno dei test qui sotto trasforma uno di quei silenzi in un rosso.
 */
final class LeIllustrazioniDegliEserciziTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ogni_disegno_citato_dalla_mappa_esiste_davvero(): void
    {
        $cartella = database_path('data/illustrazioni');
        $mancanti = [];

        foreach (IllustrazioniDegliEsercizi::tutte() as $nome => $slug) {
            if (! is_file("$cartella/$slug.png")) {
                $mancanti[] = "$nome → $slug.png";
            }
        }

        $this->assertSame(
            [],
            $mancanti,
            "La mappa cita disegni che non ci sono:\n  ".implode("\n  ", $mancanti)
        );
    }

    /**
     * 🚨 **Il test piu' importante del file.**
     *
     * ⛔ Un nome scritto anche solo con una lettera diversa in
     * `IllustrazioniDegliEsercizi` rispetto a `ExerciseLibrarySeeder` non
     * rompe niente: il comando non trova l'esercizio, lo elenca fra gli
     * «assenti» e va avanti. ⚠️ E quell'elenco lo legge chi lancia il comando,
     * una volta, mesi fa.
     *
     * 💡 Qui invece la differenza diventa un rosso con scritto il nome esatto.
     */
    #[Test]
    public function ogni_nome_della_mappa_e_un_esercizio_che_esiste(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $catalogo = Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->pluck('slug_normalized')
            ->all();

        $orfani = [];

        foreach (array_keys(IllustrazioniDegliEsercizi::tutte()) as $nome) {
            if (! in_array(Exercise::normalize($nome), $catalogo, true)) {
                $orfani[] = $nome;
            }
        }

        $this->assertSame(
            [],
            $orfani,
            "Nomi nella mappa che il seeder non crea:\n  ".implode("\n  ", $orfani)
        );
    }

    /**
     * ⚠️ Il contrario del precedente, e dice una cosa diversa: quali esercizi
     * restano senza figura. 🚨 Il numero e' scritto **apposta**: se domani
     * qualcuno aggiunge un esercizio al seeder e si scorda la riga nella
     * mappa, questo test glielo dice — invece di lasciare un buco che si
     * scopre guardando l'app.
     */
    #[Test]
    public function si_sa_esattamente_quanti_esercizi_restano_senza_disegno(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $senza = Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->get()
            ->reject(fn (Exercise $e): bool => IllustrazioniDegliEsercizi::slugDi($e->name) !== null)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [
                'Girata',
                'Glute ham raise',
                'Leg curl in piedi',
                'Pullover',
                'Rullo per avambracci',
                'Slam ball',
                'Thruster',
            ],
            $senza,
            'Sono cambiati gli esercizi senza disegno: se e\' voluto, aggiorna '
            .'anche `IllustrazioniDegliEsercizi` e il suo elenco documentato.'
        );
    }

    #[Test]
    public function il_comando_attacca_i_disegni_e_ci_scrive_sopra_chi_li_ha_fatti(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $this->artisan(AttaccaLeIllustrazioni::class)->assertSuccessful();

        $panca = Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('name', 'Panca piana')
            ->firstOrFail();

        $this->assertNotNull($panca->imageUrl());
        $this->assertSame('Bryl Lim / Everkinetic — CC BY-SA 4.0', $panca->imageCredit());
    }

    /**
     * 🚨 `preservingOriginal()` non e' un dettaglio di stile: senza,
     * `addMedia()` **sposta** il file. La prima esecuzione svuoterebbe
     * `database/data/illustrazioni/`, cioe' cancellerebbe dal repository i
     * file committati — e la seconda non troverebbe piu' niente.
     */
    #[Test]
    public function il_comando_non_si_porta_via_i_file_del_repository(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $cartella = database_path('data/illustrazioni');
        $prima = count(glob("$cartella/*.png"));

        $this->artisan(AttaccaLeIllustrazioni::class)->assertSuccessful();

        $this->assertSame($prima, count(glob("$cartella/*.png")));
    }

    #[Test]
    public function rilanciarlo_non_rifa_il_lavoro(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $this->artisan(AttaccaLeIllustrazioni::class)->assertSuccessful();

        $panca = Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('name', 'Panca piana')
            ->firstOrFail();

        $primo = $panca->getFirstMedia(Exercise::COLLECTION_IMMAGINE)?->id;

        $this->artisan(AttaccaLeIllustrazioni::class)
            ->expectsOutputToContain('illustrazioni messe: 0')
            ->assertSuccessful();

        $this->assertSame($primo, $panca->fresh()->getFirstMedia(Exercise::COLLECTION_IMMAGINE)?->id);
    }

    /**
     * ⚖️ 🚨 **Un credito falso e' peggio del nessun credito.**
     *
     * Se una palestra carica **la foto della sua macchina**, quella foto e'
     * sua: scriverci sotto «Bryl Lim / Everkinetic» attribuirebbe a qualcun
     * altro un lavoro che non ha fatto. ⛔ Ed e' anche il motivo per cui il
     * comando non gliela sostituisce.
     */
    #[Test]
    public function una_foto_caricata_da_una_persona_non_prende_il_nostro_credito(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);

        $panca = Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('name', 'Panca piana')
            ->firstOrFail();

        // Come farebbe il pannello: nessuna proprieta' `origine`.
        $panca
            ->addMedia(database_path('data/illustrazioni/squat.png'))
            ->preservingOriginal()
            ->usingFileName('foto-della-palestra.png')
            ->toMediaCollection(Exercise::COLLECTION_IMMAGINE);

        $this->assertNull($panca->fresh()->imageCredit());

        $this->artisan(AttaccaLeIllustrazioni::class)->assertSuccessful();

        $panca = $panca->fresh();

        $this->assertSame(
            'foto-della-palestra.png',
            $panca->getFirstMedia(Exercise::COLLECTION_IMMAGINE)?->file_name,
            'Il comando ha sovrascritto una foto caricata a mano.'
        );
        $this->assertNull($panca->imageCredit());
    }

    /**
     * ⚠️ L'app riceve il credito **dalla stessa risposta** che porta l'URL.
     * ⛔ Se viaggiassero separati, il giorno che una palestra sostituisce il
     * disegno con una foto sua l'app scriverebbe ancora il vecchio credito.
     */
    #[Test]
    public function il_credito_viaggia_insieme_all_immagine_nella_risposta(): void
    {
        $this->seed(ExerciseLibrarySeeder::class);
        $this->artisan(AttaccaLeIllustrazioni::class)->assertSuccessful();

        $panca = Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('name', 'Panca piana')
            ->firstOrFail();

        $this->assertNotNull($panca->imageUrl());
        $this->assertNotNull($panca->imageCredit());
    }
}
