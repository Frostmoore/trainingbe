<?php

declare(strict_types=1);

namespace App\Support\Training;

/**
 * Quale disegno va su quale esercizio — 3b-L, 28/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«Scarica i svg e mettili su tutti gli esercizi»*.
 *
 * ══ 🚨 IL PONTE ITALIANO→INGLESE E' SCRITTO A MANO, E DEVE ESSERLO ════════
 *
 * ⛔ Nessuna regola automatica sa che «Lat machine» e' `lat-pulldown`, che
 * «Pulley basso» e' `seated-row` o che «French press» e' `skull-crusher`.
 * ⚠️ Un confronto sui nomi darebbe **zero** corrispondenze su tre quarti del
 * catalogo, e — peggio — qualche corrispondenza sbagliata su nomi che si
 * somigliano: «Panca stretta» e «Panca piana» distano una parola.
 *
 * 🚨 **Una figura sbagliata e' peggio di nessuna figura**, perche' nessuno la
 * legge come un errore: chi vede il disegno di una panca piana sopra
 * «Panca stretta» pensa di aver capito male lui.
 *
 * ── ⚠️ Sei nomi puntano su un disegno che non e' esattamente il loro ──────
 *
 * `Croci ai cavi alti`, `Military press`, `Pressa orizzontale`,
 * `Stacco a gambe tese`, `Hip thrust a una gamba` e `Salti sul posto` usano il
 * disegno del movimento **piu' vicino**. 💡 Approvati uno per uno dal
 * committente: a 64 pixel la differenza fra uno stacco rumeno e uno a gambe
 * tese non si vede, e il disegno serve a far riconoscere l'esercizio in un
 * elenco, non a insegnarne la tecnica.
 *
 * ── ⛔ Sette dei nostri restano senza ─────────────────────────────────────
 *
 * | Esercizio | Perche' |
 * |---|---|
     * | `Girata` | non c'e' in nessuna delle due raccolte |
     * | `Glute ham raise` | non c'e' in nessuna delle due raccolte |
     * | `Leg curl in piedi` | il rasterizzatore sbaglia il disegno di Everkinetic |
     * | `Pullover` | il rasterizzatore sbaglia il disegno di Everkinetic |
     * | `Rullo per avambracci` | non c'e' in nessuna delle due raccolte |
     * | `Slam ball` | non c'e' in nessuna delle due raccolte |
     * | `Thruster` | non c'e' in nessuna delle due raccolte |
 *
 * 💡 Per loro `Miniatura` disegna il segnaposto, che e' il comportamento che
 * aveva gia' per tutti.
 *
 * ── 💡 Perche' una classe e non una colonna ───────────────────────────────
 *
 * Il legame nome→disegno e' **nostro**, non della palestra: vale per gli
 * esercizi della piattaforma e non cambia mai a runtime. ⚠️ In colonna sarebbe
 * un dato da migrare a ogni aggiunta, e nessuno saprebbe piu' perche' quella
 * riga punta li'. Qui sta accanto alla sua motivazione, come
 * `MuscoliDegliEsercizi`.
 *
 * ⛔ **Il credito non sta qui**: sta in `database/data/illustrazioni.jsonl`,
 * accanto ai file. Chi cambia il disegno cambia anche chi l'ha fatto, e le due
 * cose devono muoversi insieme.
 */
