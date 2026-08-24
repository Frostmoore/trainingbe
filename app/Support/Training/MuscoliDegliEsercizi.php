<?php

declare(strict_types=1);

namespace App\Support\Training;

use App\Enums\MuscleGroup;

/**
 * I muscoli di ogni esercizio — 3b-B.2, 24/08/2026.
 *
 * ══ 🚨 I VALORI VENGONO DA UNA FONTE ESTERNA, NON DALLA MEMORIA ═══════════
 *
 * 📌 Il committente: *«Controlla se esiste una repository di esercizi da
 * palestra online con i gruppi muscolari gia' inseriti. Se esiste importala,
 * almeno non dobbiamo farcela a mano»*.
 *
 * 💡 **Esiste, ed e' `free-exercise-db`** (yuhonas/free-exercise-db, **pubblico
 * dominio**): 873 esercizi con `primaryMuscles` e `secondaryMuscles`. Le righe
 * marcate con il nome inglese in coda vengono da li', una per una — il
 * commento dice **quale voce**, cosi' chi dubita puo' andare a guardare.
 *
 * ⚠️ **Il ponte italiano→inglese e' l'unica cosa scritta a mano**, e non si
 * poteva evitare: nessun algoritmo sa che «Lat machine» e' «Wide-Grip Lat
 * Pulldown». Ma i muscoli non li decide piu' nessuno di noi.
 *
 * ── ⚠️ Diciassette gruppi contro tredici ──────────────────────────────────
 *
 * 🚨 La loro tassonomia e' piu' fine della nostra: `lats`, `middle back`,
 * `lower back` e `traps` diventano tutti **`back`**; `adductors` va in `quads`,
 * `abductors` in `glutes`. ⛔ E `neck` si butta: non e' una zona che
 * l'applicazione allena, e un asse «collo» sulla stella non lo saprebbe leggere
 * nessuno.
 *
 * 💡 Un **secondo primario** diventa un secondario invece di sparire: la nostra
 * colonna ne tiene uno solo, e buttarlo perderebbe un dato vero.
 *
 * ── ⛔ Le voci con il primario a `null` ───────────────────────────────────
 *
 * Sono quelle che il dataset non ha, e per cui il primario resta **quello gia'
 * scritto in tabella**: quelle righe le avevamo compilate a mano il 23/08 e
 * sono giuste. ⚠️ `null` qui vuol dire «non lo cambio», non «non lo so».
 */
