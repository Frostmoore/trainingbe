<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Models\NutritionPlan;
use App\Models\WorkoutPlan;
use Illuminate\Contracts\Console\Kernel;
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
        $comandi = array_keys(app(Kernel::class)->all());

        foreach (['training:seed-plans', 'scheda:dettata'] as $vietato) {
            self::assertNotContains(
                $vietato,
                $comandi,
                "⛔ Il comando {$vietato} è tornato: creava schede intestate a un iscritto.",
            );
        }
    }

    /**
     * 🚨 **E NESSUNA SCHERMATA DELLE SCHEDE CHIEDE UN ELENCO DI PERSONE.**
     *
     * ══ ⛔ PERCHE' I TRE TEST QUI SOPRA NON BASTAVANO ══════════════════════
     *
     * Il 27/09/2026 è saltato fuori `PlanImportResource::iscritti()`, con il
     * commento *«A chi si può assegnare la scheda importata»*. ⛔ **Non lo
     * chiamava più nessuno**, ma c'era — e chi lo leggeva (io) ricostruiva
     * un'architettura che non esiste: *«il trainer manda le schede dal server»*.
     *
     * 📌 Il committente, per l'ennesima volta: *«IL TRAINER NON MANDA SCHEDE DAL
     * SERVER»*.
     *
     * 🚨 **I tre test qui sopra non potevano vederlo**: controllano una **lista
     * di nomi noti** — `assignTo`, due comandi. ⛔ Un metodo chiamato in un altro
     * modo passa in mezzo.
     *
     * ── 🔑 Perché questo invece lo vede ────────────────────────────────────
     *
     * Non guarda i **nomi**, guarda la **forma**: per assegnare una scheda a una
     * persona serve, prima di tutto, **un elenco di persone da cui sceglierla**.
     * 💡 In questo pannello quell'elenco lo dà `ChiPossoSeguire`, e su una
     * schermata di schede non ci deve arrivare.
     *
     * ⚖️ È una guardia **sulla forma del reato, non sul nome del colpevole** —
     * ed è il motivo per cui regge anche al prossimo residuo, che si chiamerà
     * diversamente.
     *
     * ⚠️ **Come funziona davvero**: il trainer scrive i **modelli** nel pannello
     * — esercizi, serie, ripetizioni, che non parlano di nessuno — e poi manda la
     * scheda **dalla chat, dall'app**, dentro una busta cifrata. 🔑 Le chiavi
     * stanno solo sui telefoni: il server trasporta e **non capisce**.
     */
    #[Test]
    public function nessuna_schermata_delle_schede_chiede_un_elenco_di_persone(): void
    {
        $cartelle = [
            app_path('Filament/Gym/Resources/PlanImports'),
            app_path('Filament/Gym/Resources/WorkoutPlans'),
            app_path('Filament/Gym/Resources/NutritionPlans'),
        ];

        $guardati = 0;

        foreach ($cartelle as $cartella) {
            if (! is_dir($cartella)) {
                continue;
            }

            $file = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cartella)
            );

            foreach ($file as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }

                $guardati++;

                $codice = (string) file_get_contents($f->getPathname());

                /*
                 * ⚠️ Si cerca la **chiamata** (`::`), non il nome nudo: nei
                 * commenti la classe si nomina per spiegare perché NON si usa, e
                 * un test che vietasse anche quello costringerebbe a cancellare
                 * la spiegazione — cioè la cosa che serve di più.
                 */
                foreach (['ChiPossoSeguire::', 'perIlMenu('] as $vietato) {
                    self::assertStringNotContainsString(
                        $vietato,
                        $codice,
                        "🚨 {$f->getFilename()} chiede un elenco di persone su una schermata di "
                        .'schede. ⛔ Da lì si assegna una scheda a qualcuno, e il legame fra una '
                        .'persona e un programma NON deve restare scritto sul server. '
                        .'💡 Il trainer manda la scheda dalla chat, cifrata: il server trasporta '
                        .'e non capisce.',
                    );
                }
            }
        }

        self::assertGreaterThan(
            0,
            $guardati,
            '⛔ Nessun file guardato: le cartelle sono state rinominate e questo test '
            .'non protegge più niente.',
        );
    }
}
