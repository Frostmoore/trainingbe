<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 🔑 **«Con cosa posso entrare?»** — voce 25, 26/09/2026.
 *
 * ══ ⛔ IL DIFETTO CHE QUESTI TEST CHIUDONO ═════════════════════════════════
 *
 * 🚨 L'elenco dei fornitori viaggiava **solo** dentro `Tenant::branding()`, e
 * il branding arriva con l'accesso o con l'invito — cioe' **dopo**. ⛔ Chi
 * apriva l'app per la prima volta aveva `GymBranding.neutral`, con l'elenco
 * vuoto, e i pulsanti «Continua con Google» non comparivano **mai**.
 *
 * ⚠️ La rotta pubblica che serviva a questo (`branding/lookup`) e' stata tolta
 * con F11 il 24/09/2026 insieme al codice palestra. 🚨 Nessuno se n'e' accorto
 * perche' l'accesso social era comunque spento: il sintomo del difetto era
 * **identico** al comportamento atteso.
 */
class FornitoriSocialApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🎯 **Il test del difetto.** Se questa rotta non risponde a chi non e'
     * autenticato, l'app non puo' sapere quali pulsanti disegnare prima
     * dell'accesso — che e' l'unico momento in cui le serve saperlo.
     */
    #[Test]
    public function si_puo_chiedere_senza_essere_nessuno(): void
    {
        config(['services.social.google.client_ids' => 'uno.apps.googleusercontent.com']);

        $this->getJson('/api/v1/auth/fornitori')
            ->assertOk()
            ->assertJsonPath('data.0', 'google');
    }

    /**
     * ⚠️ **Un fornitore senza credenziali non va annunciato.** ⛔ Annunciarlo
     * vorrebbe dire disegnare un pulsante che risponde sempre errore, e un
     * pulsante che fallisce sempre fa sembrare rotta tutta l'applicazione.
     */
    #[Test]
    public function un_fornitore_spento_non_compare(): void
    {
        config([
            'services.social.google.client_ids' => '',
            'services.social.apple.client_ids' => '',
        ]);

        $this->getJson('/api/v1/auth/fornitori')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    /** 💡 E quando ci sono tutti e due, ci sono tutti e due. */
    #[Test]
    public function li_elenca_tutti_quelli_accesi(): void
    {
        config([
            'services.social.google.client_ids' => 'uno.apps.googleusercontent.com',
            'services.social.apple.client_ids' => 'com.smp.mytrainingcompanion',
        ]);

        $risposta = $this->getJson('/api/v1/auth/fornitori')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['google', 'apple'],
            $risposta->json('data'),
        );
    }

    /**
     * 🔒 **Non deve uscire niente oltre ai nomi.**
     *
     * ⛔ Gli identificativi dei client servono all'app per costruire la
     * richiesta, non per sapere se disegnare un pulsante: pubblicarli qui
     * sarebbe regalare configurazione senza motivo.
     *
     * 🚨 E' il tipo di svista che si fa allargando una risposta «tanto e' gia'
     * pubblica»: questo test la blocca.
     */
    #[Test]
    public function non_lascia_uscire_gli_identificativi_dei_client(): void
    {
        config([
            'services.social.google.client_ids' => 'segretuccio.apps.googleusercontent.com',
            'services.social.apple.client_ids' => 'com.smp.mytrainingcompanion',
        ]);

        $corpo = $this->getJson('/api/v1/auth/fornitori')->assertOk()->getContent();

        $this->assertStringNotContainsString('segretuccio', (string) $corpo);
        $this->assertStringNotContainsString('googleusercontent', (string) $corpo);
        $this->assertStringNotContainsString('com.smp', (string) $corpo);
    }
}
