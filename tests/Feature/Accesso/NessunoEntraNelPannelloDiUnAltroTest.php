<?php

declare(strict_types=1);

namespace Tests\Feature\Accesso;

use App\Enums\UserRole;
use App\Http\Middleware\SpazioDelPannello;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔒 **Nessuno entra nel pannello di un altro** — 63.B, 28/09/2026.
 *
 * ══ 🚨 PERCHE' ESISTE, E PERCHE' ESISTE PROPRIO ADESSO ════════════════════
 *
 * 📌 Il committente, 27/09: *«NESSUNO deve NON POTER ACCEDERE al suo account /
 * NESSUNO deve POTER ACCEDERE all'account di qualcun altro»*.
 *
 * ⚠️ **Le due regole tirano in direzioni opposte, ed e' li' che nascono i
 * difetti.** 🚨 La fase F1 ha appena **allargato** una porta: `SpazioDelPannello`
 * non rifiuta piu' uno spazio per il suo stato. ⛔ Di tutto il controllo su
 * *«quale spazio posso avere davanti»* e' rimasta **una riga sola**:
 *
 *     ! $this->chiSiVede->spaziDi($utente)->contains($scelto)
 *
 * 🔑 **Tutta l'isolazione dei pannelli passa da li'.** Un file di test che la
 * guarda da sola non e' pignoleria: e' la controparte necessaria di F1.
 *
 * ── 💡 Perche' non basta `TenantIsolationTest` ──────────────────────────────
 *
 * Quello prova che le **query** non escano dal proprio spazio. ⛔ Questo prova
 * che non ci si **atterri**. Sono due cose diverse: si puo' atterrare nel
 * pannello di un altro e vederlo vuoto per merito dello scope — e sarebbe un
 * difetto comunque, perche' il nome della palestra, il logo e i colori sono
 * nell'intestazione **prima** di qualunque query.
 *
 * ── ⚠️ E si prova con richieste HTTP ───────────────────────────────────────
 *
 * 🚨 Su `canAccessPanel()` chiamato a mano i difetti di F1 erano **invisibili**.
 * ⛔ Qui si chiede di entrare, non si chiede al portiere se ti farebbe entrare.
 */
