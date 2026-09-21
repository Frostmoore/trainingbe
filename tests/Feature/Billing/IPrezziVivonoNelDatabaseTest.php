<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Filament\God\Resources\PacchettiGettoni\Pages\ListPacchettiGettoni;
use App\Models\PacchettoGettoni;
use App\Services\Billing\Listino;
use Database\Seeders\PacchettiGettoniSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 💶 **I prezzi dei gettoni vivono nel database** — 22/09/2026, fase F2 di
 * `plan_UX_pannello.md`.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * > *«mi serve una sezione per impostare i prezzi dei pacchetti di token»* …
 * > *«Ah certo, ovviamente devono essere nel database, non in un file»*
 *
 * ══ 🚨 IL RISCHIO CHE QUESTO FILE SORVEGLIA ══════════════════════════════
 *
 * Spostare i prezzi dal file al database riapre, se fatto male, il difetto
 * §86.1: **due listini che si contraddicono**, uno dei quali in vendita. Su
 * staging il piano personale costava 4,99 € a database e 7,99 € sul sito.
 *
 * 💡 La regola che lo tiene chiuso è una sola: **il file è il seme, la tabella
 * è la verità**, e il seeder del deploy non torna sopra a ciò che una persona
 * ha cambiato dal pannello.
 *
 * ⛔ **E nessun ripiego sul file**: se la tabella è vuota, il listino è vuoto.
 * Un `?: config(...)` di cortesia vorrebbe dire vendere prezzi diversi da
 * quelli che il pannello mostra, senza che nessuno se ne accorga.
 */
class IPrezziVivonoNelDatabaseTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    #[Test]
    public function il_seme_riempie_la_tabella_coi_prezzi_del_file(): void
    {
        $this->seed(PacchettiGettoniSeeder::class);

        $dalFile = config('listino.pacchetti');
        $inTabella = PacchettoGettoni::query()->inVendita()->get();

        $this->assertCount(count($dalFile), $inTabella);

        $this->assertSame(
            array_map(static fn (array $p): int => $p['prezzo_cent'], $dalFile),
            $inTabella->pluck('prezzo_cent')->all(),
        );
    }

    /**
     * 🚨 **Il cuore della fase**: chi cambia un prezzo dal pannello non se lo
     * ritrova indietro al primo deploy.
     *
     * ⛔ Senza la colonna `modificato_a_mano`, il seeder riscriverebbe la riga
     * **in silenzio**: il sito tornerebbe a vendere a 3,00 € un taglio portato
     * a 3,50 €, e lo si scoprirebbe da un incasso sbagliato.
     */
    #[Test]
    public function il_deploy_non_riporta_indietro_un_prezzo_cambiato_a_mano(): void
    {
        $this->seed(PacchettiGettoniSeeder::class);

        $taglio = PacchettoGettoni::query()->where('codice', 'gettoni_100')->sole();

        $taglio->forceFill(['prezzo_cent' => 350, 'modificato_a_mano' => true])->save();

        // 🌙 Il deploy della notte dopo.
        $this->seed(PacchettiGettoniSeeder::class);

        $this->assertSame(350, $taglio->refresh()->prezzo_cent);
    }

    /** 💡 E su ciò che nessuno ha toccato, il seme resta la fonte. */
    #[Test]
    public function ma_su_quello_che_nessuno_tocca_il_seme_comanda(): void
    {
        $this->seed(PacchettiGettoniSeeder::class);

        $taglio = PacchettoGettoni::query()->where('codice', 'gettoni_100')->sole();

        // ⚠️ Cambiato **senza** marcarlo: è il caso di una riga storta, non di
        // una decisione.
        $taglio->forceFill(['prezzo_cent' => 1])->save();

        $this->seed(PacchettiGettoniSeeder::class);

        $this->assertSame(300, $taglio->refresh()->prezzo_cent);
    }

    /**
     * ⛔ **Un taglio sospeso sparisce dal listino**, e resta in tabella.
     *
     * 🚨 Cancellarlo renderebbe illeggibile la storia di chi l'ha comprato:
     * `ai_credit_movements` racconta acquisti fatti con quel codice.
     */
    #[Test]
    public function un_taglio_sospeso_non_si_vende_piu_ma_resta(): void
    {
        $this->seed(PacchettiGettoniSeeder::class);

        $taglio = PacchettoGettoni::query()->where('codice', 'gettoni_500')->sole();
        $taglio->forceFill(['attivo' => false])->save();

        $codici = array_column(app(Listino::class)->pacchettiGettoni(), 'codice');

        $this->assertNotContains('gettoni_500', $codici);
        $this->assertNotNull(PacchettoGettoni::query()->where('codice', 'gettoni_500')->first());
    }

    /**
     * 🚨 **Senza tabella, listino vuoto.** ⛔ Nessun ripiego silenzioso sul
     * file: è la regola che impedisce i due listini.
     */
    #[Test]
    public function senza_righe_il_listino_e_vuoto_e_non_ripiega_sul_file(): void
    {
        $this->assertSame([], app(Listino::class)->pacchettiGettoni());

        // ⚠️ E il file è ancora pieno: è proprio il caso che non deve ripiegare.
        $this->assertNotEmpty(config('listino.pacchetti'));
    }

    /** 🎛️ La pagina del pannello si apre e mostra i tagli. */
    #[Test]
    public function la_pagina_del_pannello_si_apre(): void
    {
        $this->seed(PacchettiGettoniSeeder::class);

        $this->actingAs($this->creaSuperAdmin());
        filament()->setCurrentPanel('god');

        Livewire::test(ListPacchettiGettoni::class)
            ->assertSuccessful()
            ->assertSee('Prova')
            ->assertSee('3,00 €')
            ->assertSee('1,50 cent');
    }
}
