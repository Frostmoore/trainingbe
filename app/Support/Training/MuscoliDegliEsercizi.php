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
        /*
         * 🔧 **Correzione del 28/08/2026.** ⛔ `free-exercise-db` non dava
         * secondari, ma nelle croci il deltoide anteriore accompagna tutto
         * l'arco.
         */
        'Croci su panca' => ['chest', ['shoulders']], // Dumbbell Flyes
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
        'Curl Invertito (Manubrio)' => ['biceps', ['forearms']], // Standing Dumbbell Reverse Curl
        'Crunch Inverso' => ['abs', []], // Reverse Crunch
        'Rematore Inclinato (Bilanciere)' => ['back', ['biceps', 'shoulders']], // Bent Over Barbell Row
        'Rematore Inclinato (Manubrio)' => ['back', ['biceps', 'shoulders']], // Bent Over Two-Dumbbell Row
        'Rematore corda' => ['back', ['biceps', 'shoulders']], // Seated Cable Rows
        'Croci Inverse (Manubrio)' => ['shoulders', []], // Reverse Flyes
        'Push Press' => ['shoulders', ['quads', 'triceps']], // Push Press
        'Lento in Avanti (Manubrio)' => ['shoulders', ['triceps']], // Seated Dumbbell Press
        'Alzate Laterali (Cavo)' => ['shoulders', []], // Side Lateral Raise
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
        /*
         * 🔧 **Correzione del 28/08/2026.** ⛔ Mancava la schiena: romboidi e
         * trapezio medio lavorano in ogni alzata posteriore, e senza di loro
         * la figura lasciava spenta meta' di quello che l'esercizio allena.
         */
        'Alzate posteriori' => ['shoulders', ['back']], // Reverse Flyes
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

        /*
         * ══ 🆕 DAL SECONDO DATASET: workout-guide — 3b-L, 28/08/2026 ══════
         *
         * ⚠️ **Fonte diversa dalle righe qui sopra**, e va detto: quelle
         * vengono da `free-exercise-db`, queste da `bryllim/workout-guide`
         * (metadati sotto licenza MIT). Il nome inglese in coda dice **quale
         * voce**, come per le altre.
         *
         * 💡 Il primario e' sempre `null` — «non lo cambio» — perche' per
         * questi esercizi il primario lo scrive gia' il seeder: qui servono
         * solo i secondari, che senza questa mappa sarebbero vuoti e
         * lascerebbero **grigia** la figura del corpo per meta' catalogo.
         *
         * ⛔ `Mobility` e `Cardio` non diventano secondari: come `full_body`,
         * dicono che tipo di movimento e', non che zona lavora.
         */

        // ── Petto ──
        'Allungamento pettorali alla porta' => [null, ['shoulders']], // Doorway Chest Stretch
        'Panca declinata con manubri' => [null, ['triceps', 'shoulders']], // Decline Dumbbell Press
        'Panca piana al multipower' => [null, ['triceps', 'shoulders']], // Smith Machine Bench Press
        'Piegamenti a macchina da scrivere' => [null, ['triceps', 'shoulders', 'abs']], // Typewriter Push-up
        'Piegamenti a presa larga' => [null, ['shoulders', 'triceps', 'abs']], // Wide Push-up
        'Piegamenti al muro' => [null, ['triceps', 'shoulders']], // Wall Push-up
        'Piegamenti arciere' => [null, ['triceps', 'shoulders', 'abs']], // Archer Push-up
        'Piegamenti con piedi rialzati' => [null, ['shoulders', 'triceps', 'abs']], // Decline Push-up
        'Piegamenti esplosivi' => [null, ['triceps', 'shoulders', 'abs']], // Explosive Push-up
        'Piegamenti hindu' => [null, ['shoulders', 'triceps', 'abs']], // Hindu Push-up
        'Piegamenti sulle ginocchia' => [null, ['triceps', 'shoulders', 'abs']], // Knee Push-up
        'Piegamenti zavorrati' => [null, ['triceps', 'abs']], // Weighted Push-up
        'Seal jack' => [null, ['shoulders', 'quads']], // Seal Jack

        // ── Schiena ──
        'Alzate a T da prono' => [null, ['shoulders']], // Prone T Raise
        'Alzate a Y da prono' => [null, ['shoulders']], // Prone Y Raise
        'Angelo inverso' => [null, ['shoulders']], // Reverse Snow Angel
        'Aperture con elastico' => [null, ['shoulders']], // Band Pull-Apart
        'Gatto-cammello' => [null, ['abs']], // Cat-Cow Stretch
        'Lat machine con elastico' => [null, ['biceps', 'abs']], // Banded Lat Pulldown
        'Lat machine presa larga' => [null, ['biceps']], // Wide-Grip Lat Pulldown
        'Meadows row' => [null, ['biceps', 'shoulders']], // Meadows Row
        'Pendlay row' => [null, ['biceps', 'shoulders']], // Pendlay Row
        'Piegamenti scapolari' => [null, ['chest', 'shoulders', 'abs']], // Scapular Push-up
        'Posizione del bambino' => [null, ['shoulders', 'glutes']], // Child's Pose
        'Rack pull' => [null, ['glutes', 'hamstrings']], // Rack Pull
        'Rematore a macchina' => [null, ['biceps']], // Machine Row
        'Rematore alla porta' => [null, ['biceps', 'abs']], // Doorway Row
        'Rematore con appoggio al petto' => [null, ['biceps', 'shoulders']], // Chest Supported Row
        'Rematore con asciugamano' => [null, ['biceps', 'abs']], // Towel Row
        'Rematore con due manubri' => [null, ['biceps', 'shoulders']], // Dumbbell Bent Over Row
        'Rematore con elastico' => [null, ['biceps']], // Banded Row
        'Sospensione attiva' => [null, ['forearms', 'abs']], // Active Hang
        'Stacco con trap bar' => [null, ['quads', 'forearms']], // Trap Bar Deadlift
        'Stacco sumo' => [null, ['glutes', 'quads']], // Sumo Deadlift
        'Stacco sumo con manubrio' => [null, ['glutes', 'quads']], // Dumbbell Sumo Deadlift
        'Superman' => [null, ['glutes', 'hamstrings']], // Superman
        'Superman isometrico' => [null, ['glutes', 'hamstrings']], // Superman Hold
        'Trazioni a L' => [null, ['biceps', 'abs', 'forearms']], // L-Sit Pull-up
        'Trazioni commando' => [null, ['biceps', 'abs']], // Commando Pull-up
        'Trazioni con asciugamano' => [null, ['biceps', 'forearms']], // Towel Pull-up
        'Trazioni negative' => [null, ['biceps', 'forearms']], // Negative Pull-up
        'Trazioni orizzontali' => [null, ['biceps', 'abs']], // Inverted Row
        'Trazioni presa neutra' => [null, ['biceps', 'abs']], // Neutral-Grip Pull-up
        'Trazioni scapolari' => [null, ['forearms', 'abs']], // Scapular Pull-up
        'Trazioni zavorrate' => [null, ['biceps']], // Weighted Pull-up

        // ── Spalle ──
        'Allungamento spalle incrociato' => [null, ['back']], // Cross-Body Shoulder Stretch
        'Alzate frontali ai cavi' => [null, ['chest']], // Cable Front Raise
        'Alzate frontali con disco' => [null, ['chest']], // Plate Front Raise
        'Alzate laterali a macchina' => [null, ['back']], // Machine Lateral Raise
        'Alzate posteriori ai cavi' => [null, ['back']], // Cable Rear Delt Fly
        'Circonduzioni delle braccia' => [null, ['chest', 'back']], // Arm Circles
        'Face pull con elastico' => [null, []], // Banded Face Pull
        'Landmine press' => [null, ['chest', 'triceps']], // Landmine Press
        'Pectoral machine inversa' => [null, ['back']], // Reverse Pec Deck
        'Piegamenti in verticale' => [null, ['triceps', 'abs', 'chest']], // Handstand Push-up
        'Piegamenti in verticale al muro' => [null, ['triceps', 'abs', 'chest']], // Wall Handstand Push-up
        'Pike push up' => [null, ['triceps', 'chest', 'abs']], // Pike Push-up
        'Pike push up con piedi rialzati' => [null, ['triceps', 'chest', 'abs']], // Feet-Elevated Pike Push-up
        'Push press' => [null, ['triceps', 'quads']], // Push Press
        'Scrollate con manubri' => [null, ['forearms']], // Dumbbell Shrug
        'Shoulder press in piedi' => [null, ['triceps', 'abs']], // Standing Dumbbell Press
        'Wall walk' => [null, ['abs', 'chest', 'triceps']], // Wall Walk

        // ── Bicipiti ──
        'Chin up assistite' => [null, ['back']], // Assisted Chin-up
        'Chin up zavorrate' => [null, ['back']], // Weighted Chin-up
        'Curl a martello con corda' => [null, ['forearms']], // Rope Hammer Curl
        'Curl su panca inclinata' => [null, ['forearms']], // Incline Dumbbell Curl
        'Drag curl' => [null, ['forearms']], // Drag Curl
        'Spider curl' => [null, ['forearms']], // Spider Curl

        // ── Tricipiti ──
        'Camminata del granchio' => [null, ['glutes', 'abs', 'shoulders']], // Crab Walk
        'Dips assistite' => [null, ['chest']], // Assisted Dip
        'Dips su panca' => [null, ['chest', 'shoulders']], // Bench Dip
        'Dips zavorrate' => [null, ['chest', 'shoulders']], // Weighted Dip
        /*
         * ⚠️ Qui la spalla **resta**: e' la variante sopra la testa, e il
         * deltoide tiene il braccio in flessione per tutta la serie. 💡 Non
         * e' lo stesso caso dello skullcrusher qui sotto.
         */
        'Estensioni tricipiti a un braccio' => [null, ['shoulders']], // Single Arm Dumbbell Tricep Extension
        /*
         * 🔧 **Correzione del 28/08/2026, dai dati e non a occhio.**
         *
         * ⛔ workout-guide dava `Shoulders` come secondario. In uno
         * skullcrusher la spalla **sta ferma**: regge il braccio, non
         * lavora. 🚨 Era in tre schede su quattro del committente, e da sola
         * spingeva le spalle sopra il petto sulla figura del corpo.
         */
        'French press con manubri' => [null, []], // Two Dumbbell Skullcrusher
        'French press con un manubrio' => [null, ['shoulders']], // Single Dumbbell Skullcrusher
        'Piegamenti a diamante' => [null, ['chest', 'shoulders', 'abs']], // Diamond Push-up

        // ── Addome ──
        'Bird dog' => [null, ['glutes', 'shoulders']], // Bird Dog
        'Bruco' => [null, ['shoulders', 'hamstrings', 'chest']], // Inchworm
        'Camminata dell\'orso' => [null, ['shoulders', 'quads']], // Bear Crawl
        'Crunch bicicletta' => [null, ['quads']], // Bicycle Crunch
        'Crunch con disco' => [null, []], // Weighted Crunch
        'Dead bug' => [null, ['quads']], // Dead Bug
        'Dead bug con elastico' => [null, ['glutes', 'shoulders']], // Banded Dead Bug
        'Dragon flag' => [null, ['back', 'quads']], // Dragon Flag
        'Flessioni laterali con manubrio' => [null, ['forearms']], // Dumbbell Side Bend
        'Forbici' => [null, ['quads']], // Flutter Kick
        'Hollow rock' => [null, ['quads']], // Hollow Rock
        'Knee tuck da seduto' => [null, ['quads']], // Seated Knee Tuck
        'L-sit' => [null, ['triceps', 'quads', 'shoulders']], // L-Sit Hold
        'Pallof hold ai cavi' => [null, ['glutes', 'shoulders']], // Cable Pallof Hold
        'Pallof press' => [null, ['shoulders']], // Pallof Press
        'Pallof press con elastico' => [null, ['glutes', 'shoulders']], // Banded Pallof Press
        'Pallof press in ginocchio' => [null, ['glutes', 'shoulders']], // Half-Kneeling Pallof Press
        'Piegamenti con tocco delle spalle' => [null, ['chest', 'shoulders', 'triceps']], // Push-up Shoulder Tap
        'Plank con tocco delle spalle' => [null, ['shoulders', 'chest']], // Plank Shoulder Tap
        'Plank dell\'orso' => [null, ['quads', 'shoulders']], // Bear Plank
        'Plank di Copenaghen' => [null, ['quads', 'glutes']], // Copenhagen Plank
        'Plank jack' => [null, ['shoulders']], // Plank Jack
        'Plank laterale con discesa del bacino' => [null, ['shoulders', 'glutes']], // Side Plank Hip Dip
        'Russian twist con peso' => [null, ['shoulders']], // Weighted Russian Twist
        'Sit up su panca declinata' => [null, ['quads']], // Decline Sit-Up
        'Sollevamento ginocchia alla sbarra' => [null, ['forearms']], // Hanging Knee Raise
        'Sollevamento ginocchia alle parallele' => [null, ['shoulders']], // Captain's Chair Knee Raise
        'Spaccalegna ai cavi' => [null, ['shoulders']], // Cable Woodchop
        'Spaccalegna con elastico' => [null, ['shoulders', 'glutes']], // Banded Woodchop
        'Toccare le punte' => [null, []], // Toe Touch
        'Tocco dei talloni' => [null, []], // Heel Tap
        'Torsioni del busto' => [null, ['back']], // Torso Twists
        'V-up' => [null, ['quads']], // V-Up

        // ── Glutei ──
        'Abduzioni da sdraiato' => [null, ['abs']], // Side-Lying Hip Abduction
        'Abduzioni da seduto con elastico' => [null, ['abs']], // Banded Seated Hip Abduction
        'Abduzioni in piedi con elastico' => [null, ['abs']], // Banded Standing Hip Abduction
        'Affondo incrociato' => [null, ['quads', 'hamstrings', 'abs']], // Curtsy Lunge
        'Affondo incrociato con manubri' => [null, ['quads', 'hamstrings', 'abs']], // Dumbbell Curtsy Lunge
        'Affondo indietro in deficit' => [null, ['quads', 'hamstrings', 'abs']], // Deficit Reverse Lunge
        'Allungamento a farfalla' => [null, ['quads']], // Butterfly Stretch
        'Camminata laterale con elastico' => [null, ['quads', 'abs']], // Banded Lateral Walk
        'Clamshell' => [null, ['abs']], // Clamshell
        'Clamshell con elastico' => [null, ['abs']], // Banded Clamshell
        'Fire hydrant' => [null, ['abs']], // Fire Hydrant
        'Fire hydrant con elastico' => [null, ['abs']], // Banded Fire Hydrant
        'Frog pump' => [null, ['hamstrings']], // Frog Pump
        'Frog pump con elastico' => [null, ['hamstrings']], // Banded Frog Pump
        'Hip airplane' => [null, ['hamstrings', 'abs']], // Hip Airplane
        'Hip thrust al multipower' => [null, ['hamstrings', 'abs']], // Smith Machine Hip Thrust
        'Hip thrust con elastico' => [null, ['hamstrings', 'abs']], // Banded Hip Thrust
        'Hip thrust con manubrio' => [null, ['hamstrings']], // Dumbbell Hip Thrust
        'Iperestensioni inverse' => [null, ['hamstrings', 'back']], // Reverse Hyperextension
        'Iperestensioni per i glutei' => [null, ['hamstrings', 'back']], // Glute-Focused Back Extension
        'Kickback a macchina' => [null, ['hamstrings']], // Machine Glute Kickback
        'Kickback con elastico' => [null, ['hamstrings', 'abs']], // Banded Kickback
        'Monster walk con elastico' => [null, ['quads', 'hamstrings', 'abs']], // Banded Monster Walk
        'Ponte glutei con bilanciere' => [null, ['hamstrings', 'abs']], // Barbell Glute Bridge
        'Ponte glutei con elastico' => [null, ['hamstrings', 'abs']], // Banded Glute Bridge
        'Ponte glutei con manubrio' => [null, ['hamstrings', 'abs']], // Dumbbell Glute Bridge
        'Ponte glutei con marcia' => [null, ['hamstrings', 'abs']], // Glute Bridge March
        'Pull through ai cavi' => [null, ['hamstrings', 'back']], // Cable Pull-Through
        'Slanci indietro' => [null, ['hamstrings', 'abs']], // Donkey Kick
        'Slanci indietro con elastico' => [null, ['hamstrings', 'abs']], // Banded Donkey Kick
        'Slanci laterali da sdraiato' => [null, ['abs']], // Side-Lying Leg Raise
        'Squat sumo con manubrio' => [null, ['quads', 'hamstrings']], // Dumbbell Sumo Squat

        // ── Quadricipiti ──
        'Adduttori a macchina' => [null, ['abs']], // Hip Adduction Machine
        'Adduzioni ai cavi' => [null, ['abs', 'glutes']], // Cable Standing Hip Adduction
        'Affondo indietro' => [null, ['glutes', 'hamstrings']], // Reverse Lunge
        'Affondo indietro al multipower' => [null, ['glutes', 'hamstrings', 'abs']], // Smith Machine Reverse Lunge
        'Affondo laterale a corpo libero' => [null, ['glutes', 'hamstrings', 'abs']], // Lateral Lunge
        'Allungamento flessori dell\'anca' => [null, []], // Kneeling Hip Flexor Stretch
        'Allungamento quadricipiti in piedi' => [null, ['glutes']], // Standing Quad Stretch
        'Belt squat' => [null, ['glutes']], // Belt Squat
        'Cossack squat' => [null, ['glutes', 'hamstrings', 'abs']], // Cossack Squat
        'Goblet squat con talloni rialzati' => [null, ['glutes', 'abs']], // Heel-Elevated Goblet Squat
        'Pistol squat' => [null, ['glutes', 'hamstrings', 'abs']], // Pistol Squat
        'Pistol squat assistito' => [null, ['glutes', 'hamstrings', 'abs']], // Assisted Pistol Squat
        'Sedia al muro' => [null, ['glutes', 'abs']], // Wall Sit
        'Shrimp squat' => [null, ['glutes', 'hamstrings', 'abs']], // Shrimp Squat
        'Skater squat' => [null, ['glutes', 'hamstrings', 'abs']], // Skater Squat
        'Split squat' => [null, ['glutes', 'abs']], // Split Squat
        'Split squat al multipower' => [null, ['glutes', 'abs']], // Smith Machine Split Squat
        'Split squat con avampiede rialzato' => [null, ['glutes', 'abs']], // Front-Foot Elevated Split Squat
        'Squat a corpo libero' => [null, ['glutes', 'hamstrings', 'abs']], // Bodyweight Squat
        'Squat a una gamba al box' => [null, ['glutes', 'hamstrings', 'abs']], // Single-Leg Box Squat
        'Squat al landmine' => [null, ['glutes', 'abs']], // Landmine Squat
        'Squat al multipower' => [null, ['glutes', 'abs']], // Smith Machine Squat
        'Squat bulgaro al multipower' => [null, ['glutes', 'abs']], // Smith Machine Bulgarian Split Squat
        'Squat con elastico' => [null, ['glutes', 'hamstrings', 'abs']], // Banded Squat
        'Squat con salto' => [null, ['glutes', 'calves']], // Jump Squat
        'Step down' => [null, ['glutes', 'hamstrings', 'calves']], // Step-Down

        // ── Femorali ──
        'Allungamento femorali' => [null, ['calves']], // Hamstring Stretch
        'Allungamento in avanti da seduto' => [null, ['back', 'calves']], // Seated Forward Fold
        'Leg curl con asciugamano' => [null, ['glutes', 'abs']], // Towel Hamstring Curl
        'Leg curl con fitball' => [null, ['glutes', 'abs']], // Stability Ball Hamstring Curl
        'Leg curl da seduto' => [null, ['calves']], // Seated Leg Curl
        'Slanci delle gambe' => [null, ['glutes', 'quads']], // Leg Swings
        'Stacco rumeno a una gamba' => [null, ['glutes', 'abs']], // Single-Leg Romanian Deadlift
        'Stacco rumeno al landmine' => [null, ['glutes', 'back']], // Landmine Romanian Deadlift
        'Stacco rumeno al multipower' => [null, ['glutes', 'back']], // Smith Machine Romanian Deadlift
        'Stacco rumeno con kettlebell' => [null, ['glutes', 'back']], // Kettlebell Romanian Deadlift
        'Stacco rumeno con manubri' => [null, ['glutes', 'back']], // Dumbbell Romanian Deadlift
        'Walkout per femorali' => [null, ['glutes', 'abs']], // Lying Hamstring Walkout

        // ── Polpacci ──
        'Allungamento polpacci al muro' => [null, []], // Wall Calf Stretch
        'Calf a corpo libero' => [null, []], // Calf Raise
        'Donkey calf' => [null, []], // Donkey Calf Raise

        // ── Corpo intero ──
        'Mezzo burpee' => [null, ['quads', 'shoulders']], // Half Burpee
        'Sprawl' => [null, ['abs', 'shoulders']], // Sprawl
        'Squat thrust' => [null, ['quads', 'shoulders']], // Squat Thrust
        'World\'s greatest stretch' => [null, ['glutes', 'hamstrings', 'back']], // World's Greatest Stretch

        // ── Cardio ──
        'Camminata' => [null, []], // Walking
        'Corsa sul posto a ginocchia alte' => [null, ['abs']], // High Knees
        'Escursionismo' => [null, ['glutes']], // Hiking
        'Jumping jack' => [null, ['shoulders', 'calves']], // Jumping Jack
        'Nuoto' => [null, ['shoulders']], // Swimming
        'Salti del pattinatore' => [null, ['glutes', 'calves']], // Skater Hop
        'Spostamenti laterali' => [null, ['glutes', 'calves']], // Lateral Shuffle
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
