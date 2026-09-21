<?php

/**
 * Costruisce `database/data/mondo.jsonl.gz` dal dump GeoNames.
 *
 * ══ 🌍 PERCHÉ ESISTE — 23/09/2026, fase F6.1 ═══════════════════════════════
 *
 * 📌 Il committente: *«Beh no, perché poi tra un po' faremo che l'app sarà
 * internazionale, quindi non ha senso importare un dataset italiano»*, e poi
 * *«Facciamo stessa tabella ma mondiale. E deve funzionare.»*
 *
 * 💡 **Stessa tabella**: `comuni` resta una tabella nostra, con una chiave
 * esterna vera da `users.comune_id`. ⛔ L'alternativa — un servizio esterno di
 * geocoding — avrebbe fatto saltare quella chiave, non avrebbe funzionato senza
 * rete (e la città si sceglie **anche dall'app**), e avrebbe mandato fuori
 * quello che la gente digita mentre lo digita.
 *
 * ══ 🚨 L'ITALIA NON STA QUI DENTRO, ED È DELIBERATO ═══════════════════════
 *
 * ⛔ GeoNames conosce Milano, ma sa **meno** dell'ISTAT: niente CAP, niente
 * sigla di provincia, e la copertura si ferma ai centri sopra i 500 abitanti.
 * ✅ Quindi l'Italia resta ISTAT (`comuni.jsonl`) e il resto del mondo è
 * GeoNames: i due non si sovrappongono mai. 🚨 Senza questa esclusione Milano
 * comparirebbe **due volte** nel selettore, con due id diversi.
 *
 * ══ 🗣️ I TRE NOMI DI UNA CITTÀ, E PERCHÉ SERVONO TUTTI ════════════════════
 *
 * Il nome «ufficiale» di GeoNames è quasi sempre quello **inglese**: `Munich`,
 * `Cologne`, `Vienna`. ⛔ Con solo quello, un italiano che scrive «Monaco» e un
 * tedesco che scrive «München» non trovano niente — cioè il selettore non
 * funziona per nessuno dei due, e il committente ha detto *«deve funzionare»*.
 *
 * | Chiave | Cosa | Esempio |
 * |---|---|---|
 * | `n` | il nome GeoNames | `Munich` |
 * | `it` | il nome **italiano**, se c'è ed è diverso | `Monaco di Baviera` |
 * | `l` | il nome **locale**, nella lingua del paese | `München` |
 *
 * 🚨 **Si prendono dal file con le lingue etichettate** (`alternateNamesV2`),
 * **non** dalla colonna `alternatenames` di `cities500`. ⛔ Quella è una lista
 * senza lingue e piena di sporcizia: fra gli alias di Mosca c'è *«Paradise
 * Valley»*. Cercarla avrebbe restituito Mosca.
 *
 * ⚠️ Si scartano i nomi **storici** e **colloquiali**: «Londinium» non è un modo
 * in cui qualcuno cerca Londra oggi.
 *
 * 💡 E lo stesso vale per la **regione**: `admin1CodesASCII` dà `Bavaria`, e
 * dal file etichettato si prende `Baviera`. Senza, il selettore scriverebbe
 * «Monaco di Baviera, Bavaria (Germania)» — tre lingue in una riga.
 *
 * ══ ⚠️ PERCHÉ COMPRESSO ═══════════════════════════════════════════════════
 *
 * 🚨 In chiaro sono ~20 MB: in un repo che si clona ogni volta è un peso che
 * non torna più indietro, e ogni ri-generazione ne aggiunge un altro alla
 * storia. Compresso sono ~6 MB, e `ImportaIlMondo` lo legge con `gzopen`.
 *
 * ══ 📥 LE FONTI ═══════════════════════════════════════════════════════════
 *
 * Tutte da <https://download.geonames.org/export/dump/>, licenza **CC BY 4.0**:
 *
 * | File | Cosa |
 * |---|---|
 * | `cities500.zip` | i centri sopra i 500 abitanti (~236.000) |
 * | `admin1CodesASCII.txt` | la divisione di primo livello (regione, stato) |
 * | `countryInfo.txt` | la lingua principale di ogni paese |
 * | `alternateNamesV2.zip` | i nomi **con la lingua**: 19 milioni di righe, ~800 MB scompattato |
 *
 * ⚠️ **I nomi dei paesi in italiano li genera `intl`** e finiscono **dentro il
 * file**: ⛔ farlo all'importazione vorrebbe dire che il server di produzione
 * deve avere `intl`, e un'estensione mancante si scopre durante un deploy.
 *
 * Uso (la cartella deve contenere i quattro file, scompattati):
 *
 *     php database/data/costruisci-mondo.php <cartella-geonames>
 *
 * ⏱️ Un paio di minuti: il file dei nomi è grande.
 */

declare(strict_types=1);