final class MuscoliDegliEsercizi
{
    /**
     * `nome => [primario|null, secondari]`.
     *
     * 💡 **Scritti a mano dove il dataset non arriva**: un «rematore» e un
     * «rematore presa inversa» reclutano i bicipiti in modo diverso, e una
     * regola automatica sul nome avrebbe dato a tutti gli stessi muscoli — cioe'
     * un dato che sembra ricco ed e' finto.
     *
     * @var array<string, array{0: string|null, 1: list<string>}>
     */
    private const MAPPA = [
        // ══ Dal dataset: yuhonas/free-exercise-db (pubblico dominio) ══════
        'Piegamenti' => ['chest', ['shoulders', 'triceps']], // Pushups
        'Panca inclinata con manubri' => ['chest', ['shoulders', 'triceps']], // Incline Dumbbell Press
        'Panca inclinata' => ['chest', ['shoulders', 'triceps']], // Barbell Incline Bench Press - Medium Grip
        'Panca piana con manubri' => ['chest', ['shoulders', 'triceps']], // Dumbbell Bench Press
        'Panca piana' => ['chest', ['shoulders', 'triceps']], // Barbell Bench Press - Medium Grip
        'Croci su panca' => ['chest', []], // Dumbbell Flyes
        'Croci ai cavi' => ['chest', ['shoulders']], // Cable Crossover
        'Curl con bilanciere' => ['biceps', ['forearms']], // Barbell Curl
        'Curl concentrato' => ['biceps', ['forearms']], // Concentration Curls
        'Curl inverso' => ['biceps', ['forearms']], // Reverse Barbell Curl
        'Curl a martello' => ['biceps', ['forearms']], // Alternate Hammer Curl
        'Calf a una gamba' => ['calves', []], // Standing Dumbbell Calf Raise
        'Calf alla pressa' => ['calves', []], // Calf Press On The Leg Press Machine
        'Iperestensioni' => ['back', ['glutes', 'hamstrings']], // Hyperextensions (Back Extensions)
        'Crunch' => ['abs', []], // Crunches
        'Crunch inverso' => ['abs', []], // Reverse Crunch
        'Rematore Manubrio' => ['back', ['biceps', 'shoulders']], // Bent Over Two-Dumbbell Row
        'Bicipiti Martello (Manubrio)' => ['biceps', ['forearms']], // Alternate Hammer Curl
        'Arnold Press (Manubrio)' => ['shoulders', ['triceps']], // Arnold Dumbbell Press
        'Skullcrusher (Manubrio)' => ['triceps', ['forearms']], // EZ-Bar Skullcrusher
        'Calf Raise Gamba Singola in Piedi' => ['calves', []], // Standing Dumbbell Calf Raise
        'Calf Press (Macchina)' => ['calves', []], // Calf Press On The Leg Press Machine
        'Estensione Dorso (Iperestensione con Peso)' => ['back', ['glutes', 'hamstrings']], // Weighted Ball Hyperextension
        'Panca Inclinata (Manubrio)' => ['chest', ['shoulders', 'triceps']], // Incline Dumbbell Press
        'Panca Inclinata (Bilanciere)' => ['chest', ['shoulders', 'triceps']], // Barbell Incline Bench Press - Medium Grip
        'Panca Piana (Manubrio)' => ['chest', ['shoulders', 'triceps']], // Dumbbell Bench Press
        'Panca Piana (Bilanciere)' => ['chest', ['shoulders', 'triceps']], // Barbell Bench Press - Medium Grip
        'Croci (Manubrio)' => ['chest', []], // Dumbbell Flyes
        'Curl Bicipiti (Bilanciere)' => ['biceps', ['forearms']], // Barbell Curl
        'Concentration Curl' => ['biceps', ['forearms']], // Concentration Curls
        'Curl Invertito (Manubrio)' => ['biceps', ['forearms']], // Reverse Barbell Curl
        'Crunch Inverso' => ['abs', []], // Reverse Crunch
        'Rematore Inclinato (Bilanciere)' => ['back', ['biceps', 'shoulders']], // Bent Over Barbell Row
        'Rematore Inclinato (Manubrio)' => ['back', ['biceps', 'shoulders']], // Bent Over Two-Dumbbell Row
        'Rematore corda' => ['back', ['biceps', 'shoulders']], // Seated Cable Rows
        'Croci Inverse (Manubrio)' => ['shoulders', []], // Reverse Flyes
        'Push Press' => ['shoulders', ['quads', 'triceps']], // Push Press
        'Lento in Avanti (Manubrio)' => ['shoulders', ['triceps']], // Seated Dumbbell Press
        'Alzate Laterali (Cavo)' => ['shoulders', ['back']], // Cable Seated Lateral Raise
        'Alzata Frontale (Manubrio)' => ['shoulders', []], // Front Dumbbell Raise
        'Estensione Tricipiti Braccio Singolo (Manubrio)' => ['triceps', []], // Dumbbell One-Arm Triceps Extension
        'Squat (Corpo Libero)' => ['quads', ['glutes', 'hamstrings']], // Bodyweight Squat
        'Curl Bicipiti (Manubrio)' => ['biceps', ['forearms']], // Dumbbell Bicep Curl
        'Squat' => ['quads', ['calves', 'glutes', 'hamstrings', 'back']], // Barbell Squat
        'Squat frontale' => ['quads', ['calves', 'glutes', 'hamstrings']], // Front Barbell Squat
        'Stacco da terra' => ['back', ['calves', 'forearms', 'glutes', 'hamstrings', 'quads']], // Barbell Deadlift
        'Stacco rumeno' => ['hamstrings', ['calves', 'glutes', 'back']], // Romanian Deadlift
        'Stacco a gambe tese' => ['hamstrings', ['glutes', 'back']], // Stiff-Legged Barbell Deadlift
        'Affondi' => ['quads', ['calves', 'glutes', 'hamstrings']], // Barbell Lunge
        'Affondi camminati' => ['quads', ['calves', 'glutes', 'hamstrings']], // Barbell Walking Lunge
        'Squat bulgaro' => ['quads', ['calves', 'glutes', 'hamstrings']], // One Leg Barbell Squat
        'Pressa' => ['quads', ['calves', 'glutes', 'hamstrings']], // Leg Press
        'Leg extension' => ['quads', []], // Leg Extensions
        'Leg curl' => ['hamstrings', []], // Lying Leg Curls
        'Leg curl in piedi' => ['hamstrings', []], // Standing Leg Curl
        'Leg curl sdraiato' => ['hamstrings', []], // Lying Leg Curls
        'Hip thrust' => ['glutes', ['calves', 'hamstrings']], // Barbell Hip Thrust
        'Ponte glutei' => ['glutes', ['hamstrings']], // Butt Lift (Bridge)
        'Trazioni' => ['back', ['biceps']], // Pullups
        'Chin up' => ['back', ['biceps', 'forearms']], // Chin-Up
        'Lat machine' => ['back', ['biceps', 'shoulders']], // Wide-Grip Lat Pulldown
        'Lat machine presa inversa' => ['back', ['biceps', 'shoulders']], // Underhand Cable Pulldowns
        'Pulley basso' => ['back', ['biceps', 'shoulders']], // Seated Cable Rows
        'Rematore con bilanciere' => ['back', ['biceps', 'shoulders']], // Bent Over Barbell Row
        'Rematore con manubrio' => ['back', ['biceps', 'shoulders']], // One-Arm Dumbbell Row
        'Rematore T-bar' => ['back', ['biceps']], // T-Bar Row with Handle
        'Pullover' => ['chest', ['back', 'shoulders', 'triceps']], // Straight-Arm Dumbbell Pullover
        'Good morning' => ['hamstrings', ['abs', 'glutes', 'back']], // Good Morning
        'Scrollate' => ['back', []], // Barbell Shrug
        'Lento avanti' => ['shoulders', ['triceps']], // Standing Military Press
        'Military press' => ['shoulders', ['triceps']], // Standing Military Press
        'Shoulder press' => ['shoulders', ['triceps']], // Dumbbell Shoulder Press
        'Alzate laterali' => ['shoulders', []], // Side Lateral Raise
        'Alzate frontali' => ['shoulders', []], // Front Dumbbell Raise
        'Alzate posteriori' => ['shoulders', []], // Reverse Flyes
        'Face pull' => ['shoulders', ['back']], // Face Pull
        'Tirate al mento' => ['shoulders', ['back']], // Upright Barbell Row
        'Arnold press' => ['shoulders', ['triceps']], // Arnold Dumbbell Press
        'Dips' => ['chest', ['shoulders', 'triceps']], // Dips - Chest Version
        'Dips alle parallele' => ['triceps', ['chest', 'shoulders']], // Dips - Triceps Version
        'Panca stretta' => ['triceps', ['chest', 'shoulders']], // Close-Grip Barbell Bench Press
        'Panca declinata' => ['chest', ['shoulders', 'triceps']], // Decline Barbell Bench Press
        'Panca inclinata con manubri ' => ['chest', ['shoulders', 'triceps']], // Incline Dumbbell Press
        'French press' => ['triceps', []], // Lying Triceps Press
        'Push down tricipiti' => ['triceps', []], // Triceps Pushdown
        'Push down con corda' => ['triceps', []], // Triceps Pushdown - Rope Attachment
        'Estensioni sopra la testa' => ['triceps', []], // Standing Dumbbell Triceps Extension
        'Kickback tricipiti' => ['triceps', []], // Tricep Dumbbell Kickback
        'Curl bicipiti' => ['biceps', ['forearms']], // Dumbbell Bicep Curl
        'Curl ai cavi' => ['biceps', []], // Standing Biceps Cable Curl
        'Curl alla panca Scott' => ['biceps', []], // Preacher Curl
        'Curl ai polsi' => ['forearms', []], // Palms-Up Barbell Wrist Curl Over A Bench
        'Curl ai polsi inverso' => ['forearms', []], // Palms-Down Wrist Curl Over A Bench
        'Plank' => ['abs', []], // Plank
        'Russian twist' => ['abs', ['back']], // Russian Twist
        'Sollevamento gambe alla sbarra' => ['abs', []], // Hanging Leg Raise
        'Ruota per addominali' => ['abs', ['shoulders']], // Ab Roller
        'Mountain climber' => ['quads', ['chest', 'hamstrings', 'shoulders']], // Mountain Climbers
        'Kettlebell swing' => ['hamstrings', ['calves', 'glutes', 'back', 'shoulders']], // One-Arm Kettlebell Swings
        'Thruster' => ['shoulders', ['quads', 'triceps']], // Kettlebell Thruster
        'Girata' => ['hamstrings', ['calves', 'forearms', 'glutes', 'back', 'quads', 'shoulders', 'triceps']], // Power Clean
        'Strappo' => ['hamstrings', ['calves', 'glutes', 'back', 'quads', 'shoulders', 'triceps']], // Power Snatch
        'Calf in piedi' => ['calves', []], // Standing Calf Raises
        'Calf da seduto' => ['calves', []], // Seated Calf Raise
        'Nordic curl' => ['hamstrings', ['calves', 'glutes', 'back']], // Natural Glute Ham Raise
        'Glute ham raise' => ['hamstrings', ['calves', 'glutes', 'back']], // Natural Glute Ham Raise
        'Hack squat' => ['quads', ['calves', 'glutes', 'hamstrings']], // Hack Squat
        'Step up' => ['quads', ['calves', 'glutes', 'hamstrings']], // Dumbbell Step Ups
        'Goblet squat' => ['quads', ['calves', 'glutes', 'hamstrings', 'shoulders']], // Goblet Squat
        'Sissy squat' => ['quads', ['calves', 'glutes', 'hamstrings']], // Weighted Sissy Squat
        'Abduzioni' => ['glutes', []], // Thigh Abductor
        'Camminata del contadino' => ['forearms', ['abs', 'glutes', 'hamstrings', 'back', 'quads']], // Farmer's Walk
        'Battle rope' => ['shoulders', ['chest', 'forearms']], // Battling Ropes
        'Sospensione alla sbarra' => ['hamstrings', ['abs', 'glutes', 'back']], // Hanging Bar Good Morning

        // ══ Quelle che il dataset non ha: primario invariato ══════════════
        'Crunch ai cavi' => [null, []],
        'Hollow hold' => [null, ['quads']],
        'Plank laterale' => [null, ['shoulders', 'glutes']],
        'Sollevamento gambe' => [null, ['quads']],
        'Lat machine presa stretta' => [null, ['biceps']],
        'Pulldown a braccia tese' => [null, ['triceps']],
        'Rematore a un braccio ai cavi' => [null, ['biceps', 'forearms']],
        'Rematore presa inversa' => [null, ['biceps']],
        'Trazioni assistite' => [null, ['biceps', 'forearms']],
        'Salti sul posto' => [null, ['quads', 'glutes']],
        'Air bike' => [null, ['quads', 'back', 'shoulders']],
        'Camminata in salita' => [null, ['quads', 'glutes', 'calves']],
        'Corda' => [null, ['calves', 'shoulders']],
        'Corsa' => [null, ['quads', 'hamstrings', 'calves', 'glutes']],
        'Cyclette' => [null, ['quads', 'glutes', 'calves']],
        'Ellittica' => [null, ['quads', 'glutes', 'hamstrings']],
        'Scala' => [null, ['quads', 'glutes', 'calves']],
        'Sci indoor' => [null, ['quads', 'glutes', 'back']],
        'Tapis roulant' => [null, ['quads', 'hamstrings', 'calves', 'glutes']],
        'Vogatore' => [null, ['back', 'biceps', 'quads', 'shoulders']],
        'Chest press' => [null, ['triceps', 'shoulders']],
        'Croci ai cavi alti' => [null, ['shoulders']],
        'Pectoral machine' => [null, ['shoulders']],
        'Piegamenti su rialzo' => [null, ['triceps', 'shoulders']],
        'Rullo per avambracci' => [null, []],
        'Burpees' => [null, ['chest', 'quads', 'shoulders', 'abs']],
        'Slam ball' => [null, ['abs', 'shoulders', 'back']],
        'Abduzioni in piedi ai cavi' => [null, []],
        'Affondi laterali' => [null, ['quads', 'hamstrings']],
        'Hip thrust a una gamba' => [null, ['hamstrings']],
        'Kickback ai cavi' => [null, ['hamstrings']],
        'Pressa orizzontale' => [null, ['glutes']],
        'Alzate laterali ai cavi' => [null, []],
        'Shoulder press a macchina' => [null, ['triceps']],
        'Estensioni ai cavi sopra la testa' => [null, []],
    ];

    private function __construct() {}

    /**
     * I muscoli di un esercizio del catalogo, o `null` se non lo conosciamo.
     *
     * @return array{primario: MuscleGroup|null, secondari: list<string>}|null
     */
    public static function di(string $nome): ?array
    {
        $riga = self::MAPPA[$nome] ?? null;

        if ($riga === null) {
            return null;
        }

        return [
            'primario' => $riga[0] === null ? null : MuscleGroup::from($riga[0]),
            'secondari' => $riga[1],
        ];
    }

    /**
     * I soli secondari, per chi scrive una riga nuova del catalogo.
     *
     * ⚠️ Torna un elenco **vuoto** anche per un nome che non conosce, e non
     * `null`: chi chiama sta creando una riga, e un catalogo con dei buchi e'
     * precisamente la cosa che questa classe esiste per togliere.
     *
     * @return list<string>
     */
    public static function secondariDi(string $nome): array
    {
        return self::MAPPA[$nome][1] ?? [];
    }

    /** @return array<string, array{0: string|null, 1: list<string>}> */
    public static function tutti(): array
    {
        return self::MAPPA;
    }
}
