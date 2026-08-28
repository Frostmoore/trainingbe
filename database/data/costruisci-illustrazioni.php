<?php

/**
 * Costruisce database/data/illustrazioni/ e illustrazioni.jsonl.
 *
 * Non fa parte dell'applicazione: si lancia a mano quando si vuole rifare il
 * parco illustrazioni. Il risultato si committa, cosi' l'import non dipende
 * dalla rete — stessa regola dei comuni.
 *
 * Uso:
 *   git clone --depth 1 https://github.com/bryllim/workout-guide.git /tmp/wg
 *   php costruisci-illustrazioni.php /tmp/wg
 *
 * Serve `magick` (ImageMagick) nel PATH.
 *
 * ══ 🚨 PERCHE' PNG E NON GLI SVG ORIGINALI ════════════════════════════════
 *
 * ⛔ La collezione media di `Exercise` accetta jpeg/png/webp, e **non si
 * allarga a SVG**: su quella stessa collezione carica anche il gestore di una
 * palestra dal pannello, e un SVG servito dal nostro dominio e' uno script che
 * gira sul nostro dominio. 💡 Un rasterizzato non ha questo problema, e a 512px
 * non si distingue.
 *
 * ══ 🚨 PERCHE' IL FOTOGRAMMA 2 ════════════════════════════════════════════
 *
 * Ogni esercizio ne ha tre. Il 2 e' la **posizione di lavoro** — quella che
 * identifica il movimento — ed e' disegnata piu' grande degli altri due, che a
 * 64 pixel e' l'unica cosa che conta. ⚠️ Verificato guardando un campione, non
 * dedotto dal numero.
 *
 * ══ ⚠️ LA FIGURA E' BIANCA ════════════════════════════════════════════════
 *
 * Il tratto originale e' `fill="#fff"` su fondo trasparente: **su una card
 * chiara sarebbe invisibile**. Si tiene com'e' e si tinge nell'app, cosi' la
 * stessa immagine serve tema chiaro e tema scuro. 💡 E tingere a schermo non
 * crea un'opera derivata da ridistribuire: il file resta quello originale, che
 * e' anche il modo di stare tranquilli con la clausola ShareAlike.
 */

declare(strict_types=1);

$repo = $argv[1] ?? null;

if ($repo === null || ! is_dir("$repo/packages/workout-guide/assets")) {
    fwrite(STDERR, "uso: php costruisci-illustrazioni.php <clone di workout-guide>\n");
    exit(1);
}

$qui = __DIR__;
$uscita = "$qui/illustrazioni";

if (! is_dir($uscita)) {
    mkdir($uscita, 0o755, true);
}

const LATO = 512;

/** Il credito che finira' sotto l'immagine, nell'app. */
const CREDITO_WG = 'Bryl Lim / Everkinetic — CC BY-SA 4.0';
const CREDITO_EK = 'Everkinetic — CC BY-SA 4.0';

$manifesto = json_decode(
    (string) file_get_contents("$repo/packages/workout-guide/manifest.json"),
    true,
    flags: JSON_THROW_ON_ERROR
);

$righe = [];
$falliti = [];

// ── 1. workout-guide: 302 esercizi, fotogramma 2 ────────────────────────────

foreach ($manifesto as $e) {
    $slug = $e['slug'];
    $svg = "$repo/packages/workout-guide/assets/$slug/frame-2.svg";

    if (! is_file($svg)) {
        $falliti[] = "$slug (manca frame-2)";

        continue;
    }

    $png = "$uscita/$slug.png";

    /*
     * ⚠️ `-strip` toglie i metadati, `color-type=4` e' grigio+alfa: il colore
     * non serve, la forma sta tutta nel canale alfa. Un RGBA peserebbe un
     * terzo in piu' per non dire niente di piu'.
     */
    $cmd = sprintf(
        'magick -background none %s -resize %dx%d -colorspace gray -strip '.
        '-define png:color-type=4 -define png:compression-level=9 %s',
        escapeshellarg($svg),
        LATO,
        LATO,
        escapeshellarg($png)
    );

    exec($cmd, $_, $esito);

    if ($esito !== 0 || ! is_file($png)) {
        $falliti[] = $slug;

        continue;
    }

    $righe[] = [
        's' => $slug,
        'n' => $e['name'],
        'm' => $e['primaryMuscle'] ?? null,
        'q' => $e['equipment'] ?? null,
        'c' => CREDITO_WG,
        'u' => 'https://github.com/bryllim/workout-guide',
    ];
}