final class IllustrazioniDegliEsercizi
{
    /**
     * `nome dell'esercizio => slug del file` (senza `.png`).
     *
     * @var array<string, string>
     */
    private const MAPPA = [
        'Abduzioni' => 'hip-abduction-machine',
        'Abduzioni da sdraiato' => 'side-lying-hip-abduction',
        'Abduzioni da seduto con elastico' => 'banded-seated-hip-abduction',
        'Abduzioni in piedi ai cavi' => 'cable-standing-hip-abduction',
        'Abduzioni in piedi con elastico' => 'banded-standing-hip-abduction',
        'Adduttori a macchina' => 'hip-adduction-machine',
        'Adduzioni ai cavi' => 'cable-standing-hip-adduction',
        'Affondi' => 'forward-lunge',
        'Affondi camminati' => 'walking-lunge',
        'Affondi laterali' => 'dumbbell-lateral-lunge',
        'Affondo incrociato' => 'curtsy-lunge',
        'Affondo incrociato con manubri' => 'dumbbell-curtsy-lunge',
        'Affondo indietro' => 'reverse-lunge',
        'Affondo indietro al multipower' => 'smith-machine-reverse-lunge',
        'Affondo indietro in deficit' => 'deficit-reverse-lunge',
        'Affondo laterale a corpo libero' => 'lateral-lunge',
        'Air bike' => 'assault-bike',
        'Allungamento a farfalla' => 'butterfly-stretch',
        'Allungamento femorali' => 'hamstring-stretch',
        'Allungamento flessori dell\'anca' => 'kneeling-hip-flexor-stretch',
        'Allungamento in avanti da seduto' => 'seated-forward-fold-stretch',
        'Allungamento pettorali alla porta' => 'doorway-chest-stretch',
        'Allungamento polpacci al muro' => 'wall-calf-stretch',
        'Allungamento quadricipiti in piedi' => 'standing-quad-stretch',
        'Allungamento spalle incrociato' => 'cross-body-shoulder-stretch',
        'Alzate a T da prono' => 'prone-t-raise',
        'Alzate a Y da prono' => 'prone-y-raise',
        'Alzate frontali' => 'front-raise',
        'Alzate frontali ai cavi' => 'cable-front-raise',
        'Alzate frontali con disco' => 'plate-front-raise',
        'Alzate laterali' => 'lateral-raise',
        'Alzate laterali a macchina' => 'machine-lateral-raise',
        'Alzate laterali ai cavi' => 'cable-lateral-raise',
        'Alzate posteriori' => 'rear-delt-fly',
        'Alzate posteriori ai cavi' => 'cable-rear-delt-fly',
        'Angelo inverso' => 'reverse-snow-angel',
        'Aperture con elastico' => 'band-pull-apart',
        'Arnold press' => 'arnold-press',
        'Battle rope' => 'battle-ropes',
        'Belt squat' => 'belt-squat',
        'Bird dog' => 'bird-dog',
        'Bruco' => 'inchworm',
        'Burpees' => 'burpee',
        'Calf a corpo libero' => 'calf-raise',
        'Calf a una gamba' => 'single-leg-calf-raise',
        'Calf alla pressa' => 'leg-press-calf-raise',
        'Calf da seduto' => 'seated-calf-raise',
        'Calf in piedi' => 'standing-calf-raise',
        'Camminata' => 'walking',
        'Camminata del contadino' => 'farmer-carry',
        'Camminata del granchio' => 'crab-walk',
        'Camminata dell\'orso' => 'bear-crawl',
        'Camminata in salita' => 'treadmill-incline-walk',
        'Camminata laterale con elastico' => 'banded-lateral-walk',
        'Chest press' => 'machine-chest-press',
        'Chin up' => 'chin-up',
        'Chin up assistite' => 'assisted-chin-up',
        'Chin up zavorrate' => 'weighted-chin-up',
        'Circonduzioni delle braccia' => 'arm-circles',
        'Clamshell' => 'clamshell',
        'Clamshell con elastico' => 'banded-clamshell',
        'Corda' => 'jump-rope',
        'Corsa' => 'running',
        'Corsa sul posto a ginocchia alte' => 'high-knees',
        'Cossack squat' => 'cossack-squat',
        'Croci ai cavi' => 'cable-fly',
        'Croci ai cavi alti' => 'incline-cable-fly',
        'Croci su panca' => 'dumbbell-fly',
        'Crunch' => 'crunch',
        'Crunch ai cavi' => 'cable-crunch',
        'Crunch bicicletta' => 'bicycle-crunch',
        'Crunch con disco' => 'weighted-crunch',
        'Crunch inverso' => 'reverse-crunch',
        'Curl a martello' => 'hammer-curl',
        'Curl a martello con corda' => 'rope-hammer-curl',
        'Curl ai cavi' => 'cable-curl',
        'Curl ai polsi' => 'wrist-curl',
        'Curl ai polsi inverso' => 'wrist-extension',
        'Curl alla panca Scott' => 'preacher-curl',
        'Curl bicipiti' => 'bicep-curl',
        'Curl con bilanciere' => 'ez-bar-curl',
        'Curl concentrato' => 'concentration-curl',
        'Curl inverso' => 'reverse-curl',
        'Curl su panca inclinata' => 'incline-dumbbell-curl',
        'Cyclette' => 'cycling',
        'Dead bug' => 'dead-bug',
        'Dead bug con elastico' => 'banded-dead-bug',
        'Dips' => 'chest-dip',
        'Dips alle parallele' => 'dip',
        'Dips assistite' => 'assisted-dip',
        'Dips su panca' => 'bench-dip',
        'Dips zavorrate' => 'weighted-dip',
        'Donkey calf' => 'donkey-calf-raise',
        'Drag curl' => 'drag-curl',
        'Dragon flag' => 'dragon-flag',
        'Ellittica' => 'elliptical',
        'Escursionismo' => 'hiking',
        'Estensioni ai cavi sopra la testa' => 'overhead-tricep-extension',
        'Estensioni sopra la testa' => 'dumbbell-overhead-tricep-extension',
        'Estensioni tricipiti a un braccio' => 'single-arm-dumbbell-tricep-extension',
        'Face pull' => 'face-pull',
        'Face pull con elastico' => 'banded-face-pull',
        'Fire hydrant' => 'fire-hydrant',
        'Fire hydrant con elastico' => 'banded-fire-hydrant',
        'Flessioni laterali con manubrio' => 'dumbbell-side-bend',
        'Forbici' => 'flutter-kick',
        'French press' => 'skull-crusher',
        'French press con manubri' => 'dumbbell-skull-crusher',
        'French press con un manubrio' => 'single-dumbbell-skullcrusher',
        'Frog pump' => 'frog-pump',
        'Frog pump con elastico' => 'banded-frog-pump',
        'Gatto-cammello' => 'cat-cow-stretch',
        'Goblet squat' => 'goblet-squat',
        'Goblet squat con talloni rialzati' => 'heel-elevated-goblet-squat',
        'Good morning' => 'good-morning',
        'Hack squat' => 'hack-squat',
        'Hip airplane' => 'hip-airplane',
        'Hip thrust' => 'hip-thrust',
        'Hip thrust a una gamba' => 'single-leg-glute-bridge',
        'Hip thrust al multipower' => 'smith-machine-hip-thrust',
        'Hip thrust con elastico' => 'banded-hip-thrust',
        'Hip thrust con manubrio' => 'dumbbell-hip-thrust',
        'Hollow hold' => 'hollow-body-hold',
        'Hollow rock' => 'hollow-rock',
        'Iperestensioni' => 'back-extension',
        'Iperestensioni inverse' => 'reverse-hyperextension',
        'Iperestensioni per i glutei' => 'glute-focused-back-extension',
        'Jumping jack' => 'jumping-jack',
        'Kettlebell swing' => 'kettlebell-swing',
        'Kickback a macchina' => 'machine-glute-kickback',
        'Kickback ai cavi' => 'cable-kickback',
        'Kickback con elastico' => 'banded-kickback',
        'Kickback tricipiti' => 'tricep-kickback',
        'Knee tuck da seduto' => 'seated-knee-tuck',
        'L-sit' => 'l-sit-hold',
        'Landmine press' => 'landmine-press',
        'Lat machine' => 'lat-pulldown',
        'Lat machine con elastico' => 'banded-lat-pulldown',
        'Lat machine presa inversa' => 'underhand-lat-pulldown',
        'Lat machine presa larga' => 'wide-grip-lat-pulldown',
        'Lat machine presa stretta' => 'close-grip-lat-pulldown',
        'Leg curl' => 'leg-curl',
        'Leg curl con asciugamano' => 'towel-hamstring-curl',
        'Leg curl con fitball' => 'stability-ball-hamstring-curl',
        'Leg curl da seduto' => 'seated-leg-curl',
        'Leg curl sdraiato' => 'lying-leg-curl',
        'Leg extension' => 'leg-extension',
        'Lento avanti' => 'overhead-press',
        'Meadows row' => 'meadows-row',
        'Mezzo burpee' => 'half-burpee',
        'Military press' => 'overhead-press',
        'Monster walk con elastico' => 'banded-monster-walk',
        'Mountain climber' => 'mountain-climber',
        'Nordic curl' => 'nordic-hamstring-curl',
        'Nuoto' => 'swimming',
        'Pallof hold ai cavi' => 'cable-pallof-hold',
        'Pallof press' => 'pallof-press',
        'Pallof press con elastico' => 'banded-pallof-press',
        'Pallof press in ginocchio' => 'half-kneeling-pallof-press',
        'Panca declinata' => 'decline-bench-press',
        'Panca declinata con manubri' => 'decline-dumbbell-press',
        'Panca inclinata' => 'incline-bench-press',
        'Panca inclinata con manubri' => 'incline-dumbbell-press',
        'Panca piana' => 'bench-press',
        'Panca piana al multipower' => 'smith-machine-bench-press',
        'Panca piana con manubri' => 'dumbbell-bench-press',
        'Panca stretta' => 'close-grip-bench-press',
        'Pectoral machine' => 'pec-deck',
        'Pectoral machine inversa' => 'reverse-pec-deck',
        'Pendlay row' => 'pendlay-row',
        'Piegamenti' => 'push-up',
        'Piegamenti a diamante' => 'diamond-push-up',
        'Piegamenti a macchina da scrivere' => 'typewriter-push-up',
        'Piegamenti a presa larga' => 'wide-push-up',
        'Piegamenti al muro' => 'wall-push-up',
        'Piegamenti arciere' => 'archer-push-up',
        'Piegamenti con piedi rialzati' => 'decline-push-up',
        'Piegamenti con tocco delle spalle' => 'push-up-shoulder-tap',
        'Piegamenti esplosivi' => 'explosive-push-up',
        'Piegamenti hindu' => 'hindu-push-up',
        'Piegamenti in verticale' => 'handstand-push-up',
        'Piegamenti in verticale al muro' => 'wall-handstand-push-up',
        'Piegamenti scapolari' => 'scapular-push-up',
        'Piegamenti su rialzo' => 'incline-push-up',
        'Piegamenti sulle ginocchia' => 'knee-push-up',
        'Piegamenti zavorrati' => 'weighted-push-up',
        'Pike push up' => 'pike-push-up',
        'Pike push up con piedi rialzati' => 'feet-elevated-pike-push-up',
        'Pistol squat' => 'pistol-squat',
        'Pistol squat assistito' => 'assisted-pistol-squat',
        'Plank' => 'plank',
        'Plank con tocco delle spalle' => 'plank-shoulder-tap',
        'Plank dell\'orso' => 'bear-plank',
        'Plank di Copenaghen' => 'copenhagen-plank',
        'Plank jack' => 'plank-jack',
        'Plank laterale' => 'side-plank',
        'Plank laterale con discesa del bacino' => 'side-plank-hip-dip',
        'Ponte glutei' => 'glute-bridge',
        'Ponte glutei con bilanciere' => 'barbell-glute-bridge',
        'Ponte glutei con elastico' => 'banded-glute-bridge',
        'Ponte glutei con manubrio' => 'dumbbell-glute-bridge',
        'Ponte glutei con marcia' => 'glute-bridge-march',
        'Posizione del bambino' => 'childs-pose',
        'Pressa' => 'leg-press',
        'Pressa orizzontale' => 'leg-press',
        'Pull through ai cavi' => 'cable-pull-through',
        'Pulldown a braccia tese' => 'straight-arm-pulldown',
        'Pulley basso' => 'seated-row',
        'Push down con corda' => 'rope-tricep-pushdown',
        'Push down tricipiti' => 'tricep-pushdown',
        'Push press' => 'push-press',
        'Rack pull' => 'rack-pull',
        'Rematore T-bar' => 't-bar-row',
        'Rematore a macchina' => 'machine-row',
        'Rematore a un braccio ai cavi' => 'single-arm-cable-row',
        'Rematore alla porta' => 'doorway-row',
        'Rematore con appoggio al petto' => 'chest-supported-row',
        'Rematore con asciugamano' => 'towel-row',
        'Rematore con bilanciere' => 'barbell-row',
        'Rematore con due manubri' => 'dumbbell-bent-over-row',
        'Rematore con elastico' => 'banded-row',
        'Rematore con manubrio' => 'one-arm-dumbbell-row',
        'Rematore presa inversa' => 'reverse-grip-barbell-row',
        'Ruota per addominali' => 'ab-wheel',
        'Russian twist' => 'russian-twist',
        'Russian twist con peso' => 'weighted-russian-twist',
        'Salti del pattinatore' => 'skater-hop',
        'Salti sul posto' => 'fast-feet',
        'Scala' => 'stair-climber',
        'Sci indoor' => 'skierg',
        'Scrollate' => 'shrug',
        'Scrollate con manubri' => 'dumbbell-shrug',
        'Seal jack' => 'seal-jack',
        'Sedia al muro' => 'wall-sit',
        'Shoulder press' => 'seated-dumbbell-press',
        'Shoulder press a macchina' => 'machine-shoulder-press',
        'Shoulder press in piedi' => 'standing-dumbbell-press',
        'Shrimp squat' => 'shrimp-squat',
        'Sissy squat' => 'sissy-squat',
        'Sit up su panca declinata' => 'decline-sit-up',
        'Skater squat' => 'skater-squat',
        'Slanci delle gambe' => 'leg-swings-stretch',
        'Slanci indietro' => 'donkey-kick',
        'Slanci indietro con elastico' => 'banded-donkey-kick',
        'Slanci laterali da sdraiato' => 'side-lying-leg-raise',
        'Sollevamento gambe' => 'lying-leg-raise',
        'Sollevamento gambe alla sbarra' => 'hanging-leg-raise',
        'Sollevamento ginocchia alla sbarra' => 'hanging-knee-raise',
        'Sollevamento ginocchia alle parallele' => 'captains-chair-knee-raise',
        'Sospensione alla sbarra' => 'dead-hang',
        'Sospensione attiva' => 'active-hang',
        'Spaccalegna ai cavi' => 'cable-woodchop',
        'Spaccalegna con elastico' => 'banded-woodchop',
        'Spider curl' => 'spider-curl',
        'Split squat' => 'split-squat',
        'Split squat al multipower' => 'smith-machine-split-squat',
        'Split squat con avampiede rialzato' => 'front-foot-elevated-split-squat',
        'Spostamenti laterali' => 'lateral-shuffle',
        'Sprawl' => 'sprawl',
        'Squat' => 'squat',
        'Squat a corpo libero' => 'bodyweight-squat',
        'Squat a una gamba al box' => 'single-leg-box-squat',
        'Squat al landmine' => 'landmine-squat',
        'Squat al multipower' => 'smith-machine-squat',
        'Squat bulgaro' => 'bulgarian-split-squat',
        'Squat bulgaro al multipower' => 'smith-machine-bulgarian-split-squat',
        'Squat con elastico' => 'banded-squat',
        'Squat con salto' => 'jump-squat',
        'Squat frontale' => 'front-squat',
        'Squat sumo con manubrio' => 'dumbbell-sumo-squat',
        'Squat thrust' => 'squat-thrust',
        'Stacco a gambe tese' => 'romanian-deadlift',
        'Stacco con trap bar' => 'trap-bar-deadlift',
        'Stacco da terra' => 'deadlift',
        'Stacco rumeno' => 'romanian-deadlift',
        'Stacco rumeno a una gamba' => 'single-leg-romanian-deadlift',
        'Stacco rumeno al landmine' => 'landmine-romanian-deadlift',
        'Stacco rumeno al multipower' => 'smith-machine-romanian-deadlift',
        'Stacco rumeno con kettlebell' => 'kettlebell-romanian-deadlift',
        'Stacco rumeno con manubri' => 'dumbbell-romanian-deadlift',
        'Stacco sumo' => 'sumo-deadlift',
        'Stacco sumo con manubrio' => 'dumbbell-sumo-deadlift',
        'Step down' => 'step-down',
        'Step up' => 'step-up',
        'Strappo' => 'one-arm-barbell-snatch',
        'Superman' => 'superman',
        'Superman isometrico' => 'superman-hold',
        'Tapis roulant' => 'running',
        'Tirate al mento' => 'upright-row',
        'Toccare le punte' => 'toe-touch',
        'Tocco dei talloni' => 'heel-tap',
        'Torsioni del busto' => 'torso-twist-stretch',
        'Trazioni' => 'pull-up',
        'Trazioni a L' => 'l-sit-pull-up',
        'Trazioni assistite' => 'assisted-pull-up',
        'Trazioni commando' => 'commando-pull-up',
        'Trazioni con asciugamano' => 'towel-pull-up',
        'Trazioni negative' => 'negative-pull-up',
        'Trazioni orizzontali' => 'inverted-row',
        'Trazioni presa neutra' => 'neutral-grip-pull-up',
        'Trazioni scapolari' => 'scapular-pull-up',
        'Trazioni zavorrate' => 'weighted-pull-up',
        'V-up' => 'v-up',
        'Vogatore' => 'rowing',
        'Walkout per femorali' => 'lying-hamstring-walkout',
        'Wall walk' => 'wall-walk',
        'World\'s greatest stretch' => 'worlds-greatest-stretch',
    ];

    private function __construct() {}

    /** Il disegno di un esercizio, o `null` se non ne ha uno. */
    public static function slugDi(string $nome): ?string
    {
        return self::MAPPA[$nome] ?? null;
    }

    /** @return array<string, string> */
    public static function tutte(): array
    {
        return self::MAPPA;
    }
}
