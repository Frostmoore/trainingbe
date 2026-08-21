<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Il cancello della versione — FASE 10, 21/08/2026.
 *
 * ── 🚨 Cosa difende questo file ────────────────────────────────────────────
 *
 * 📌 Il committente: *«se mai in futuro dovessimo cambiare server, cosa
 * probabile, la gente non continua a vedere le api vecchie»*.
 *
 * ⚠️ Ma la cosa che questi test proteggono davvero è **il contrario del
 * blocco**: che il cancello sia chiuso solo quando lo diciamo noi, per la
 * piattaforma giusta, e a chi si è presentato. 🚨 Un cancello che sbaglia in
 * questa direzione **ferma tutti gli utenti insieme**, e l'unico rimedio è un
 * altro intervento sul server.
 */
final class VersioneMinimaTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, string>  $intestazioni */
    private function chiama(array $intestazioni = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/branding/lookup?code=NONESISTE', $intestazioni);
    }

    #[Test]
    public function di_serie_non_blocca_nessuno(): void
    {
        /*
         * 🚨 **Il test più importante del file.** Il default di
         * `app_versione.minima` è `0`, cioè «non blocco nessuno», e non è
         * pigrizia: è l'interruttore di sicurezza. ⚠️ Un cancello che di serie è
         * chiuso è un cancello che il giorno del primo deploy chiude fuori
         * tutti, compresi noi.
         */
        $this->assertSame(0, (int) config('app_versione.minima.android'));

        $this->chiama(['X-App-Build' => '1', 'X-App-Platform' => 'android'])
            ->assertStatus(404);
    }

    #[Test]
    public function una_build_sotto_il_minimo_riceve_426_e_l_indirizzo_dello_store(): void
    {
        config()->set('app_versione.minima.android', 74500);

        $risposta = $this->chiama([
            'X-App-Build' => '74400',
            'X-App-Platform' => 'android',
        ])->assertStatus(426);

        $risposta->assertJsonPath('code', 'app_da_aggiornare');
        $risposta->assertJsonPath('minima', 74500);

        /*
         * 💡 L'indirizzo dello store lo manda **il server**: il giorno che
         * l'identificativo del pacchetto cambia, le copie già installate
         * manderebbero la gente sulla scheda sbagliata — e sono proprio quelle
         * che non si possono aggiornare.
         */
        $this->assertStringContainsString('play.google.com', (string) $risposta->json('store'));
    }

    #[Test]
    public function la_build_giusta_passa(): void
    {
        config()->set('app_versione.minima.android', 74500);

        $this->chiama(['X-App-Build' => '74500', 'X-App-Platform' => 'android'])
            ->assertStatus(404);
    }

    #[Test]
    public function chi_non_si_presenta_passa(): void
    {
        /*
         * ⚠️ Le uniche cose che chiamano l'API senza `X-App-Build` sono il
         * pannello Filament e le nostre verifiche con `curl`. 🚨 Un cancello che
         * blocca ciò che non sa riconoscere blocca **prima di tutto noi**, e lo
         * fa nel momento peggiore — mentre si cerca di capire cosa non va.
         */
        config()->set('app_versione.minima.android', 999999);

        $this->chiama()->assertStatus(404);
        $this->chiama(['X-App-Build' => 'non-un-numero'])->assertStatus(404);
    }

    #[Test]
    public function il_minimo_e_per_piattaforma(): void
    {
        /*
         * 🚨 Alzare il minimo per Android quando la versione iOS non è ancora
         * approvata fermerebbe gli utenti iOS **per una cosa che non possono
         * fare**: sugli store le pubblicazioni non arrivano insieme.
         */
        config()->set('app_versione.minima.android', 999999);
        config()->set('app_versione.minima.ios', 0);

        $this->chiama(['X-App-Build' => '1', 'X-App-Platform' => 'android'])
            ->assertStatus(426);

        $this->chiama(['X-App-Build' => '1', 'X-App-Platform' => 'ios'])
            ->assertStatus(404);
    }

    #[Test]
    public function la_rotta_della_versione_risponde_anche_a_chi_e_bloccato(): void
    {
        /*
         * 🚨 Senza questa eccezione, `GET /versione` starebbe dietro il cancello
         * che lui stesso descrive: l'app bloccata non potrebbe nemmeno chiedere
         * «sono ancora vecchia?». 💡 Serve quando il blocco è stato **un errore
         * nostro** — senza, per toglierlo servirebbe un'altra pubblicazione.
         */
        config()->set('app_versione.minima.android', 999999);

        $this->getJson('/api/v1/versione', ['X-App-Build' => '1'])
            ->assertOk()
            ->assertJsonPath('data.minima', 999999);
    }

    #[Test]
    public function il_confronto_e_fra_numeri_e_non_fra_stringhe(): void
    {
        /*
         * ══ 🚨 LA TRAPPOLA CHE QUESTO TEST ESISTE PER FERMARE ════════════════
         *
         * Confrontando le **stringhe**, `'74500'` risulta minore di `'9000'`
         * perché `'7' < '9'`. ⚠️ In numeri è l'opposto, ed è quello che conta.
         *
         * 💡 È lo stesso motivo per cui si confronta il `versionCode` e non
         * `7.45.0`: fra stringhe, `7.10.0` è minore di `7.9.0` — e non ce ne si
         * accorge fino alla decima versione minore, cioè fra mesi.
         */
        config()->set('app_versione.minima.android', 9000);

        $this->chiama(['X-App-Build' => '74500'])->assertStatus(404);
        $this->chiama(['X-App-Build' => '8999'])->assertStatus(426);
    }
}
