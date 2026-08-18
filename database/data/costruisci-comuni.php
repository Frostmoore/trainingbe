<?php

/**
 * Costruisce database/data/comuni.json unendo tre fonti.
 *
 * Non fa parte dell'applicazione: si lancia a mano quando l'ISTAT pubblica un
 * elenco nuovo. Il risultato si committa, cosi' l'import non dipende dalla rete.
 */

declare(strict_types=1);

$dove = __DIR__;
$uscita = 'e:/coding/XAMPP/htdocs/TrainingCompanionAI/trainingbe/database/data/comuni.jsonl';

// ── 1. L'elenco ufficiale ISTAT (la spina dorsale) ──────────────────────────

$comuni = [];
$r = fopen("$dove/istat.csv", 'r');
fgetcsv($r, 0, ';', '"', '');

while (($x = fgetcsv($r, 0, ';', '"', '')) !== false) {
    if (count($x) < 15) {
        continue;
    }

    $u = fn (string $s): string => trim(mb_convert_encoding($s, 'UTF-8', 'Windows-1252'));

    $codice = $u($x[4]);          // 001001
    $nome = $u($x[6]);            // Denominazione in italiano
    $altro = $u($x[7]);           // Denominazione altra lingua
    $regione = $u($x[10]);
    $provincia = $u($x[11]);      // Unita' territoriale sovracomunale
    $sigla = $u($x[14]);

    if ($codice === '' || $nome === '' || $sigla === '') {
        continue;
    }

    $comuni[$codice] = [
        'c' => $codice,
        'n' => $nome,
        'a' => $altro !== '' && $altro !== $nome ? $altro : null,
        'p' => $sigla,
        'pn' => $provincia,
        'r' => $regione,
        'cap' => null,
        'pop' => null,
        'lat' => null,
        'lng' => null,
    ];
}
fclose($r);

fwrite(STDERR, 'ISTAT: '.count($comuni)." comuni\n");

// ── 2. CAP e popolazione (comuni-json, derivato ISTAT) ──────────────────────
//
// 💡 La popolazione non serve a mostrarla: serve a **ordinare la ricerca**. Chi
// digita «mil» vuole Milano, non un paese di trecento abitanti che comincia
// uguale. E' l'unico criterio che rende utile un selettore di citta'.

$conCap = 0;
$conPop = 0;
foreach (json_decode(file_get_contents("$dove/comuni-json.json"), true) as $riga) {
    $codice = $riga['codice'] ?? null;
    if ($codice === null || ! isset($comuni[$codice])) {
        continue;
    }
    $cap = $riga['cap'][0] ?? null;
    if ($cap !== null) {
        $comuni[$codice]['cap'] = $cap;
        $conCap++;
    }
    $pop = $riga['popolazione'] ?? null;
    if (is_int($pop) && $pop > 0) {
        $comuni[$codice]['pop'] = $pop;
        $conPop++;
    }
}
fwrite(STDERR, "CAP trovati: $conCap — popolazione: $conPop\n");

// ── 3. Le coordinate (italy_geo, vintage 2018) ──────────────────────────────
//
// ⚠️ La chiave qui e' il codice ISTAT **numerico senza zeri davanti**: 1001 e
// non 001001. Va normalizzata o non aggancia niente.

$conCoord = 0;
foreach (json_decode(file_get_contents("$dove/italy_geo.json"), true) as $riga) {
    $codice = str_pad((string) ($riga['istat'] ?? ''), 6, '0', STR_PAD_LEFT);
    if (! isset($comuni[$codice])) {
        continue;
    }
    $lat = isset($riga['lat']) ? (float) $riga['lat'] : null;
    $lng = isset($riga['lng']) ? (float) $riga['lng'] : null;

    // Un controllo di sanita': l'Italia sta dentro questo rettangolo.
    if ($lat === null || $lng === null || $lat < 35.0 || $lat > 47.5 || $lng < 6.0 || $lng > 19.0) {
        continue;
    }

    $comuni[$codice]['lat'] = round($lat, 6);
    $comuni[$codice]['lng'] = round($lng, 6);
    $conCoord++;
}
fwrite(STDERR, "Coordinate trovate: $conCoord\n");

// ── 4. Rapporto e scrittura ─────────────────────────────────────────────────

$senzaCoord = count(array_filter($comuni, fn ($c) => $c['lat'] === null));
$senzaCap = count(array_filter($comuni, fn ($c) => $c['cap'] === null));
fwrite(STDERR, "Senza coordinate: $senzaCoord — senza CAP: $senzaCap\n");

$province = count(array_unique(array_column($comuni, 'p')));
$regioni = count(array_unique(array_column($comuni, 'r')));
fwrite(STDERR, "Province: $province — regioni: $regioni\n");

if (! is_dir(dirname($uscita))) {
    mkdir(dirname($uscita), 0755, true);
}

$valori = array_values($comuni);
usort($valori, fn ($a, $b) => $a['c'] <=> $b['c']);

// 💡 Una riga per comune (JSONL): pesa la meta' del JSON indentato e i diff
// restano leggibili — quando l'ISTAT aggiorna si vedono le righe cambiate, non
// un blocco unico riscritto.
$righe = array_map(fn ($c) => json_encode($c, JSON_UNESCAPED_UNICODE), $valori);
file_put_contents($uscita, implode("\n", $righe)."\n");
fwrite(STDERR, 'Scritto: '.$uscita.' ('.number_format(filesize($uscita) / 1024)." KB)\n");
