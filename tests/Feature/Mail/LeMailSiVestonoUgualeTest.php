<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\BenvenutoMail;
use App\Mail\InvitoDelTrainerMail;
use App\Mail\PagamentoFallitoMail;
use App\Mail\PasswordCambiataMail;
use App\Mail\RichiestaDalSitoMail;
use App\Mail\RichiestaRicevutaMail;
use App\Models\RichiestaDiContatto;
use App\Models\Tenant;
use App\Models\TrainerInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 📧 **Tutte le mail escono vestite uguali, e dicono cose vere** — 22/09/2026.
 *
 * ══ 🚨 I DUE DIFETTI CHE QUESTO TEST AVREBBE VISTO ════════════════════════
 *
 * Trovati guardando le anteprime nel browser, e nessuno dei due dava errore:
 *
 * 1. ⛔ **Il modello intero stampato in JSON** al posto del nome. Una chiave di
 *    `Content::with` che si chiama come una **proprietà pubblica** del Mailable
 *    non la sostituisce: in `InvitoDelTrainerMail` la proprietà `$trainer` (un
 *    `User`) vinceva su `'trainer' => $this->trainer->name`, e la mail si
 *    apriva con `{"locale":"it","is_active":true,…}`.
 * 2. ⛔ **Il marchio con un indirizzo relativo** (`/img/sito/marchio.svg`): in
 *    una mail non esiste niente di relativo, e quel file era anche un SVG, che
 *    Gmail e Outlook non mostrano.
 *
 * 💡 Per questo qui si **rende** ogni mail e si legge l'HTML: un test che
 *    controlla solo l'oggetto avrebbe detto verde a tutte e due.
 */
final class LeMailSiVestonoUgualeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, Mailable> */
    private function tutte(): array
    {
        $persona = User::make(['name' => 'Rita Bianchi', 'email' => 'rita@esempio.test']);
        $trainer = User::make(['name' => 'Marco Rossi', 'email' => 'marco@esempio.test']);
        $palestra = Tenant::make(['name' => 'Palestra Olimpo']);

        $richiesta = RichiestaDiContatto::make([
            'tipo' => 'palestra',
            'nome' => 'Rita Bianchi',
            'email' => 'rita@esempio.test',
            'messaggio' => "Prima riga\nSeconda riga",
        ]);

        $invito = TrainerInvite::make([
            'token' => str_repeat('B', 32),
            'email' => 'rita@esempio.test',
            'expires_at' => now()->addDays(7),
        ]);

        return [
            'benvenuto' => new BenvenutoMail($persona, BenvenutoMail::PERSONA),
            'benvenuto-palestra' => new BenvenutoMail($persona, BenvenutoMail::PALESTRA),
            'password-cambiata' => new PasswordCambiataMail($persona),
            'invito-del-trainer' => new InvitoDelTrainerMail($invito, $trainer),
            'richiesta-ricevuta' => new RichiestaRicevutaMail($richiesta),
            'richiesta-dal-sito' => new RichiestaDalSitoMail($richiesta),
            'pagamento-fallito' => new PagamentoFallitoMail($palestra, "l'abbonamento"),
        ];
    }

    /** ⚖️ Il vestito è uno solo: marchio in cima, piè di pagina con l'informativa. */
    #[Test]
    public function ogni_mail_ha_il_marchio_e_il_pie_di_pagina(): void
    {
        foreach ($this->tutte() as $quale => $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('Training Companion', $html, $quale);
            $this->assertStringContainsString('Informativa sulla privacy', $html, $quale);
            $this->assertStringContainsString('#0F766E', $html, "$quale: manca il colore del sito");
            $this->assertStringContainsString(
                'font-family',
                $html,
                "$quale: nessun carattere in linea — la mail esce con le grazie",
            );
        }
    }

    /**
     * 🚨 **Il marchio deve essere un indirizzo assoluto, e un PNG.**
     *
     * ⛔ Un `/img/...` in una mail non esiste, e un SVG non si vede su Gmail:
     * in tutti e due i casi resta un riquadro vuoto in cima — l'aspetto tipico
     * di una mail falsa.
     */
    #[Test]
    public function il_marchio_e_assoluto_e_non_e_un_svg(): void
    {
        $html = (new PasswordCambiataMail(User::make(['name' => 'Rita', 'email' => 'r@esempio.test'])))->render();

        $this->assertMatchesRegularExpression('~<img[^>]+src="https?://[^"]+\.png~', $html);
        $this->assertStringNotContainsString('.svg', $html);
    }

    /**
     * ⛔ Niente modelli stampati per sbaglio: il primo segno è una graffa con
     * dentro le colonne del database.
     */
    #[Test]
    public function nessuna_mail_stampa_un_modello_intero(): void
    {
        foreach ($this->tutte() as $quale => $mail) {
            $html = $mail->render();

            foreach (['"is_active"', '"tenant_id"', '"remember_token"', '"password"'] as $spia) {
                $this->assertStringNotContainsString($spia, $html, "$quale: c'è un modello stampato in JSON");
            }
        }
    }

    /** 💡 I nomi propri ci sono davvero: è la prova che le variabili arrivano. */
    #[Test]
    public function i_nomi_arrivano_nelle_mail_che_li_hanno(): void
    {
        $tutte = $this->tutte();

        $this->assertStringContainsString('Rita Bianchi', $tutte['benvenuto']->render());
        $this->assertStringContainsString('Marco Rossi', $tutte['invito-del-trainer']->render());
        $this->assertStringContainsString('Palestra Olimpo', $tutte['pagamento-fallito']->render());
    }

    /** ⚖️ Il testo scritto da qualcun altro non diventa HTML. */
    #[Test]
    public function il_messaggio_di_chi_scrive_resta_testo(): void
    {
        $richiesta = RichiestaDiContatto::make([
            'tipo' => 'altro',
            'nome' => 'Tizio',
            'email' => 'tizio@esempio.test',
            'messaggio' => '<script>alert(1)</script>',
        ]);

        $html = (new RichiestaDalSitoMail($richiesta))->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** 🚨 Ogni mail ha un oggetto, e nessuno è quello di Laravel. */
    #[Test]
    public function ogni_mail_ha_un_oggetto_suo(): void
    {
        foreach ($this->tutte() as $quale => $mail) {
            $oggetto = $mail->envelope()->subject;

            $this->assertNotNull($oggetto, $quale);
            $this->assertNotSame('', trim((string) $oggetto), $quale);
            $this->assertStringNotContainsStringIgnoringCase('laravel', (string) $oggetto, $quale);
        }
    }
}
