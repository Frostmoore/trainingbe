<?php

declare(strict_types=1);

namespace App\Support\Training;

/**
 * I muscoli secondari di ogni esercizio del catalogo — 3b-A.3, 23/08/2026.
 *
 * ══ 🚨 UN POSTO SOLO, E IL MOTIVO NON E' L'ELEGANZA ═══════════════════════
 *
 * Il catalogo nasce da **due strade**: `ExerciseLibrarySeeder` su un ambiente
 * nuovo, e la migrazione `2026_08_23_210000` sulle righe che esistevano gia'.
 *
 * ⛔ **Al primo tentativo la mappa stava solo nella migrazione**, ed era un
 * difetto vero: su un'installazione pulita le migrazioni girano su un database
 * senza esercizi, poi il seeder li crea — **senza secondari**. Il risultato
 * sarebbe stato «funziona in staging e non su una macchina nuova», che e' il
 * genere di cosa che si scopre mesi dopo.
 *
 * 💡 Qui la mappa e' una sola, e tutte e due le strade la leggono.
 *
 * ── ⚠️ Sulla migrazione che legge codice dell'applicazione ────────────────
 *
 * 🚨 Una migrazione di norma **congela** i suoi dati, perche' deve poter girare
 * identica fra un anno. Questa no, ed e' una scelta: duplicare centoventuno
 * righe vorrebbe dire due verita' che divergono al primo esercizio corretto.
 *
 * ⛔ Se un giorno questa classe sparisse, quella migrazione va **svuotata**, non
 * lasciata a puntare nel vuoto: sulle righe gia' sistemate non serve piu' a
 * niente.
 *
 * ── 💡 Perche' sono scritti a mano ────────────────────────────────────────
 *
 * 🚨 **Nessuna regola sul nome.** Un «rematore» e un «rematore presa inversa»
 * reclutano i bicipiti in modo diverso, e una regola automatica avrebbe dato a
 * tutti gli stessi secondari — cioe' un dato che sembra ricco ed e' finto.
 *
 * ⛔ **Un elenco vuoto e' una risposta**, non una casella da riempire: «Leg
 * extension» il quadricipite lo isola davvero.
 */