ini_set('memory_limit', '2G');

$sorgenti = rtrim($argv[1] ?? __DIR__, '/\\');
$uscita = __DIR__.'/mondo.jsonl.gz';

$file = [
    'cities' => "{$sorgenti}/cities500.txt",
    'admin1' => "{$sorgenti}/admin1CodesASCII.txt",
    'paesi' => "{$sorgenti}/countryInfo.txt",
    'nomi' => "{$sorgenti}/alternateNamesV2.txt",
];

foreach ($file as $f) {
    if (! is_readable($f)) {
        fwrite(STDERR, "Non trovo {$f}\n");
        exit(1);
    }
}

// ── 1. La lingua principale di ogni paese ───────────────────────────────────
//
// 💡 `DE → de`, `CH → de`, `BE → nl`. ⚠️ È la **prima** della lista, che per
// GeoNames è la più parlata: per un paese plurilingue si perde qualcosa (il
// francese del Belgio), ma resta il nome GeoNames e quello italiano.

$lingua = [];
$r = fopen($file['paesi'], 'r');

while (($riga = fgets($r)) !== false) {
    if ($riga === '' || $riga[0] === '#') {
        continue;
    }

    $c = explode("\t", rtrim($riga, "\r\n"));

    if (count($c) < 16 || $c[0] === '') {
        continue;
    }

    $prima = trim(explode(',', $c[15])[0] ?? '');
    $lingua[$c[0]] = strtolower(explode('-', $prima)[0]);
}

fclose($r);

// ── 2. Le regioni, e l'id GeoNames di ognuna ────────────────────────────────

$regioni = [];        // `DE.02` → [nome, geonameid]
$idRegioni = [];      // geonameid → `DE.02`
$r = fopen($file['admin1'], 'r');

while (($riga = fgets($r)) !== false) {
    $c = explode("\t", rtrim($riga, "\r\n"));

    if (count($c) < 4) {
        continue;
    }

    $regioni[$c[0]] = ['nome' => $c[1], 'id' => (int) $c[3]];
    $idRegioni[(int) $c[3]] = $c[0];
}

fclose($r);

// ── 3. Le città, in memoria ─────────────────────────────────────────────────
//
// ⚠️ Serve averle **prima** di leggere i nomi: si tengono solo i nomi delle
// città che finiranno nel file, e sono 224.000 su 13 milioni di luoghi.

$citta = [];
$r = fopen($file['cities'], 'r');
$saltate = 0;

while (($riga = fgets($r)) !== false) {
    $c = explode("\t", rtrim($riga, "\r\n"));

    if (count($c) < 15) {
        continue;
    }

    $paese = strtoupper(trim($c[8]));

    // 🚨 L'Italia si salta: ce l'abbiamo migliore dall'ISTAT.
    if ($paese === 'IT') {
        $saltate++;

        continue;
    }

    if ($c[0] === '' || trim($c[1]) === '' || $paese === '') {
        continue;
    }

    $citta[(int) $c[0]] = [
        'n' => trim($c[1]),
        'p' => $paese,
        'a1' => trim($c[10]),
        'a2' => trim($c[11]),
        'pop' => (int) $c[14],
        'lat' => $c[4],
        'lng' => $c[5],
    ];
}

fclose($r);

// ── 4. I nomi etichettati: italiano e lingua locale ─────────────────────────
//
// 📋 Le colonne di `alternateNamesV2.txt`:
//    0 id · 1 geonameid · 2 lingua · 3 nome · 4 preferito · 5 corto ·
//    6 colloquiale · 7 storico · 8 da · 9 a
//
// 💡 Il punteggio sceglie il nome migliore fra i tanti di una lingua: quello
// **preferito** vince, quello **corto** («Monaco» per Monaco di Baviera) perde —
// è ambiguo, e in un selettore l'ambiguità è il difetto.

$scelti = [];        // geonameid → lingua → [nome, punteggio]
$r = fopen($file['nomi'], 'r');
$letti = 0;

while (($riga = fgets($r)) !== false) {
    $letti++;
    $c = explode("\t", rtrim($riga, "\r\n"));

    if (count($c) < 8) {
        continue;
    }

    $id = (int) $c[1];
    $ling = $c[2];

    $eCitta = isset($citta[$id]);
    $eRegione = isset($idRegioni[$id]);

    if (! $eCitta && ! $eRegione) {
        continue;
    }

    // ⛔ Storici e colloquiali: nessuno cerca Londra scrivendo «Londinium».
    if ($c[6] === '1' || $c[7] === '1') {
        continue;
    }

    $paese = $eCitta ? $citta[$id]['p'] : explode('.', $idRegioni[$id])[0];
    $locale = $lingua[$paese] ?? null;

    if ($ling !== 'it' && $ling !== $locale) {
        continue;
    }

    $nome = trim($c[3]);

    if ($nome === '' || mb_strlen($nome) > 120) {
        continue;
    }

    $punti = ($c[4] === '1' ? 4 : 0) - ($c[5] === '1' ? 1 : 0);

    if (! isset($scelti[$id][$ling]) || $punti > $scelti[$id][$ling][1]) {
        $scelti[$id][$ling] = [$nome, $punti];
    }
}

