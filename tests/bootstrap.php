<?php

declare(strict_types=1);

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
