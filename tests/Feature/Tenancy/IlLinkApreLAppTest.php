<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 🎯 `assetlinks.json` — quello che fa aprire il link **dall'app** — 3b-V.3.2.
 *
 * ══ 🚨 PERCHE' QUESTO FILE HA BISOGNO DI UN TEST ══════════════════════════
 *
 * ⛔ **Perche' quando smette di funzionare non succede niente di visibile.**
 *
 * Android verifica questo file per decidere se un link `https://…/invito-palestra/…`
 * puo' aprire l'app senza chiedere niente a nessuno. Se la verifica fallisce —
 * file mancante, JSON storto, impronta sbagliata, pacchetto sbagliato — il
 * telefono **apre il browser**. E il browser mostra la nostra pagina web, che
 * funziona: l'invito si legge, si capisce, c'e' scritto cosa fare.
 *
 * 🚨 **Quindi nessuno si lamenta, e nessuno se ne accorge.** Il prodotto e'
 * peggiore in modo silenzioso: ogni persona invitata fa due passaggi invece di
 * uno, e la meta' si ferma al primo.
 *
 * ⚠️ **E il file non e' PHP**: e' un JSON statico, senza una riga di codice che
 * lo tocchi. Non c'e' nessun altro punto in cui un errore verrebbe fuori.
 *
 * ══ ⛔ L'IMPRONTA E' QUELLA DELLA CHIAVE DI DEBUG ═════════════════════════
 *
 * 🚨 **E va detto, perche' e' un debito con una data di scadenza precisa.**
 * L'APK oggi e' firmato con la chiave di debug (`~/.android/debug.keystore`,
 * la stessa su ogni macchina che compila). Il giorno in cui si firma per
 * davvero — per la pubblicazione — l'impronta cambia, questo file smette di
 * corrispondere, e **il link torna ad aprire il browser in silenzio**.
 *
 * 💡 Questo test non puo' accorgersene da solo — non ha la chiave — ma tiene
 * fermo tutto il resto e **scrive dove guardare** quando succede.
 */
final class IlLinkApreLAppTest extends TestCase
{
    /** 💡 Un solo posto da cui leggerlo: se si sposta, tutti i test lo dicono. */
    private function assetlinks(): array
    {
        $percorso = public_path('.well-known/assetlinks.json');

        $this->assertFileExists(
            $percorso,
            'Manca `public/.well-known/assetlinks.json`: il link d\'invito aprira\' '
            .'il browser invece dell\'app, e nessuno se ne accorgera\'.',
        );

        $letto = json_decode((string) file_get_contents($percorso), true);

        $this->assertIsArray($letto, 'Il file non e\' JSON valido.');

        return $letto;
    }

    /**
     * 🚨 **Il pacchetto, e non un pacchetto qualunque.**
     *
     * ⚠️ `com.smp.mytrainingcompanion`, che e' l'`applicationId` vero. Ha gia'
     * fatto danni una volta: con `com.trainingcompanion.app` — che sembra
     * giusto — `monkey` apriva **un'altra app** sul telefono del committente.
     */
    #[Test]
    public function dichiara_il_pacchetto_giusto(): void
    {
        $dati = $this->assetlinks();

        $this->assertCount(1, $dati);
        $this->assertSame(
            'com.smp.mytrainingcompanion',
            $dati[0]['target']['package_name'] ?? null,
        );
        $this->assertSame('android_app', $dati[0]['target']['namespace'] ?? null);
    }

    /**
     * ⚠️ **La relazione esatta**, che e' l'unica che Android accetta per gli
     * App Links. Una parola diversa non da' errore: il file si scarica, la
     * verifica non passa, e il link apre il browser.
     */
    #[Test]
    public function dichiara_la_relazione_che_serve(): void
    {
        $this->assertSame(
            ['delegate_permission/common.handle_all_urls'],
            $this->assetlinks()[0]['relation'] ?? null,
        );
    }

    /**
     * 🔢 **L'impronta ha la forma di un'impronta.**
     *
     * ⛔ Questo test non puo' dire se e' **quella giusta** — non ha la chiave —
     * ma prende i due errori che si fanno davvero: incollarla in minuscolo o
     * senza i due punti (Android la vuole cosi'), e lasciarla vuota.
     */
    #[Test]
    public function l_impronta_ha_la_forma_giusta(): void
    {
        $impronte = $this->assetlinks()[0]['target']['sha256_cert_fingerprints'] ?? [];

        $this->assertNotEmpty($impronte, 'Nessuna impronta: la verifica non passera\' mai.');

        foreach ($impronte as $impronta) {
            $this->assertMatchesRegularExpression(
                '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/',
                $impronta,
                'L\'impronta va scritta in MAIUSCOLO e separata da due punti: '
                .'32 byte, 95 caratteri. Cosi\' com\'e\' Android non la riconosce.',
            );
        }
    }

    /**
     * 🚨 **E si scarica davvero.**
     *
     * ⚠️ Il test sul file non basta: quello che conta e' che il **web server**
     * lo serva. ⛔ Una cartella che comincia con un punto e' esattamente il
     * genere di cosa che una configurazione «di sicurezza» blocca per
     * abitudine, e il file resterebbe sul disco a non servire a niente.
     *
     * 💡 Qui si prova la rotta; sul server vero lo dice `deploy-staging.sh`.
     */
    #[Test]
    public function si_scarica_dal_web(): void
    {
        $risposta = $this->get('/.well-known/assetlinks.json');

        $risposta->assertStatus(200);

        /*
         * ⚠️ **`getContent()` torna `false`**: la risposta e' un file, non una
         * stringa costruita in memoria — ed e' giusto cosi', si mandano i byte
         * esatti senza rileggerli e riscriverli.
         */
        $this->assertIsArray(
            json_decode($risposta->baseResponse->getFile()->getContent(), true),
            'Quello che esce dal web non e\' il JSON che c\'e\' sul disco.',
        );

        // 💡 E con il tipo giusto: Android non guarda l'estensione.
        $risposta->assertHeader('Content-Type', 'application/json');
    }
}