fclose($r);

// ── 5. I nomi dei paesi, in italiano ────────────────────────────────────────

$paesi = [];

$nomePaese = static function (string $codice) use (&$paesi): string {
    if (! isset($paesi[$codice])) {
        /*
         * ⚠️ Il trattino davanti è la sintassi di `Locale` per «solo la
         * regione». ⛔ Senza, `getDisplayRegion('DE')` legge `DE` come una
         * **lingua** e risponde una stringa vuota.
         */
        $n = Locale::getDisplayRegion('-'.$codice, 'it');
        $paesi[$codice] = $n !== '' ? $n : $codice;
    }

    return $paesi[$codice];
};

// ── 6. Un nome italiano, una città sola ─────────────────────────────────────
//
// 🚨 **Misurato**: GeoNames dà «Londra» sia a *London* (9 milioni) sia alla
// *City of London* (8.000 abitanti, il quartiere storico). Nel selettore
// comparivano **due «Londra, Inghilterra (Regno Unito)» identiche**, e non c'era
// modo di capire quale fosse quella giusta.
//
// ✅ Quando due città dello **stesso paese** hanno lo stesso nome italiano, lo
// tiene **la più popolosa**; l'altra resta col suo nome GeoNames («City of
// London»), che è più preciso. ⚠️ Solo nello stesso paese: due «San José» in due
// paesi diversi sono due città vere, e la regione fra parentesi le distingue.

$migliore = [];      // "paese|nome italiano" → [geonameid, popolazione]

foreach ($citta as $id => $x) {
    $it = $scelti[$id]['it'][0] ?? null;

    if ($it === null) {
        continue;
    }

    $chiave = $x['p'].'|'.mb_strtolower($it);

    if (! isset($migliore[$chiave]) || $x['pop'] > $migliore[$chiave][1]) {
        $migliore[$chiave] = [$id, $x['pop']];
    }
}

// ── 7. Il file ──────────────────────────────────────────────────────────────

$fuori = gzopen($uscita, 'wb9');
$scritte = 0;
$conItaliano = 0;
$conLocale = 0;

foreach ($citta as $id => $x) {
    $paese = $x['p'];
    $chiaveRegione = "{$paese}.{$x['a1']}";
    $regione = $regioni[$chiaveRegione] ?? null;

    $it = $scelti[$id]['it'][0] ?? null;
    $loc = $scelti[$id][$lingua[$paese] ?? '-'][0] ?? null;

    // 🚨 Vedi il passo 6: il nome italiano lo tiene solo la città più grande.
    if ($it !== null && ($migliore[$paese.'|'.mb_strtolower($it)][0] ?? null) !== $id) {
        $it = null;
    }

    // 💡 Solo se diversi: un doppione non aggiunge niente alla ricerca.
    if ($it !== null && $it === $x['n']) {
        $it = null;
    }

    if ($loc !== null && ($loc === $x['n'] || $loc === $it)) {
        $loc = null;
    }

    $conItaliano += $it !== null ? 1 : 0;
    $conLocale += $loc !== null ? 1 : 0;

    /*
     * 🗺️ La regione in italiano, se c'è; altrimenti il nome ASCII inglese.
     * ⚠️ Mai il codice: «Monaco (02)» non dice niente a nessuno.
     */
    $nomeRegione = $regione === null
        ? null
        : ($scelti[$regione['id']]['it'][0] ?? $regione['nome']);

    $riga = [
        'g' => $id,
        'n' => $x['n'],
        'it' => $it,
        'l' => $loc,
        'p' => $paese,
        'pn' => $nomeRegione,
        'a1' => $x['a1'] !== '' ? $x['a1'] : null,
        'a2' => $x['a2'] !== '' ? $x['a2'] : null,
        'r' => $nomePaese($paese),
        'pop' => $x['pop'] > 0 ? $x['pop'] : null,
        'lat' => $x['lat'] !== '' ? round((float) $x['lat'], 6) : null,
        'lng' => $x['lng'] !== '' ? round((float) $x['lng'], 6) : null,
    ];

    gzwrite($fuori, json_encode($riga, JSON_UNESCAPED_UNICODE)."\n");
    $scritte++;
}

gzclose($fuori);

printf(
    "%s: %d città (%d italiane saltate, %d paesi) · col nome italiano: %d · col nome locale: %d · nomi letti: %d\n",
    basename($uscita),
    $scritte,
    $saltate,
    count($paesi),
    $conItaliano,
    $conLocale,
    $letti,
);
