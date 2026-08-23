<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Http\Controllers\Api\V1\Ai\AiController;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Il nome della scheda non arriva al modello — 23/08/2026.
 *
 * ══ 🚨 QUESTA VERIFICA E' CITATA NELL'INFORMATIVA ══════════════════════════
 *
 * `memory/informativa_privacy.md` §3.4 scrive, all'utente:
 *
 * > *«Il nome della scheda non parte, e non partira' mai. Da un nome come
 * > "riabilitazione spalla" si capirebbe qualcosa che riguarda la tua salute
 * > […]. C'e' una verifica automatica che fallisce se quel nome ricompare.»*
 *
 * ⛔ **Se questo file sparisce, quella frase diventa falsa.** Non e' un test di
 * regressione come gli altri: e' la cosa che rende vera una promessa scritta.
 *
 * ── ⚠️ Perche' e' stato riscritto ────────────────────────────────────────
 *
 * La versione precedente (`AllenamentiNelConsiglioTest`) creava una
 * `WorkoutSession` **sul server** e verificava che il suo nome non finisse nel
 * contesto. 🚨 Dalla FASE 11 quelle tabelle non esistono piu': il test era rosso
 * da settimane, e una guardia rossa non guarda niente.
 *
 * 💡 Adesso la garanzia e' **strutturale invece che comportamentale**: gli
 * allenamenti arrivano dall'app dentro `week_workouts`, e passano da una lista
 * bianca. Un campo che non e' nella lista non parte — qualunque cosa mandi
 * l'app, anche per sbaglio, anche in futuro.
 */
class IlNomeDellaSchedaNonParteTest extends TestCase
{
    /**
     * @return array<string, array<string, string>>
     */
    private function listaBianca(): array
    {
        $r = new ReflectionClass(AiController::class);

        /** @var array<string, array<string, string>> $v */
        $v = $r->getConstant('SETTIMANA');

        return $v;
    }

    #[Test]
    public function la_lista_bianca_degli_allenamenti_non_ha_un_campo_per_il_nome(): void
    {
        $campi = array_keys($this->listaBianca()['week_workouts'] ?? []);

        self::assertNotEmpty($campi, 'la lista bianca esiste ancora');

        /*
         * 🚨 Non si cerca la parola «name» e basta: si pretende che i campi
         * siano **esattamente** quei quattro. Un campo nuovo va deciso, non
         * aggiunto di sfuggita — e se un giorno servisse il nome, questo test
         * costringe a passare da qui e a rileggere l'informativa.
         */
        sort($campi);

        self::assertSame(
            ['day', 'kcal', 'minutes', 'type'],
            $campi,
            'i campi degli allenamenti che partono verso Anthropic sono cambiati: '
                .'rileggi informativa_privacy.md §3.4 prima di toccare questa lista',
        );
    }

    #[Test]
    public function nessuna_serie_della_settimana_puo_portare_un_nome(): void
    {
        // ⚠️ Vale per tutte e quattro le serie, non solo per gli allenamenti:
        // sonno, HRV e battito non devono poter trasportare testo libero.
        foreach ($this->listaBianca() as $serie => $forma) {
            foreach (array_keys($forma) as $campo) {
                self::assertNotContains(
                    $campo,
                    ['name', 'plan_name', 'title', 'nome', 'label', 'note'],
                    "«{$serie}» ha un campo «{$campo}» che puo' contenere testo libero",
                );
            }
        }
    }

    #[Test]
    public function e_i_tipi_ammessi_non_lasciano_passare_testo_lungo(): void
    {
        /*
         * 💡 `type` e' una stringa, ed e' l'unico varco possibile: ci passa
         * «Pesi», «Corsa», «Bici». ⚠️ `AiController` la tronca a 32 caratteri —
         * ma la difesa vera e' che l'app manda un'etichetta chiusa, non il nome
         * della scheda. Qui si verifica che il varco resti uno solo.
         */
        $stringhe = [];

        foreach ($this->listaBianca() as $serie => $forma) {
            foreach ($forma as $campo => $tipo) {
                if ($tipo === 'string') {
                    $stringhe[] = "{$serie}.{$campo}";
                }
            }
        }

        sort($stringhe);

        self::assertSame(
            [
                'week_hrv.day',
                'week_resting_hr.day',
                'week_sleep.day',
                'week_workouts.day',
                'week_workouts.type',
            ],
            $stringhe,
            'e comparso un campo di testo nuovo nelle serie che partono',
        );
    }
}