class NessunoEntraNelPannelloDiUnAltroTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🚨 **Il caso centrale**: il titolare di A mette in sessione l'id di B.
     *
     * ⛔ Non deve atterrare in B. ⚠️ E il test guarda **il nome nella pagina**,
     * non il codice di stato: un 200 va benissimo — deve solo essere il **suo**
     * pannello. 💡 Un test che si accontentasse di «non 500» passerebbe anche
     * mostrandogli la palestra di un altro.
     */
    #[Test]
    public function il_titolare_di_a_non_atterra_nella_palestra_b(): void
    {
        $a = $this->creaPalestra('Palestra Alfa', 'palestra-alfa');
        $b = $this->creaPalestra('Palestra Beta', 'palestra-beta');

        $capoDiA = $this->creaUtente($a, UserRole::GymAdmin, 'capo@palestra-alfa.test');
        $this->creaUtente($b, UserRole::GymAdmin, 'capo@palestra-beta.test');

        $risposta = $this->actingAs($capoDiA)
            ->withSession([SpazioDelPannello::CHIAVE => $b->getKey()])
            ->get('/admin');

        $risposta->assertDontSee('Palestra Beta', escape: false);

        $risposta->assertSee('Palestra Alfa', escape: false);
    }

    /**
     * ⚠️ **E il trainer di A nemmeno.**
     *
     * 💡 Non è lo stesso caso del titolare: un trainer può legittimamente
     * lavorare in più palestre, quindi per lui la domanda *«questo spazio è
     * fra i tuoi?»* ha una risposta meno ovvia. ⛔ Ed è proprio per questo che
     * va provata.
     */
    #[Test]
    public function il_trainer_di_a_non_atterra_nella_palestra_b(): void
    {
        $a = $this->creaPalestra('Palestra Gamma', 'palestra-gamma');
        $b = $this->creaPalestra('Palestra Delta', 'palestra-delta');

        $this->creaUtente($a, UserRole::GymAdmin, 'capo@palestra-gamma.test');
        $trainer = $this->creaUtente($a, UserRole::Trainer, 'trainer@palestra-gamma.test');
        $this->creaUtente($b, UserRole::GymAdmin, 'capo@palestra-delta.test');

        $risposta = $this->actingAs($trainer)
            ->withSession([SpazioDelPannello::CHIAVE => $b->getKey()])
            ->get('/admin');

        $risposta->assertDontSee('Palestra Delta', escape: false);
    }

    /**
     * 🔎 **Un id che non esiste non deve rompere niente.**
     *
     * ⛔ Un errore qui sarebbe **un modo per chiudersi fuori da soli**: basta un
     * cookie vecchio con l'id di una palestra cancellata e il pannello non si
     * apre piu'. 💡 La scelta si **dimentica** e si torna a casa propria, che e'
     * la cosa giusta e non ha bisogno di spiegazioni.
     */
    #[Test]
    public function un_id_inesistente_in_sessione_non_chiude_fuori_nessuno(): void
    {
        $a = $this->creaPalestra('Palestra Epsilon', 'palestra-epsilon');
        $capo = $this->creaUtente($a, UserRole::GymAdmin, 'capo@palestra-epsilon.test');

        $this->actingAs($capo)
            ->withSession([SpazioDelPannello::CHIAVE => 999_999_999])
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Palestra Epsilon', escape: false);
    }

    /**
     * 🗑️ **E nemmeno l'id di una palestra cancellata a soft-delete.**
     *
     * ⚠️ Non è lo stesso del caso sopra: una riga cancellata **c'è ancora** nel
     * database, e `Tenant::withoutGlobalScopes()->find()` la trova. ⛔ Se il
     * controllo su `spaziDi()` non la escludesse, si atterrerebbe in uno spazio
     * che per il resto del sistema non esiste — lo stato più difficile da
     * diagnosticare di tutti.
     */
    #[Test]
    public function un_id_di_palestra_cancellata_non_apre_niente(): void
    {
        $a = $this->creaPalestra('Palestra Zeta', 'palestra-zeta');
        $morta = $this->creaPalestra('Palestra Morta', 'palestra-morta');

        $capo = $this->creaUtente($a, UserRole::GymAdmin, 'capo@palestra-zeta.test');
        $this->creaUtente($morta, UserRole::GymAdmin, 'capo@palestra-morta.test');

        Tenant::withoutGlobalScopes()->whereKey($morta->getKey())->delete();

        $risposta = $this->actingAs($capo)
            ->withSession([SpazioDelPannello::CHIAVE => $morta->getKey()])
            ->get('/admin');

        $risposta->assertSuccessful();

        $risposta->assertDontSee('Palestra Morta', escape: false);
    }

    /**
     * 🔒 **`/god` e' solo del committente — provato su una RICHIESTA.**
     *
     * 🚨 `ChiEntraNeiPannelliTest` lo prova su `canAccessPanel()`, e quel test
     * era **vacuo** il 27/09: rimettendo il difetto restava verde, perche'
     * girava fuori dal contesto della palestra dove `hasRole()` risponde sempre
     * `false`. ⛔ Una richiesta HTTP non si puo' sbagliare in quel modo.
     */
    #[Test]
    public function nessun_ruolo_di_palestra_apre_il_pannello_god(): void
    {
        $palestra = $this->creaPalestra('Palestra Eta', 'palestra-eta');

        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@palestra-eta.test');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'trainer@palestra-eta.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@palestra-eta.test');

        foreach (['capo' => $capo, 'trainer' => $trainer, 'iscritto' => $iscritto] as $chi => $utente) {
            $this->flushSession();

            $risposta = $this->actingAs($utente->fresh())->get('/god');

            self::assertNotSame(
                200,
                $risposta->getStatusCode(),
                "🚨 Un {$chi} ha aperto il pannello di piattaforma.",
            );
        }
    }

    /**
     * ✅ **Il controllo: il super admin `/god` lo apre.**
     *
     * ⚠️ Senza questo, i test qui sopra passerebbero anche con `/god`
     * **rotto per tutti** — e direbbero «nessuno entra dove non deve» mentre la
     * verità è «nessuno entra».
     */
    #[Test]
    public function e_il_super_admin_lo_apre(): void
    {
        $god = $this->creaSuperAdmin('god@matrice.test');

        $this->actingAs($god)->get('/god')->assertSuccessful();
    }

    /**
     * 🔒 **Un iscritto non apre nessun pannello.** Usa solo l'app.
     *
     * ⚠️ Provato su richiesta anche questo: è la stessa distinzione di sopra.
     */
    #[Test]
    public function un_iscritto_non_apre_nessun_pannello(): void
    {
        $palestra = $this->creaPalestra('Palestra Theta', 'palestra-theta');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'solo-app@palestra-theta.test');

        $risposta = $this->actingAs($iscritto)->get('/admin');

        self::assertNotSame(
            200,
            $risposta->getStatusCode(),
            '🚨 Un iscritto ha aperto il pannello della palestra.',
        );
    }
}
