<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MuscleGroup;
use App\Models\Exercise;
use App\Support\Tenancy\TenantContext;
use App\Support\Training\MuscoliDegliEsercizi;
use Illuminate\Database\Seeder;

/**
 * La libreria esercizi di base della piattaforma — B2.4.
 *
 * 🚨 **Serve a rendere possibile l'onboarding di una palestra.**
 * Senza una base condivisa, il primo accesso di un cliente comincia con due ore
 * di data entry — e nessuno le fa: il trainer scrive i nomi nelle note e la
 * libreria non nasce mai. Con questa base, `ExerciseMatcher` (B7.3) ha anche
 * qualcosa su cui riconciliare i PDF fin dal primo import.
 *
 * I nomi sono quelli che si usano davvero in una palestra italiana, non le
 * traduzioni letterali dall'inglese: e' il vocabolario su cui il matcher deve
 * fare centro.
 *
 * `updateOrCreate` sulla forma canonica: rilanciare il seeder non duplica.
 */
class ExerciseLibrarySeeder extends Seeder
{
    /**
     * nome, gruppo, attrezzo, MET.
     *
     * I MET vengono dal Compendium of Physical Activities. Dove non e' noto si
     * lascia `null`: `WorkoutCalorieService` usa allora il valore generico 5.0,
     * che e' meglio di un numero inventato per riempire una colonna.
     *
     * @var list<array{0: string, 1: MuscleGroup, 2: ?string, 3: ?float}>
     */
    private const ESERCIZI = [
        // Petto
        ['Panca piana', MuscleGroup::Chest, 'bilanciere', 5.0],
        ['Panca inclinata', MuscleGroup::Chest, 'bilanciere', 5.0],
        ['Panca declinata', MuscleGroup::Chest, 'bilanciere', 5.0],
        ['Croci ai cavi', MuscleGroup::Chest, 'cavi', 3.5],
        ['Croci su panca', MuscleGroup::Chest, 'manubri', 3.5],
        ['Chest press', MuscleGroup::Chest, 'macchina', 4.0],
        ['Piegamenti', MuscleGroup::Chest, 'corpo libero', 3.8],
        ['Dips', MuscleGroup::Chest, 'corpo libero', 5.0],
        // G3
        ['Panca piana con manubri', MuscleGroup::Chest, 'manubri', 5.0],
        ['Panca inclinata con manubri', MuscleGroup::Chest, 'manubri', 5.0],
        ['Pectoral machine', MuscleGroup::Chest, 'macchina', 4.0],
        ['Croci ai cavi alti', MuscleGroup::Chest, 'cavi', 3.5],
        ['Piegamenti su rialzo', MuscleGroup::Chest, 'corpo libero', 3.8],
        ['Pullover', MuscleGroup::Chest, 'manubri', 4.0],

        // Schiena
        ['Lat machine', MuscleGroup::Back, 'macchina', 4.5],
        ['Trazioni', MuscleGroup::Back, 'corpo libero', 8.0],
        ['Rematore con bilanciere', MuscleGroup::Back, 'bilanciere', 5.0],
        ['Rematore con manubrio', MuscleGroup::Back, 'manubri', 5.0],
        ['Pulley basso', MuscleGroup::Back, 'cavi', 4.5],
        ['Stacco da terra', MuscleGroup::Back, 'bilanciere', 6.0],
        ['Stacco rumeno', MuscleGroup::Back, 'bilanciere', 5.5],
        ['Iperestensioni', MuscleGroup::Back, 'corpo libero', 3.5],
        // G3
        ['Rematore presa inversa', MuscleGroup::Back, 'bilanciere', 5.0],
        ['Rematore T-bar', MuscleGroup::Back, 'macchina', 5.0],
        ['Lat machine presa inversa', MuscleGroup::Back, 'macchina', 4.5],
        ['Lat machine presa stretta', MuscleGroup::Back, 'macchina', 4.5],
        ['Pulldown a braccia tese', MuscleGroup::Back, 'cavi', 3.5],
        ['Trazioni assistite', MuscleGroup::Back, 'macchina', 5.0],
        ['Rematore a un braccio ai cavi', MuscleGroup::Back, 'cavi', 4.5],
        ['Good morning', MuscleGroup::Back, 'bilanciere', 5.0],

        // Spalle
        ['Lento avanti', MuscleGroup::Shoulders, 'bilanciere', 5.0],
        ['Shoulder press', MuscleGroup::Shoulders, 'manubri', 4.5],
        ['Alzate laterali', MuscleGroup::Shoulders, 'manubri', 3.5],
        ['Alzate frontali', MuscleGroup::Shoulders, 'manubri', 3.5],
        ['Alzate posteriori', MuscleGroup::Shoulders, 'manubri', 3.5],
        ['Tirate al mento', MuscleGroup::Shoulders, 'bilanciere', 4.0],
        // G3
        ['Arnold press', MuscleGroup::Shoulders, 'manubri', 4.5],
        ['Alzate laterali ai cavi', MuscleGroup::Shoulders, 'cavi', 3.5],
        ['Face pull', MuscleGroup::Shoulders, 'cavi', 3.5],
        ['Military press', MuscleGroup::Shoulders, 'bilanciere', 5.0],
        ['Shoulder press a macchina', MuscleGroup::Shoulders, 'macchina', 4.0],
        ['Scrollate', MuscleGroup::Shoulders, 'bilanciere', 4.0],

        // Braccia
        ['Curl bicipiti', MuscleGroup::Biceps, 'manubri', 3.5],
        ['Curl con bilanciere', MuscleGroup::Biceps, 'bilanciere', 3.5],
        ['Curl a martello', MuscleGroup::Biceps, 'manubri', 3.5],
        ['Curl alla panca Scott', MuscleGroup::Biceps, 'macchina', 3.5],
        ['Push down tricipiti', MuscleGroup::Triceps, 'cavi', 3.5],
        ['French press', MuscleGroup::Triceps, 'bilanciere', 3.5],
        ['Estensioni sopra la testa', MuscleGroup::Triceps, 'manubri', 3.5],
        ['Panca stretta', MuscleGroup::Triceps, 'bilanciere', 5.0],
        // G3
        ['Curl ai cavi', MuscleGroup::Biceps, 'cavi', 3.5],
        ['Curl concentrato', MuscleGroup::Biceps, 'manubri', 3.5],
        ['Curl inverso', MuscleGroup::Biceps, 'bilanciere', 3.5],
        ['Chin up', MuscleGroup::Biceps, 'corpo libero', 8.0],
        ['Push down con corda', MuscleGroup::Triceps, 'cavi', 3.5],
        ['Dips alle parallele', MuscleGroup::Triceps, 'corpo libero', 5.0],
        ['Kickback tricipiti', MuscleGroup::Triceps, 'manubri', 3.5],
        ['Estensioni ai cavi sopra la testa', MuscleGroup::Triceps, 'cavi', 3.5],

        /*
         * Avambracci — G3.
         *
         * 🚨 **Il gruppo era completamente vuoto.** `G0.2` l'ha misurato: zero
         * esercizi su `MuscleGroup::Forearms`. Un gruppo a zero non e' «meno
         * scelta»: e' un trainer che apre la tendina, non trova niente, e
         * smette di fidarsi del catalogo — e da li' in poi scrive tutto a mano.
         */
        ['Curl ai polsi', MuscleGroup::Forearms, 'bilanciere', 3.0],
        ['Curl ai polsi inverso', MuscleGroup::Forearms, 'bilanciere', 3.0],
        ['Camminata del contadino', MuscleGroup::Forearms, 'manubri', 5.0],
        ['Sospensione alla sbarra', MuscleGroup::Forearms, 'corpo libero', 3.5],
        // ⚠️ MET non noto per questo attrezzo: `null`, non un numero inventato.
        ['Rullo per avambracci', MuscleGroup::Forearms, 'attrezzo', null],

        // Gambe
        ['Squat', MuscleGroup::Quads, 'bilanciere', 6.0],
        ['Squat frontale', MuscleGroup::Quads, 'bilanciere', 6.0],
        ['Pressa', MuscleGroup::Quads, 'macchina', 5.0],
        ['Affondi', MuscleGroup::Quads, 'manubri', 5.0],
        ['Leg extension', MuscleGroup::Quads, 'macchina', 4.0],
        ['Leg curl', MuscleGroup::Hamstrings, 'macchina', 4.0],
        ['Hip thrust', MuscleGroup::Glutes, 'bilanciere', 5.0],
        ['Abduzioni', MuscleGroup::Glutes, 'macchina', 3.5],
        ['Calf in piedi', MuscleGroup::Calves, 'macchina', 3.5],
        ['Calf da seduto', MuscleGroup::Calves, 'macchina', 3.5],
        // G3 — quadricipiti
        ['Squat bulgaro', MuscleGroup::Quads, 'manubri', 5.5],
        ['Hack squat', MuscleGroup::Quads, 'macchina', 5.0],
        ['Goblet squat', MuscleGroup::Quads, 'manubri', 5.0],
        ['Affondi camminati', MuscleGroup::Quads, 'manubri', 5.0],
        ['Step up', MuscleGroup::Quads, 'manubri', 5.0],
        ['Sissy squat', MuscleGroup::Quads, 'corpo libero', 4.0],
        ['Pressa orizzontale', MuscleGroup::Quads, 'macchina', 5.0],
        // G3 — femorali: ⚠️ `G0.2` ne ha contato **uno**, per tutta la catena posteriore.
        ['Leg curl in piedi', MuscleGroup::Hamstrings, 'macchina', 4.0],
        ['Leg curl sdraiato', MuscleGroup::Hamstrings, 'macchina', 4.0],
        ['Stacco a gambe tese', MuscleGroup::Hamstrings, 'bilanciere', 5.5],
        ['Nordic curl', MuscleGroup::Hamstrings, 'corpo libero', 5.0],
        ['Glute ham raise', MuscleGroup::Hamstrings, 'macchina', 5.0],
        // G3 — glutei
        ['Ponte glutei', MuscleGroup::Glutes, 'corpo libero', 3.5],
        ['Kickback ai cavi', MuscleGroup::Glutes, 'cavi', 3.5],
        ['Abduzioni in piedi ai cavi', MuscleGroup::Glutes, 'cavi', 3.5],
        ['Hip thrust a una gamba', MuscleGroup::Glutes, 'corpo libero', 4.0],
        ['Affondi laterali', MuscleGroup::Glutes, 'manubri', 5.0],
        // G3 — polpacci
        ['Calf alla pressa', MuscleGroup::Calves, 'macchina', 3.5],
        ['Calf a una gamba', MuscleGroup::Calves, 'corpo libero', 3.5],
        ['Salti sul posto', MuscleGroup::Calves, 'corpo libero', 8.0],

        // Core
        ['Crunch', MuscleGroup::Abs, 'corpo libero', 3.0],
        ['Plank', MuscleGroup::Abs, 'corpo libero', 3.0],
        ['Russian twist', MuscleGroup::Abs, 'corpo libero', 3.5],
        ['Sollevamento gambe', MuscleGroup::Abs, 'corpo libero', 3.5],
        // G3
        ['Crunch ai cavi', MuscleGroup::Abs, 'cavi', 3.5],
        ['Crunch inverso', MuscleGroup::Abs, 'corpo libero', 3.5],
        ['Plank laterale', MuscleGroup::Abs, 'corpo libero', 3.0],
        ['Hollow hold', MuscleGroup::Abs, 'corpo libero', 3.5],
        ['Ruota per addominali', MuscleGroup::Abs, 'attrezzo', 4.0],
        ['Mountain climber', MuscleGroup::Abs, 'corpo libero', 8.0],
        ['Sollevamento gambe alla sbarra', MuscleGroup::Abs, 'corpo libero', 4.0],

        // Cardio
        ['Tapis roulant', MuscleGroup::Cardio, 'macchina', 7.0],
        ['Cyclette', MuscleGroup::Cardio, 'macchina', 6.8],
        ['Ellittica', MuscleGroup::Cardio, 'macchina', 5.0],
        ['Vogatore', MuscleGroup::Cardio, 'macchina', 7.0],
        ['Corda', MuscleGroup::Cardio, 'corpo libero', 11.0],
        ['Burpees', MuscleGroup::FullBody, 'corpo libero', 8.0],
        // G3 — cardio
        ['Camminata in salita', MuscleGroup::Cardio, 'macchina', 6.0],
        ['Corsa', MuscleGroup::Cardio, 'corpo libero', 9.8],
        ['Scala', MuscleGroup::Cardio, 'macchina', 9.0],
        ['Air bike', MuscleGroup::Cardio, 'macchina', 8.0],
        ['Sci indoor', MuscleGroup::Cardio, 'macchina', 7.0],
        // G3 — full body
        ['Kettlebell swing', MuscleGroup::FullBody, 'kettlebell', 9.8],
        ['Thruster', MuscleGroup::FullBody, 'bilanciere', 8.0],
        ['Girata', MuscleGroup::FullBody, 'bilanciere', 6.0],
        ['Strappo', MuscleGroup::FullBody, 'bilanciere', 6.0],
        ['Battle rope', MuscleGroup::FullBody, 'attrezzo', 8.0],
        ['Slam ball', MuscleGroup::FullBody, 'attrezzo', 8.0],

        /*
         * ══ 🆕 IL SECONDO BLOCCO — 3b-L, 28/08/2026 ═══════════════════════
         *
         * 📌 *«A sto punto importa anche gli esercizi che mancano da noi
         * proprio no? Cosi' abbiamo anche noi un db piu' ampio»*.
         *
         * 💡 Vengono da **workout-guide**, la stessa raccolta da cui arrivano
         * le illustrazioni: importare i disegni e non gli esercizi avrebbe
         * lasciato duecento figure senza niente a cui attaccarsi.
         *
         * ⚠️ **I nomi sono scritti a mano, uno per uno.** Sono il vocabolario
         * su cui `ExerciseMatcher` (B7.3) riconcilia i PDF delle palestre: una
         * traduzione letterale dall'inglese — «Spinta dell'anca» per «Hip
         * thrust» — non la cerca nessuno, e un nome che nessuno cerca e' una
         * riga che non verra' mai agganciata.
         *
         * ⛔ **Due voci di workout-guide sono state scartate apposta**:
         * `bent-over-rear-delt-raise` e `chair-dip`, perche' dicono la stessa
         * cosa di «Alzate posteriori» e «Dips su panca». 🚨 Un quasi-doppione
         * fa piu' danno di un buco: costringe il matcher a scegliere fra due
         * righe che vogliono dire la stessa cosa, e sceglie a caso.
         *
         * ⚠️ **Il MET resta `null` quasi ovunque, ed e' voluto.** Ce l'hanno
         * solo gli allungamenti (2.3 — Compendium 02101, «stretching, mild») e
         * i cardio che hanno una voce loro. 🚨 Per gli allungamenti il numero
         * **serve**: senza, il ripiego generico e' 5.0, e un'app che conta
         * 5 MET per due minuti di allungamento dei femorali sta regalando
         * calorie che nessuno ha speso.
         *
         * 🆕 `elastico` e' un attrezzo nuovo: trentatre' esercizi lo usano, e
         * non c'era.
         */

        // Petto
        ['Allungamento pettorali alla porta', MuscleGroup::Chest, 'corpo libero', 2.3],
        ['Panca declinata con manubri', MuscleGroup::Chest, 'manubri', null],
        ['Panca piana al multipower', MuscleGroup::Chest, 'macchina', null],
        ['Piegamenti a macchina da scrivere', MuscleGroup::Chest, 'corpo libero', null],
        ['Piegamenti a presa larga', MuscleGroup::Chest, 'corpo libero', null],
        ['Piegamenti al muro', MuscleGroup::Chest, 'corpo libero', null],
        ['Piegamenti arciere', MuscleGroup::Chest, 'corpo libero', null],
        ['Piegamenti con piedi rialzati', MuscleGroup::Chest, 'corpo libero', null],
        ['Piegamenti esplosivi', MuscleGroup::Chest, 'corpo libero', null],
        ['Piegamenti hindu', MuscleGroup::Chest, 'corpo libero', null],
        ['Piegamenti sulle ginocchia', MuscleGroup::Chest, 'corpo libero', null],
        ['Piegamenti zavorrati', MuscleGroup::Chest, 'corpo libero', null],
        ['Seal jack', MuscleGroup::Chest, 'corpo libero', null],

        // Schiena
        ['Alzate a T da prono', MuscleGroup::Back, 'corpo libero', null],
        ['Alzate a Y da prono', MuscleGroup::Back, 'corpo libero', null],
        ['Angelo inverso', MuscleGroup::Back, 'corpo libero', null],
        ['Aperture con elastico', MuscleGroup::Back, 'elastico', null],
        ['Gatto-cammello', MuscleGroup::Back, 'corpo libero', 2.3],
        ['Lat machine con elastico', MuscleGroup::Back, 'elastico', null],
        ['Lat machine presa larga', MuscleGroup::Back, 'cavi', null],
        ['Meadows row', MuscleGroup::Back, 'bilanciere', null],
        ['Pendlay row', MuscleGroup::Back, 'bilanciere', null],
        ['Piegamenti scapolari', MuscleGroup::Back, 'corpo libero', null],
        ['Posizione del bambino', MuscleGroup::Back, 'corpo libero', 2.3],
        ['Rack pull', MuscleGroup::Back, 'bilanciere', null],
        ['Rematore a macchina', MuscleGroup::Back, 'macchina', null],
        ['Rematore alla porta', MuscleGroup::Back, 'corpo libero', null],
        ['Rematore con appoggio al petto', MuscleGroup::Back, 'macchina', null],
        ['Rematore con asciugamano', MuscleGroup::Back, 'corpo libero', null],
        ['Rematore con due manubri', MuscleGroup::Back, 'manubri', null],
        ['Rematore con elastico', MuscleGroup::Back, 'elastico', null],
        ['Sospensione attiva', MuscleGroup::Back, 'corpo libero', null],
        ['Stacco con trap bar', MuscleGroup::Back, 'bilanciere', null],
        ['Stacco sumo', MuscleGroup::Back, 'bilanciere', null],
        ['Stacco sumo con manubrio', MuscleGroup::Back, 'manubri', null],
        ['Superman', MuscleGroup::Back, 'corpo libero', null],
        ['Superman isometrico', MuscleGroup::Back, 'corpo libero', null],
        ['Trazioni a L', MuscleGroup::Back, 'corpo libero', null],
        ['Trazioni commando', MuscleGroup::Back, 'corpo libero', null],
        ['Trazioni con asciugamano', MuscleGroup::Back, 'corpo libero', null],
        ['Trazioni negative', MuscleGroup::Back, 'corpo libero', null],
        ['Trazioni orizzontali', MuscleGroup::Back, 'corpo libero', null],
        ['Trazioni presa neutra', MuscleGroup::Back, 'corpo libero', null],
        ['Trazioni scapolari', MuscleGroup::Back, 'corpo libero', null],
        ['Trazioni zavorrate', MuscleGroup::Back, 'corpo libero', null],

        // Spalle
        ['Allungamento spalle incrociato', MuscleGroup::Shoulders, 'corpo libero', 2.3],
        ['Alzate frontali ai cavi', MuscleGroup::Shoulders, 'cavi', null],
        ['Alzate frontali con disco', MuscleGroup::Shoulders, 'attrezzo', null],
        ['Alzate laterali a macchina', MuscleGroup::Shoulders, 'macchina', null],
        ['Alzate posteriori ai cavi', MuscleGroup::Shoulders, 'cavi', null],
        ['Circonduzioni delle braccia', MuscleGroup::Shoulders, 'corpo libero', 2.3],
        ['Face pull con elastico', MuscleGroup::Shoulders, 'elastico', null],
        ['Landmine press', MuscleGroup::Shoulders, 'bilanciere', null],
        ['Pectoral machine inversa', MuscleGroup::Shoulders, 'macchina', null],
        ['Piegamenti in verticale', MuscleGroup::Shoulders, 'corpo libero', null],
        ['Piegamenti in verticale al muro', MuscleGroup::Shoulders, 'corpo libero', null],
        ['Pike push up', MuscleGroup::Shoulders, 'corpo libero', null],
        ['Pike push up con piedi rialzati', MuscleGroup::Shoulders, 'corpo libero', null],
        ['Push press', MuscleGroup::Shoulders, 'bilanciere', null],
        ['Scrollate con manubri', MuscleGroup::Shoulders, 'manubri', null],
        ['Shoulder press in piedi', MuscleGroup::Shoulders, 'manubri', null],
        ['Wall walk', MuscleGroup::Shoulders, 'corpo libero', null],

        // Bicipiti
        ['Chin up assistite', MuscleGroup::Biceps, 'macchina', null],
        ['Chin up zavorrate', MuscleGroup::Biceps, 'corpo libero', null],
        ['Curl a martello con corda', MuscleGroup::Biceps, 'cavi', null],
        ['Curl su panca inclinata', MuscleGroup::Biceps, 'manubri', null],
        ['Drag curl', MuscleGroup::Biceps, 'bilanciere', null],
        ['Spider curl', MuscleGroup::Biceps, 'manubri', null],

        // Tricipiti
        ['Camminata del granchio', MuscleGroup::Triceps, 'corpo libero', null],
        ['Dips assistite', MuscleGroup::Triceps, 'macchina', null],
        ['Dips su panca', MuscleGroup::Triceps, 'corpo libero', null],
        ['Dips zavorrate', MuscleGroup::Triceps, 'corpo libero', null],
        ['Estensioni tricipiti a un braccio', MuscleGroup::Triceps, 'manubri', null],
        ['French press con manubri', MuscleGroup::Triceps, 'manubri', null],
        ['French press con un manubrio', MuscleGroup::Triceps, 'manubri', null],
        ['Piegamenti a diamante', MuscleGroup::Triceps, 'corpo libero', null],

        // Addome
        ['Bird dog', MuscleGroup::Abs, 'corpo libero', null],
        ['Bruco', MuscleGroup::Abs, 'corpo libero', null],
        ['Camminata dell\'orso', MuscleGroup::Abs, 'corpo libero', null],
        ['Crunch bicicletta', MuscleGroup::Abs, 'corpo libero', null],
        ['Crunch con disco', MuscleGroup::Abs, 'attrezzo', null],
        ['Dead bug', MuscleGroup::Abs, 'corpo libero', null],
        ['Dead bug con elastico', MuscleGroup::Abs, 'elastico', null],
        ['Dragon flag', MuscleGroup::Abs, 'corpo libero', null],
        ['Flessioni laterali con manubrio', MuscleGroup::Abs, 'manubri', null],
        ['Forbici', MuscleGroup::Abs, 'corpo libero', null],
        ['Hollow rock', MuscleGroup::Abs, 'corpo libero', null],
        ['Knee tuck da seduto', MuscleGroup::Abs, 'corpo libero', null],
        ['L-sit', MuscleGroup::Abs, 'corpo libero', null],
        ['Pallof hold ai cavi', MuscleGroup::Abs, 'cavi', null],
        ['Pallof press', MuscleGroup::Abs, 'cavi', null],
        ['Pallof press con elastico', MuscleGroup::Abs, 'elastico', null],
        ['Pallof press in ginocchio', MuscleGroup::Abs, 'cavi', null],
        ['Piegamenti con tocco delle spalle', MuscleGroup::Abs, 'corpo libero', null],
        ['Plank con tocco delle spalle', MuscleGroup::Abs, 'corpo libero', null],
        ['Plank dell\'orso', MuscleGroup::Abs, 'corpo libero', null],
        ['Plank di Copenaghen', MuscleGroup::Abs, 'corpo libero', null],
        ['Plank jack', MuscleGroup::Abs, 'corpo libero', null],
        ['Plank laterale con discesa del bacino', MuscleGroup::Abs, 'corpo libero', null],
        ['Russian twist con peso', MuscleGroup::Abs, 'manubri', null],
        ['Sit up su panca declinata', MuscleGroup::Abs, 'corpo libero', null],
        ['Sollevamento ginocchia alla sbarra', MuscleGroup::Abs, 'corpo libero', null],
        ['Sollevamento ginocchia alle parallele', MuscleGroup::Abs, 'macchina', null],
        ['Spaccalegna ai cavi', MuscleGroup::Abs, 'cavi', null],
        ['Spaccalegna con elastico', MuscleGroup::Abs, 'elastico', null],
        ['Toccare le punte', MuscleGroup::Abs, 'corpo libero', null],
        ['Tocco dei talloni', MuscleGroup::Abs, 'corpo libero', null],
        ['Torsioni del busto', MuscleGroup::Abs, 'corpo libero', 2.3],
        ['V-up', MuscleGroup::Abs, 'corpo libero', null],

        // Glutei
        ['Abduzioni da sdraiato', MuscleGroup::Glutes, 'corpo libero', null],
        ['Abduzioni da seduto con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Abduzioni in piedi con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Affondo incrociato', MuscleGroup::Glutes, 'corpo libero', null],
        ['Affondo incrociato con manubri', MuscleGroup::Glutes, 'manubri', null],
        ['Affondo indietro in deficit', MuscleGroup::Glutes, 'manubri', null],
        ['Allungamento a farfalla', MuscleGroup::Glutes, 'corpo libero', 2.3],
        ['Camminata laterale con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Clamshell', MuscleGroup::Glutes, 'corpo libero', null],
        ['Clamshell con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Fire hydrant', MuscleGroup::Glutes, 'corpo libero', null],
        ['Fire hydrant con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Frog pump', MuscleGroup::Glutes, 'corpo libero', null],
        ['Frog pump con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Hip airplane', MuscleGroup::Glutes, 'corpo libero', null],
        ['Hip thrust al multipower', MuscleGroup::Glutes, 'macchina', null],
        ['Hip thrust con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Hip thrust con manubrio', MuscleGroup::Glutes, 'manubri', null],
        ['Iperestensioni inverse', MuscleGroup::Glutes, 'macchina', null],
        ['Iperestensioni per i glutei', MuscleGroup::Glutes, 'corpo libero', null],
        ['Kickback a macchina', MuscleGroup::Glutes, 'macchina', null],
        ['Kickback con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Monster walk con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Ponte glutei con bilanciere', MuscleGroup::Glutes, 'bilanciere', null],
        ['Ponte glutei con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Ponte glutei con manubrio', MuscleGroup::Glutes, 'manubri', null],
        ['Ponte glutei con marcia', MuscleGroup::Glutes, 'corpo libero', null],
        ['Pull through ai cavi', MuscleGroup::Glutes, 'cavi', null],
        ['Slanci indietro', MuscleGroup::Glutes, 'corpo libero', null],
        ['Slanci indietro con elastico', MuscleGroup::Glutes, 'elastico', null],
        ['Slanci laterali da sdraiato', MuscleGroup::Glutes, 'corpo libero', null],
        ['Squat sumo con manubrio', MuscleGroup::Glutes, 'manubri', null],

        // Quadricipiti
        ['Adduttori a macchina', MuscleGroup::Quads, 'macchina', null],
        ['Adduzioni ai cavi', MuscleGroup::Quads, 'cavi', null],
        ['Affondo indietro', MuscleGroup::Quads, 'manubri', null],
        ['Affondo indietro al multipower', MuscleGroup::Quads, 'macchina', null],
        ['Affondo laterale a corpo libero', MuscleGroup::Quads, 'corpo libero', null],
        ['Allungamento flessori dell\'anca', MuscleGroup::Quads, 'corpo libero', 2.3],
        ['Allungamento quadricipiti in piedi', MuscleGroup::Quads, 'corpo libero', 2.3],
        ['Belt squat', MuscleGroup::Quads, 'macchina', null],
        ['Cossack squat', MuscleGroup::Quads, 'corpo libero', null],
        ['Goblet squat con talloni rialzati', MuscleGroup::Quads, 'manubri', null],
        ['Pistol squat', MuscleGroup::Quads, 'corpo libero', null],
        ['Pistol squat assistito', MuscleGroup::Quads, 'corpo libero', null],
        ['Sedia al muro', MuscleGroup::Quads, 'corpo libero', null],
        ['Shrimp squat', MuscleGroup::Quads, 'corpo libero', null],
        ['Skater squat', MuscleGroup::Quads, 'corpo libero', null],
        ['Split squat', MuscleGroup::Quads, 'manubri', null],
        ['Split squat al multipower', MuscleGroup::Quads, 'macchina', null],
        ['Split squat con avampiede rialzato', MuscleGroup::Quads, 'manubri', null],
        ['Squat a corpo libero', MuscleGroup::Quads, 'corpo libero', null],
        ['Squat a una gamba al box', MuscleGroup::Quads, 'attrezzo', null],
        ['Squat al landmine', MuscleGroup::Quads, 'bilanciere', null],
        ['Squat al multipower', MuscleGroup::Quads, 'macchina', null],
        ['Squat bulgaro al multipower', MuscleGroup::Quads, 'macchina', null],
        ['Squat con elastico', MuscleGroup::Quads, 'elastico', null],
        ['Squat con salto', MuscleGroup::Quads, 'corpo libero', null],
        ['Step down', MuscleGroup::Quads, 'attrezzo', null],

        // Femorali
        ['Allungamento femorali', MuscleGroup::Hamstrings, 'corpo libero', 2.3],
        ['Allungamento in avanti da seduto', MuscleGroup::Hamstrings, 'corpo libero', 2.3],
        ['Leg curl con asciugamano', MuscleGroup::Hamstrings, 'corpo libero', null],
        ['Leg curl con fitball', MuscleGroup::Hamstrings, 'attrezzo', null],
        ['Leg curl da seduto', MuscleGroup::Hamstrings, 'macchina', null],
        ['Slanci delle gambe', MuscleGroup::Hamstrings, 'corpo libero', 2.3],
        ['Stacco rumeno a una gamba', MuscleGroup::Hamstrings, 'manubri', null],
        ['Stacco rumeno al landmine', MuscleGroup::Hamstrings, 'bilanciere', null],
        ['Stacco rumeno al multipower', MuscleGroup::Hamstrings, 'macchina', null],
        ['Stacco rumeno con kettlebell', MuscleGroup::Hamstrings, 'kettlebell', null],
        ['Stacco rumeno con manubri', MuscleGroup::Hamstrings, 'manubri', null],
        ['Walkout per femorali', MuscleGroup::Hamstrings, 'corpo libero', null],

        // Polpacci
        ['Allungamento polpacci al muro', MuscleGroup::Calves, 'corpo libero', 2.3],
        ['Calf a corpo libero', MuscleGroup::Calves, 'corpo libero', null],
        ['Donkey calf', MuscleGroup::Calves, 'macchina', null],

        // Corpo intero
        ['Mezzo burpee', MuscleGroup::FullBody, 'corpo libero', null],
        ['Sprawl', MuscleGroup::FullBody, 'corpo libero', null],
        ['Squat thrust', MuscleGroup::FullBody, 'corpo libero', null],
        ['World\'s greatest stretch', MuscleGroup::FullBody, 'corpo libero', 2.3],

        // Cardio
        ['Camminata', MuscleGroup::Cardio, 'corpo libero', 3.5],
        ['Corsa sul posto a ginocchia alte', MuscleGroup::Cardio, 'corpo libero', 8.0],
        ['Escursionismo', MuscleGroup::Cardio, 'corpo libero', 6.0],
        ['Jumping jack', MuscleGroup::Cardio, 'corpo libero', 8.0],
        ['Nuoto', MuscleGroup::Cardio, 'corpo libero', 6.0],
        ['Salti del pattinatore', MuscleGroup::Cardio, 'corpo libero', null],
        ['Spostamenti laterali', MuscleGroup::Cardio, 'corpo libero', null],
    ];

