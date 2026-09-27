<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Models\NutritionPlan;
use App\Models\WorkoutPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 🔒 **Sul server nessuno sa chi ha cosa** — 27/09/2026.
 *
 * ══ 📌 LA SPECIFICA, CON LE PAROLE DEL COMMITTENTE ════════════════════════
 *
 * > *«Le schede degli utenti vivono sul telefono degli utenti e NESSUNO LE PUÒ
 * > VEDERE. Le schede che IL TRAINER SVILUPPA sono sia sul server che sul
 * > telefono del trainer (perché lui le deve poter inviare agli utenti). […]
 * > Sul server, NULLA DI TUTTO CIÒ È NOMINATIVO […] una volta che sono state
 * > inviate, NESSUNO può sapere dove sono andate, e nessuno deve poter vedere
 * > che un determinato allievo ha una determinata scheda.»*
 *
 * ══ 🚨 PERCHE' QUESTO FILE ESISTE ═════════════════════════════════════════
 *
 * ⚠️ Il 27/09 il sistema **rispettava già** questa regola — nessuna riga aveva
 * un destinatario — ⛔ ma solo **per convenzione**: la macchina per violarla
 * era ancora lì (`WorkoutPlan::assignTo()`, `NutritionPlan::assignTo()`), e
 * bastava che qualcuno, un giorno, la trovasse comoda.
 *
 * 💡 Quella macchina è stata tolta, e questo test impedisce che torni: ⛔ non
 * verifica che «oggi non ci sono destinatari», verifica che **non se ne
 * possano creare**.
 *
 * ⚖️ L'unico riferimento ammesso è `rif_allievo`, che **non è un nome**: è un
 * promemoria libero che scrive il trainer («M.R. spalla dx»), e l'interfaccia
 * lo chiama **Riferimento** apposta per non invitare a scriverci una persona.
 */
class NienteNominativoSulServerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🎯 **Nessun piano sul server ha un destinatario.**
     *
     * 💡 Si guarda il **database**, non il codice: è l'unico posto dove la
     * regola o è vera o non lo è.
     */
    #[Test]
    public function nessun_piano_sul_server_ha_un_destinatario(): void
    {
        $this->seed();

        foreach (['workout_plans', 'nutrition_plans', 'workout_plan_imports'] as $tabella) {
            $nominativi = DB::table($tabella)->whereNotNull('member_id')->count();

            self::assertSame(
                0,
                $nominativi,
                "🚨 {$tabella} ha {$nominativi} righe con un destinatario: sul server "
                .'nessuno deve poter sapere che un allievo ha una determinata scheda.',
            );
        }
    }

    /**
     * 🔒 **E la macchina per assegnarle non esiste più.**
     *
     * 🚨 È la parte che conta: finché `assignTo()` c'era, la garanzia reggeva
     * perché nessuno lo chiamava — cioè per abitudine. ⛔ Un metodo comodo che
     * fa la cosa vietata viene chiamato, prima o poi, da qualcuno che non ha
     * letto la specifica.
     */
    #[Test]
    public function non_esiste_piu_un_modo_per_assegnare_un_piano_a_qualcuno(): void
    {
        foreach ([WorkoutPlan::class, NutritionPlan::class] as $modello) {
            self::assertFalse(
                method_exists($modello, 'assignTo'),
                "⛔ {$modello}::assignTo() è tornato: assegna un piano a una persona "
                .'e scrive il legame nominativo che la specifica vieta.',
            );
        }
    }

    /**
     * ⚠️ **E nemmeno un comando da riga di comando.**
     *
     * 🚨 I due che c'erano — `training:seed-plans` e `scheda:dettata` —
     * creavano schede intestate a un iscritto. ⛔ Erano comandi di sviluppo, e
     * proprio per questo pericolosi: nessuno li rilegge, e girano con i
     * permessi di chi li lancia.
     */
    #[Test]
    public function nessun_comando_crea_piani_intestati_a_qualcuno(): void
    {
        $comandi = array_keys(app(\Illuminate\Contracts\Console\Kernel::class)->all());

        foreach (['training:seed-plans', 'scheda:dettata'] as $vietato) {
            self::assertNotContains(
                $vietato,
                $comandi,
                "⛔ Il comando {$vietato} è tornato: creava schede intestate a un iscritto.",
            );
        }
    }
}
