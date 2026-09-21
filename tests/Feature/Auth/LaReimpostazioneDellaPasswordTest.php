<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Mail\LinkPerLaPassword;
use App\Models\ReimpostazionePassword;
use App\Services\Auth\LaReimpostazioneDellaPassword;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔑 **«Ho dimenticato la password»** — 22/09/2026, fase F4.0.
 *
 * ══ ⛔ IL BUCO CHE CHIUDE ════════════════════════════════════════════════
 *
 * 📌 *«Eh sì va creata la pagina di reimpostazione password»*.
 *
 * 🚨 **Non esisteva nessuna strada.** Né dall'app, né dal sito, né dal
 * pannello: chi perdeva la password non poteva farci niente, e i quattro tasti
 * «manda il reset» chiesti dal committente non avrebbero avuto **nessuna
 * pagina** dove mandare la gente.
 *
 * ══ 🚨 E PERCHÉ NON SI USA IL BROKER DI LARAVEL ══════════════════════════
 *
 * Perché `password_reset_tokens` è indicizzata **per email**, e qui l'email non
 * identifica una persona: l'unicità è per palestra. ⛔ Con due account sullo
 * stesso indirizzo il broker ne pesca uno e cambia la password a quello —
 * una volta su due, alla persona sbagliata. Il primo test di questo file è
 * esattamente quel caso.
 */
final class LaReimpostazioneDellaPasswordTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🚨 **Due persone, lo stesso indirizzo, due token diversi.**
     *
     * ⛔ È il caso per cui questa tabella esiste invece di quella di Laravel:
     * il genitore che iscrive il figlio col proprio indirizzo.
     */
    #[Test]
    public function il_token_vale_per_una_persona_sola_anche_con_la_stessa_email(): void
    {
        $palestra = $this->creaPalestra();
        $altra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        $genitore = $this->creaUtente($palestra, UserRole::Member, 'famiglia@esempio.test');
        $figlio = $this->creaUtente($altra, UserRole::Member, 'famiglia@esempio.test');

        $servizio = app(LaReimpostazioneDellaPassword::class);

        $link = $servizio->apriPer($figlio);
        $token = $this->tokenDi($link);

        // 💡 Il token porta al figlio, non al genitore: l'email è la stessa.
        $this->assertSame($figlio->getKey(), $servizio->personaDi($token)?->getKey());
        $this->assertNotSame($genitore->getKey(), $servizio->personaDi($token)?->getKey());
    }

    /** 🔒 In tabella ci va l'impronta, mai il token. */
    #[Test]
    public function il_token_non_finisce_in_chiaro_nel_database(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $token = $this->tokenDi(app(LaReimpostazioneDellaPassword::class)->apriPer($persona));

        $riga = ReimpostazionePassword::query()->sole();

        $this->assertNotSame($token, $riga->impronta);
        $this->assertSame(hash('sha256', $token), $riga->impronta);
    }

    /** 🚨 Si usa una volta sola: la seconda non vale più. */
    #[Test]
    public function un_link_gia_usato_non_vale_piu(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $servizio = app(LaReimpostazioneDellaPassword::class);
        $token = $this->tokenDi($servizio->apriPer($persona));

        $this->assertTrue($servizio->completa($token, 'nuovapassword1'));
        $this->assertFalse($servizio->completa($token, 'ancoraunaltra1'));

        // 💡 E la password è quella della PRIMA volta, non della seconda.
        $this->assertTrue(Hash::check('nuovapassword1', $persona->refresh()->password));
    }

    /** ⏳ Dopo un'ora non vale più. */
    #[Test]
    public function un_link_scaduto_non_vale_piu(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $servizio = app(LaReimpostazioneDellaPassword::class);
        $token = $this->tokenDi($servizio->apriPer($persona));

        Carbon::setTestNow(now()->addMinutes(LaReimpostazioneDellaPassword::MINUTI + 1));

        $this->assertNull($servizio->personaDi($token));
        $this->assertFalse($servizio->completa($token, 'nuovapassword1'));

        Carbon::setTestNow();
    }

    /**
     * 🚨 **Chiederne un altro spegne il precedente.**
     *
     * ⛔ Tre link vivi insieme sono tre chiavi in giro per la stessa porta.
     */
    #[Test]
    public function il_link_nuovo_spegne_quello_vecchio(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $servizio = app(LaReimpostazioneDellaPassword::class);

        $vecchio = $this->tokenDi($servizio->apriPer($persona));
        $nuovo = $this->tokenDi($servizio->apriPer($persona));

        $this->assertNull($servizio->personaDi($vecchio));
        $this->assertSame($persona->getKey(), $servizio->personaDi($nuovo)?->getKey());
    }

    /**
     * 🚨 **Cambiare la password chiude le sessioni dell'app.**
     *
     * ⛔ Se la password si cambia perché qualcuno l'aveva, quel qualcuno
     * resterebbe dentro con il suo token.
     */
    #[Test]
    public function cambiare_la_password_cancella_i_token_dell_app(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $persona->createToken('telefono');
        $this->assertSame(1, $persona->tokens()->count());

        $servizio = app(LaReimpostazioneDellaPassword::class);
        $servizio->completa($this->tokenDi($servizio->apriPer($persona)), 'nuovapassword1');

        $this->assertSame(0, $persona->tokens()->count());
    }

    /** 📧 E la mail parte davvero (oggi nel log, ma parte). */
    #[Test]
    public function la_mail_col_link_viene_mandata(): void
    {
        Mail::fake();

        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        app(LaReimpostazioneDellaPassword::class)->apriPer($persona);

        Mail::assertSent(
            LinkPerLaPassword::class,
            fn (LinkPerLaPassword $mail): bool => $mail->hasTo('tizio@esempio.test'),
        );
    }

    // ───────────────────────── la pagina ─────────────────────────

    /** 🌐 La pagina accetta il link e cambia la password. */
    #[Test]
    public function dalla_pagina_si_sceglie_la_password_nuova(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');

        $token = $this->tokenDi(app(LaReimpostazioneDellaPassword::class)->apriPer($persona));

        $this->get('/reimposta-password/'.$token)->assertOk();

        $this->post('/reimposta-password/'.$token, [
            'password' => 'nuovapassword1',
            'password_confirmation' => 'nuovapassword1',
        ])->assertRedirect('/admin/login');

        $this->assertTrue(Hash::check('nuovapassword1', $persona->refresh()->password));
    }

    /**
     * 🚨 **La pagina non dice mai di chi è il link.**
     *
     * ⛔ Chi apre un link trovato in giro non deve scoprire a chi appartiene.
     */
    #[Test]
    public function la_pagina_non_mostra_ne_il_nome_ne_l_email(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'segretissimo@esempio.test', [
            'name' => 'Nome Che Non Deve Comparire',
        ]);

        $token = $this->tokenDi(app(LaReimpostazioneDellaPassword::class)->apriPer($persona));

        $this->get('/reimposta-password/'.$token)
            ->assertOk()
            ->assertDontSee('segretissimo@esempio.test')
            ->assertDontSee('Nome Che Non Deve Comparire');
    }

    /** ⛔ Un token storto non arriva nemmeno al controller: è un 404. */
    #[Test]
    public function un_token_storto_e_un_404(): void
    {
        $this->get('/reimposta-password/non-e-un-token')->assertNotFound();
    }

    // ───────────────────────── l'API dell'app ─────────────────────────

    /**
     * 🚨 **Sempre 200, anche per un indirizzo che non esiste.**
     *
     * ⛔ Rispondere «non esiste» sarebbe un modo di chiedere al server quali
     * email sono iscritte, una alla volta.
     */
    #[Test]
    public function l_api_risponde_uguale_a_chiunque(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::Member, 'esiste@esempio.test');

        $esiste = $this->postJson('/api/v1/auth/password-dimenticata', ['email' => 'esiste@esempio.test']);
        $inventata = $this->postJson('/api/v1/auth/password-dimenticata', ['email' => 'mai@esempio.test']);

        $esiste->assertOk();
        $inventata->assertOk();
        $this->assertSame($esiste->json('message'), $inventata->json('message'));

        // 💡 Ma il link è nato solo per chi esiste davvero.
        $this->assertSame(1, ReimpostazionePassword::query()->count());
    }

    /**
     * 🚨 **Con due account sullo stesso indirizzo non si tira a sorte.**
     *
     * ⛔ Mandare il link «a uno dei due» vuol dire, una volta su due, cambiare
     * la password alla persona sbagliata.
     */
    #[Test]
    public function l_api_non_indovina_quando_l_indirizzo_e_di_due_persone(): void
    {
        $palestra = $this->creaPalestra();
        $altra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        $this->creaUtente($palestra, UserRole::Member, 'famiglia@esempio.test');
        $this->creaUtente($altra, UserRole::Member, 'famiglia@esempio.test');

        $this->postJson('/api/v1/auth/password-dimenticata', ['email' => 'famiglia@esempio.test'])
            ->assertOk();

        $this->assertSame(0, ReimpostazionePassword::query()->count());
    }

    /** 💡 Chi ha chiesto il reset resta scritto: serve a capire chi ha premuto. */
    #[Test]
    public function si_sa_chi_ha_chiesto_il_reset(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'tizio@esempio.test');
        $god = $this->creaSuperAdmin();

        app(LaReimpostazioneDellaPassword::class)->apriPer($persona, $god);

        $this->assertSame(
            $god->getKey(),
            app(TenantContext::class)->runWithoutTenant(
                fn (): ?int => ReimpostazionePassword::query()->sole()->chiesto_da_id,
            ),
        );
    }

    /** ⛔ Un account spento non riceve link: non deve poter rientrare. */
    #[Test]
    public function un_account_disattivato_non_riceve_niente(): void
    {
        $palestra = $this->creaPalestra();
        $persona = $this->creaUtente($palestra, UserRole::Member, 'spento@esempio.test');
        $persona->forceFill(['is_active' => false])->save();

        $this->assertNull(app(LaReimpostazioneDellaPassword::class)->unicaCon('spento@esempio.test'));
    }

    private function tokenDi(string $link): string
    {
        return (string) str($link)->afterLast('/');
    }
}
