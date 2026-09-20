<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🈯 **Gli errori di validazione sono frasi, non chiavi** — 20/09/2026.
 *
 * ⛔ Visto sull'emulatore: mandando un documento, l'app mostrava
 * **«validation.array»**. Non c'era nessun `lang/it/validation.php`, Laravel ne
 * porta solo `en` e `APP_FALLBACK_LOCALE=it` non lascia niente su cui ripiegare.
 *
 * 🚨 Riguardava **ogni** errore di validazione senza messaggio scritto a mano:
 * un campo obbligatorio vuoto, un'email storta, un file troppo grande.
 */
class MessaggiDiValidazioneTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    #[Test]
    public function un_errore_di_validazione_non_mostra_mai_la_chiave(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'mario@esempio.test');

        // 💡 Una rotta qualunque che valida e basta: il punto è la **frase**,
        // non quale endpoint la produce.
        $errori = $this->comeApp($persona)
            ->patchJson('/api/v1/account/email', [])
            ->assertStatus(422)
            ->json('errors');

        $this->assertNotEmpty($errori);

        foreach ($errori as $campo => $messaggi) {
            foreach ($messaggi as $messaggio) {
                $this->assertStringNotContainsString(
                    'validation.',
                    $messaggio,
                    "Il campo «{$campo}» risponde con la chiave invece che con una frase: {$messaggio}",
                );
            }
        }
    }

    /**
     * 🈯 **E nemmeno «Too Many Attempts.»** — 20/09/2026.
     *
     * ⛔ Quel messaggio è scritto dentro l'eccezione di Laravel, non in un file
     * di lingua: chi riprovava troppo in fretta leggeva l'inglese, e per giunta
     * senza sapere **quanto** aspettare.
     */
    #[Test]
    public function anche_il_limite_di_frequenza_parla_italiano(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'luigi@esempio.test');

        $risposta = null;

        // 💡 Il limite vero è alto: si insiste finché non scatta, e non si
        // finge — il messaggio dev'essere quello che esce davvero.
        for ($i = 0; $i < 80; $i++) {
            $risposta = $this->comeApp($persona)->postJson('/api/v1/importazioni', []);

            if ($risposta->status() === 429) {
                break;
            }
        }

        $this->assertSame(429, $risposta->status(), 'Il limite di frequenza non è scattato.');
        $this->assertStringNotContainsString('Too Many', (string) $risposta->json('message'));
        $this->assertStringContainsString('troppe volte', (string) $risposta->json('message'));
    }

    /** 💡 E la frase è in italiano, non l'inglese di Laravel. */
    #[Test]
    public function e_in_italiano(): void
    {
        app()->setLocale('it');

        $errori = validator(
            ['email' => 'non-una-email', 'password' => ''],
            ['email' => ['email'], 'password' => ['required']],
        )->errors();

        $this->assertSame('Manca la password.', $errori->first('password'));
        $this->assertStringContainsString('email valido', (string) $errori->first('email'));
    }
}