// ── 2. Everkinetic, per i tre che a workout-guide mancano ───────────────────

/*
 * 🚨 **Solo tre, e scelti a mano guardandoli.** Everkinetic disegna nero su
 * fondo bianco, cioe' l'esatto contrario: vanno rovesciati per stare accanto
 * agli altri. ⛔ Su due dei cinque candidati il rasterizzatore sbaglia il
 * disegno (una zeppa nera in mezzo alla figura) e quei due restano fuori: una
 * figura sbagliata e' peggio di un segnaposto, perche' nessuno la legge come
 * un errore.
 */
$extra = [
    '0026-tension' => ['reverse-grip-barbell-row', 'Reverse Grip Barbell Row', 'Back', 'Barbell'],
    '0095-tension' => ['underhand-lat-pulldown', 'Underhand Lat Pulldown', 'Lats', 'Machine'],
    '0148-tension' => ['one-arm-barbell-snatch', 'One-Arm Barbell Snatch', 'Legs', 'Barbell'],
];

foreach ($extra as $file => [$slug, $nome, $muscolo, $attrezzo]) {
    $svg = "$qui/everkinetic/$file.svg";

    if (! is_file($svg)) {
        $falliti[] = "$slug (manca $file.svg)";

        continue;
    }

    $png = "$uscita/$slug.png";

    /*
     * ⚠️ `-fuzz 30% -transparent white` toglie il fondo, `-negate` sui soli
     * canali RGB rovescia il tratto da scuro a chiaro senza toccare l'alfa.
     * 💡 `-trim` piu' `-extent` ricentra: gli originali non sono inquadrati
     * come quelli di workout-guide, e affiancati si vedrebbe.
     */
    $cmd = sprintf(
        'magick %s -background white -flatten -fuzz 30%% -transparent white '.
        '-channel RGB -negate +channel -trim +repage -resize %dx%d '.
        '-background none -gravity center -extent %dx%d '.
        '-colorspace gray -strip -define png:color-type=4 '.
        '-define png:compression-level=9 %s',
        escapeshellarg($svg),
        (int) (LATO * 0.94),
        (int) (LATO * 0.94),
        LATO,
        LATO,
        escapeshellarg($png)
    );

    exec($cmd, $_, $esito);

    if ($esito !== 0 || ! is_file($png)) {
        $falliti[] = $slug;

        continue;
    }

    $righe[] = [
        's' => $slug,
        'n' => $nome,
        'm' => $muscolo,
        'q' => $attrezzo,
        'c' => CREDITO_EK,
        'u' => 'https://github.com/everkinetic/data',
    ];
}

// ── 3. L'indice ─────────────────────────────────────────────────────────────

usort($righe, fn (array $a, array $b): int => strcmp($a['s'], $b['s']));

$jsonl = '';

foreach ($righe as $r) {
    $jsonl .= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}

file_put_contents("$qui/illustrazioni.jsonl", $jsonl);

printf("illustrazioni: %d\n", count($righe));
printf("peso: %.1f MB\n", array_sum(array_map(
    fn (array $r): int => (int) filesize("$uscita/{$r['s']}.png"),
    $righe
)) / 1024 / 1024);

if ($falliti !== []) {
    printf("\nFALLITI (%d):\n  %s\n", count($falliti), implode("\n  ", $falliti));
}