    public function run(): void
    {
        // 🚨 Fuori da ogni contesto: questi esercizi sono della **piattaforma**
        // (`tenant_id = null`). Girando dentro un contesto, il trait
        // `BelongsToTenantOrGlobal` li assegnerebbe a quella palestra e tutte le
        // altre non li vedrebbero.
        app(TenantContext::class)->runWithoutTenant(function (): void {
            foreach (self::ESERCIZI as [$nome, $gruppo, $attrezzo, $met]) {
                Exercise::withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => null,
                        'slug_normalized' => Exercise::normalize($nome),
                    ],
                    [
                        'name' => $nome,
                        'muscle_group' => $gruppo,

                        /*
                         * 🆕 I secondari — 3b-A.3, 23/08/2026.
                         *
                         * 🚨 **Devono stare qui e non solo nella migrazione.**
                         * Su un'installazione pulita le migrazioni girano su un
                         * database senza esercizi, poi questo seeder li crea: se
                         * il dato stesse solo di la', un ambiente nuovo
                         * nascerebbe con centoventuno esercizi **senza
                         * secondari** — e la figura del corpo sarebbe grigia.
                         *
                         * 💡 La mappa e' una sola, in `MuscoliDegliEsercizi`.
                         */
                        'secondary_muscles' => MuscoliDegliEsercizi::secondariDi($nome),

                        'equipment' => $attrezzo,
                        'met' => $met,
                        'is_custom' => false,
                    ],
                );
            }
        });

        $this->command?->info('Libreria esercizi: '.count(self::ESERCIZI).' esercizi della piattaforma.');
    }
}