final class MuscoliSecondari
{
    private const MAPPA = [
        // ── Addome ────────────────────────────────────────────────────────
        'Crunch' => [],
        'Crunch ai cavi' => [],
        'Crunch inverso' => [],
        'Hollow hold' => ['quads'],
        'Mountain climber' => ['shoulders', 'quads'],
        'Plank' => ['shoulders', 'glutes'],
        'Plank laterale' => ['shoulders', 'glutes'],
        'Ruota per addominali' => ['shoulders', 'back'],
        'Russian twist' => [],
        'Sollevamento gambe' => ['quads'],
        'Sollevamento gambe alla sbarra' => ['forearms', 'quads'],

        // ── Schiena ───────────────────────────────────────────────────────
        'Good morning' => ['hamstrings', 'glutes'],
        'Iperestensioni' => ['glutes', 'hamstrings'],
        'Lat machine' => ['biceps', 'forearms'],
        'Lat machine presa inversa' => ['biceps'],
        'Lat machine presa stretta' => ['biceps'],
        'Pulldown a braccia tese' => ['triceps'],
        'Pulley basso' => ['biceps', 'forearms'],
        'Rematore a un braccio ai cavi' => ['biceps', 'forearms'],
        'Rematore con bilanciere' => ['biceps', 'forearms'],
        'Rematore con manubrio' => ['biceps', 'forearms'],
        'Rematore presa inversa' => ['biceps'],
        'Rematore T-bar' => ['biceps', 'forearms'],
        'Stacco da terra' => ['glutes', 'hamstrings', 'quads', 'forearms'],
        'Stacco rumeno' => ['hamstrings', 'glutes'],
        'Trazioni' => ['biceps', 'forearms'],
        'Trazioni assistite' => ['biceps', 'forearms'],

        // ── Bicipiti ──────────────────────────────────────────────────────
        'Chin up' => ['back', 'forearms'],
        'Curl a martello' => ['forearms'],
        'Curl ai cavi' => ['forearms'],
        'Curl alla panca Scott' => ['forearms'],
        'Curl bicipiti' => ['forearms'],
        'Curl con bilanciere' => ['forearms'],
        'Curl concentrato' => ['forearms'],
        'Curl inverso' => ['forearms'],

        // ── Polpacci ──────────────────────────────────────────────────────
        'Calf a una gamba' => [],
        'Calf alla pressa' => [],
        'Calf da seduto' => [],
        'Calf in piedi' => [],
        'Salti sul posto' => ['quads', 'glutes'],

        // ── Cardio: la natura e' cardio, i muscoli sono questi ────────────
        'Air bike' => ['quads', 'back', 'shoulders'],
        'Camminata in salita' => ['quads', 'glutes', 'calves'],
        'Corda' => ['calves', 'shoulders'],
        'Corsa' => ['quads', 'hamstrings', 'calves', 'glutes'],
        'Cyclette' => ['quads', 'glutes', 'calves'],
        'Ellittica' => ['quads', 'glutes', 'hamstrings'],
        'Scala' => ['quads', 'glutes', 'calves'],
        'Sci indoor' => ['quads', 'glutes', 'back'],
        'Tapis roulant' => ['quads', 'hamstrings', 'calves', 'glutes'],
        'Vogatore' => ['back', 'biceps', 'quads', 'shoulders'],

        // ── Petto ─────────────────────────────────────────────────────────
        'Chest press' => ['triceps', 'shoulders'],
        'Croci ai cavi' => ['shoulders'],
        'Croci ai cavi alti' => ['shoulders'],
        'Croci su panca' => ['shoulders'],
        'Dips' => ['triceps', 'shoulders'],
        'Panca declinata' => ['triceps', 'shoulders'],
        'Panca inclinata' => ['shoulders', 'triceps'],
        'Panca inclinata con manubri' => ['shoulders', 'triceps'],
        'Panca piana' => ['triceps', 'shoulders'],
        'Panca piana con manubri' => ['triceps', 'shoulders'],
        'Pectoral machine' => ['shoulders'],
        'Piegamenti' => ['triceps', 'shoulders', 'abs'],
        'Piegamenti su rialzo' => ['triceps', 'shoulders'],
        'Pullover' => ['back', 'triceps'],

        // ── Avambracci ────────────────────────────────────────────────────
        'Camminata del contadino' => ['abs', 'back', 'shoulders'],
        'Curl ai polsi' => [],
        'Curl ai polsi inverso' => [],
        'Rullo per avambracci' => [],
        'Sospensione alla sbarra' => ['back', 'shoulders'],

        // ── Total body ────────────────────────────────────────────────────
        'Battle rope' => ['shoulders', 'abs', 'back'],
        'Burpees' => ['chest', 'quads', 'shoulders', 'abs'],
        'Girata' => ['back', 'quads', 'shoulders', 'glutes'],
        'Kettlebell swing' => ['glutes', 'hamstrings', 'back', 'abs'],
        'Slam ball' => ['abs', 'shoulders', 'back'],
        'Strappo' => ['shoulders', 'back', 'quads', 'glutes'],
        'Thruster' => ['quads', 'shoulders', 'glutes', 'triceps'],

        // ── Glutei ────────────────────────────────────────────────────────
        'Abduzioni' => [],
        'Abduzioni in piedi ai cavi' => [],
        'Affondi laterali' => ['quads', 'hamstrings'],
        'Hip thrust' => ['hamstrings'],
        'Hip thrust a una gamba' => ['hamstrings'],
        'Kickback ai cavi' => ['hamstrings'],
        'Ponte glutei' => ['hamstrings'],

        // ── Femorali ──────────────────────────────────────────────────────
        'Glute ham raise' => ['glutes', 'calves'],
        'Leg curl' => ['calves'],
        'Leg curl in piedi' => ['calves'],
        'Leg curl sdraiato' => ['calves'],
        'Nordic curl' => ['glutes'],
        'Stacco a gambe tese' => ['glutes', 'back'],

        // ── Quadricipiti ──────────────────────────────────────────────────
        'Affondi' => ['glutes', 'hamstrings'],
        'Affondi camminati' => ['glutes', 'hamstrings'],
        'Goblet squat' => ['glutes', 'abs'],
        'Hack squat' => ['glutes'],
        'Leg extension' => [],
        'Pressa' => ['glutes', 'hamstrings'],
        'Pressa orizzontale' => ['glutes'],
        'Sissy squat' => [],
        'Squat' => ['glutes', 'hamstrings', 'abs'],
        'Squat bulgaro' => ['glutes', 'hamstrings'],
        'Squat frontale' => ['glutes', 'abs'],
        'Step up' => ['glutes', 'hamstrings'],

        // ── Spalle ────────────────────────────────────────────────────────
        'Alzate frontali' => [],
        'Alzate laterali' => [],
        'Alzate laterali ai cavi' => [],
        'Alzate posteriori' => ['back'],
        'Arnold press' => ['triceps'],
        'Face pull' => ['back'],
        'Lento avanti' => ['triceps'],
        'Military press' => ['triceps', 'abs'],
        'Scrollate' => ['back', 'forearms'],
        'Shoulder press' => ['triceps'],
        'Shoulder press a macchina' => ['triceps'],
        'Tirate al mento' => ['back', 'biceps'],

        // ── Tricipiti ─────────────────────────────────────────────────────
        'Dips alle parallele' => ['chest', 'shoulders'],
        'Estensioni ai cavi sopra la testa' => [],
        'Estensioni sopra la testa' => [],
        'French press' => [],
        'Kickback tricipiti' => [],
        'Panca stretta' => ['chest', 'shoulders'],
        'Push down con corda' => [],
        'Push down tricipiti' => [],
    ];

    private function __construct() {}

    /**
     * I secondari di un esercizio del catalogo.
     *
     * ⚠️ Torna un elenco **vuoto** anche per un nome che non conosce, e non
     * `null`: chi chiama sta scrivendo una riga del catalogo, e un catalogo con
     * dei buchi e' precisamente la cosa che questa fase esiste per togliere.
     *
     * @return list<string>
     */
    public static function di(string $nome): array
    {
        return self::MAPPA[$nome] ?? [];
    }

    /** @return array<string, list<string>> */
    public static function tutti(): array
    {
        return self::MAPPA;
    }
}
