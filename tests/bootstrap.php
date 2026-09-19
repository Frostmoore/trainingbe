<?php

declare(strict_types=1);
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/*
 * ⚡ **Il database di test si crea dallo schema, non dalle migrazioni** — 19/09/2026.
 *
 * 📌 Il committente: *«non è che possiamo fare un'ora di test ogni volta che si
 * fa una modifica»*.
 *
 * 💡 `RefreshDatabase` ricrea il DB di test a ogni avvio. Con 90 migrazioni su
 * MySQL erano ~3,5 minuti **prima del primo test**, anche per un file solo.
 * Con `database/schema/mysql-schema.sql` Laravel carica lo schema in un colpo
 * e poi esegue solo le migrazioni che nel file non ci sono: ~35 secondi.
 *
 * ⚠️ Per caricare lo schema Laravel lancia il client `mysql`, che su questa
 * macchina NON è nel PATH di Windows — e non ce lo mettiamo, per la regola
 * «mai toolchain globale». Lo si aggiunge qui, **solo al processo dei test**.
 * La cartella sta in `MYSQL_CLIENT_DIR` (`phpunit.xml`): su un'altra macchina
 * si cambia solo quella riga, come in `bin/php.cmd`.
 */

require __DIR__.'/../vendor/autoload.php';

$cartella = getenv('MYSQL_CLIENT_DIR') ?: '';

if ($cartella !== '' && is_dir($cartella)) {
    $percorso = $cartella.PATH_SEPARATOR.(getenv('PATH') ?: '');

    putenv('PATH='.$percorso);

    /*
     * 🚨 Symfony Process costruisce l'ambiente del figlio anche da `$_SERVER` e
     * `$_ENV`, e su Windows la chiave è `Path`, non `PATH`: vanno aggiornate
     * tutte, o il client resta introvabile.
     */
    foreach ([&$_SERVER, &$_ENV] as &$ambiente) {
        foreach (array_keys($ambiente) as $chiave) {
            if (strcasecmp((string) $chiave, 'PATH') === 0) {
                $ambiente[$chiave] = $percorso;
            }
        }
    }
    unset($ambiente);
}

/*
 * ⚡ **Il DB di test non si ricrea se non è cambiato niente** — 19/09/2026.
 *
 * Anche dallo schema, ricreare il DB costa ~16 s a ogni lancio: per un file
 * di test da 2 secondi è quasi tutto il tempo. Ma i test girano dentro una
 * transazione annullata alla fine (`RefreshDatabase`), quindi dopo un lancio
 * il DB è di nuovo pulito e la ricreazione successiva è lavoro buttato.
 *
 * Si salta **solo se tutto torna**:
 *   1. l'impronta (migrazioni + schema) è uguale a quella salvata dall'ultimo
 *      lancio andato a buon fine (`Tests\TestCase::setUp()` la scrive);
 *   2. la tabella `migrations` ha tante righe quante sono le migrazioni;
 *   3. il DB è davvero pulito: nessun utente e nessun tenant.
 * Basta che una non torni e si ricrea come sempre.
 *
 * 💡 Per forzare la ricreazione: cancellare `storage/framework/testing/impronta-db-test`.
 */
$migrazioni = glob(__DIR__.'/../database/migrations/*.php') ?: [];
sort($migrazioni);
$impronta = hash_init('sha256');
foreach ([...$migrazioni, __DIR__.'/../database/schema/mysql-schema.sql'] as $file) {
    if (is_file($file)) {
        hash_update($impronta, basename($file)."\0".hash_file('sha256', $file));
    }
}

define('IMPRONTA_DEL_DB_DI_TEST', hash_final($impronta));
define('FILE_IMPRONTA_DEL_DB_DI_TEST', __DIR__.'/../storage/framework/testing/impronta-db-test');

if (is_file(FILE_IMPRONTA_DEL_DB_DI_TEST)
    && trim((string) file_get_contents(FILE_IMPRONTA_DEL_DB_DI_TEST)) === IMPRONTA_DEL_DB_DI_TEST) {
    try {
        /*
         * ⚠️ Solo ambiente e configurazione, poi PDO nudo: avviare l'app intera
         * qui registra i gestori di errore di Laravel prima dei test, e PHPUnit
         * segna **ogni** test come «risky» per gestori non rimossi.
         */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->bootstrapWith([
            LoadEnvironmentVariables::class,
            LoadConfiguration::class,
        ]);
        $c = $app['config']['database.connections.mysql'];
        $app->flush();

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']),
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $conta = static fn (string $t): int => (int) $pdo->query("select count(*) from `{$t}`")->fetchColumn();

        $pulito = $conta('migrations') === count($migrazioni)
            && $conta('users') === 0
            && $conta('tenants') === 0;

        $pdo = null;

        if ($pulito) {
            RefreshDatabaseState::$migrated = true;
        }
    } catch (Throwable) {
        // Qualunque dubbio → si ricrea come sempre.
    }
}
