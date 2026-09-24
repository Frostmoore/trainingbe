<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\AllenareInUnaPalestra;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * 🏋️ **I due trainer che non sono di una palestra** — 24/09/2026.
 *
 * ══ 🚨 PERCHE' ESISTE: I PANNELLI DEI TRAINER NON SI POTEVANO GUARDARE ══════
 *
 * `DemoGymSeeder` crea palestre, e una palestra ha un titolare e un trainer
 * **dipendente**. ⛔ Ma i pannelli fatti fra il 23 e il 24/09 sono gli **altri
 * due**: il trainer indipendente, e chi è tutti e due insieme. 🚨 Per provarli
 * ho creato i dati **a mano**, con tinker — cioè su questa macchina e basta.
 *
 * ⚠️ Un ambiente di prova che vive solo su un computer è un ambiente che, il
 * giorno che il progetto si riprende altrove, **non esiste**: si riaprirebbe il
 * pannello e si vedrebbero tre schermate vuote, senza sapere se è un difetto o
 * se mancano i dati.
 *
 * ══ 💡 COSA CREA, E COSA DIMOSTRA OGNI PEZZO ════════════════════════════════
 *
 * | Chi | Cappello | Serve a vedere |
 * |---|---|---|
 * | **Giulia Bianchi** | solo trainer indipendente | il pannello di chi comanda a casa sua e non allena in nessuna palestra |
 * | **Luca Ferrari** | ⭐ indipendente **e** trainer di Palestra Demo | il caso che il committente ha chiesto il 24/09: *«uno che sia SIA trainer di una palestra CHE free trainer»* |
 *
 * 🚨 **Gli allievi di Luca sono tre, e sono tre casi diversi di proposito** —
 * sono le tre regole del committente sui rapporti fra trainer e allievi:
 *
 * | Allievo | Dove abita | Cosa dimostra |
 * |---|---|---|
 * | **Sara Allieva** | nasce **nello spazio di Luca** | chi non aveva un account nasce dal trainer che lo invita |
 * | **Marco Iscritto** (Fitness Prova) | resta nella **sua** palestra | ⚠️ Luca **non** allena lì: paga il posto normalmente, e nessuno gli chiede niente |
 * | **Giulia Iscritta** (Palestra Demo) | resta nella **sua** palestra | 🚨 Luca allena **proprio lì**: nasce la **sovrapposizione**, e gli viene chiesto se tiene il posto o lo libera |
 *
 * ⛔ **Nessuna riga scritta a mano nel database.** Ogni rapporto nasce dai
 * servizi veri — `InvitiDelTrainer::invita()` e `riscatta()` /
 * `riscattaPerChiEsiste()`, `AllenareInUnaPalestra::assumi()` — perché dei dati
 * fabbricati con `insert` dimostrerebbero solo che so scrivere una `insert`: le
 * sovrapposizioni, i posti occupati e i ruoli per spazio nascono **da quelle
 * classi**, e se una si rompe questo seeder deve rompersi con lei.
 *
 * ══ ⚠️ IDEMPOTENTE PER ESCLUSIONE, NON PER SOVRASCRITTURA ═══════════════════
 *
 * 🚨 Se il trainer c'è già, **non si tocca niente**: non si aggiungono allievi e
 * non si mandano inviti. ⛔ Rilanciarlo non deve consumare i posti del piano —
 * che sono cinque, e tre inviti in più li esaurirebbero — né rifare un rapporto
 * che qualcuno ha chiuso apposta mentre provava.
 *
 * 💡 **La password arriva da `DEMO_USER_PASSWORD`**, come in `DemoGymSeeder`:
 * nel repository non deve esserci nessuna stringa che assomigli a una
 * credenziale. Quella vera di questa macchina sta in `CREDENZIALI-LOCALI.md`,
 * che non è versionato.
 *
 * ⛔ **Non gira in produzione**: la guardia è in `DatabaseSeeder`.
 */
class DemoTrainerSeeder extends Seeder
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CreaTenantPersonale $creaSpazio,
        private readonly InvitiDelTrainer $inviti,
        private readonly AllenareInUnaPalestra $assunzione,
    ) {}

    public function run(): void
    {
        $demo = Tenant::query()->where('slug', 'palestra-demo')->first();
        $prova = Tenant::query()->where('slug', 'fitness-prova')->first();

        if ($demo === null || $prova === null) {
            $this->command?->warn('Le palestre demo non ci sono: lancia prima DemoGymSeeder.');

            return;
        }

        $this->soloIndipendente();
        $this->doppioCappello($demo, $prova);
    }

    /**
     * 🧍 **Giulia**: uno spazio suo, un piano da trainer, due allievi.
     *
     * ⚠️ Uno dei due abita in una palestra dove lei **non** allena: è il caso
     * che il 24/09 faceva contare «1 allievo» invece di 2 nel cruscotto, ed è la
     * ragione per cui sta qui dentro invece che restare un caso di prova.
     */
    private function soloIndipendente(): void
    {
        $palestra = Tenant::query()->where('slug', 'palestra-demo')->first();

        /*
         * ⚠️ `'trainer_5'` scritto per esteso e non una costante: quel taglio
         * sta in `config/listino.php` ma in `App\Models\Plan` la costante **non
         * c'è** (ci sono `TRAINER_10`, `TRAINER_30`, `TRAINER_PRO`). 💡 Scriverla
         * qui darebbe un errore fatale in faccia a chi semina, invece di un
         * piano mancante: il listino è il posto dove vivono i codici.
         */
        $giulia = $this->trainerConSpazio('Giulia Bianchi', 'giulia@esempio.test', 'trainer_5');

        if ($giulia === null || $palestra === null) {
            return;
        }

        $this->allievoNuovo($giulia, 'Marco Rossi', 'allievo.suo@esempio.test');
        $this->allievoCheEsisteGia($giulia, 'iscritto0@palestra-demo.test');

        $this->command?->info('Giulia Bianchi (trainer indipendente) pronta.');
    }

    /**
     * ⭐ **Luca**: indipendente a casa sua **e** trainer in Palestra Demo.
     *
     * 🚨 **L'ordine conta.** Gli allievi si creano **prima** dell'assunzione:
     * così la sovrapposizione la fa nascere `AllenareInUnaPalestra::assumi()` —
     * che è la strada vera («il trainer entra dove sta l'allievo») — e il numero
     * che torna dice quante ne ha create. ⛔ Al contrario nascerebbe dall'altra
     * strada, e non proverebbe la stessa cosa.
     */
    private function doppioCappello(Tenant $demo, Tenant $prova): void
    {
        $luca = $this->trainerConSpazio('Luca Ferrari', 'luca@esempio.test', Plan::TRAINER_10);

        if ($luca === null) {
            return;
        }

        // 1. Un allievo che non aveva niente: nasce nello spazio di Luca.
        $this->allievoNuovo($luca, 'Sara Allieva', 'allieva.luca@esempio.test');

        // 2. Un iscritto di una palestra dove Luca **non** allena: resta lì, e
        //    Luca paga il suo posto senza che nessuno gli chieda niente.
        $this->allievoCheEsisteGia($luca, "iscritto0@{$prova->slug}.test");

        // 3. Un iscritto della palestra dove Luca **sta per** essere assunto.
        $this->allievoCheEsisteGia($luca, "iscritto1@{$demo->slug}.test");

        $quante = $this->assunzione->assumi(
            User::withoutGlobalScopes()->findOrFail($luca->getKey()),
            $demo,
        );

        $this->command?->info(
            "Luca Ferrari (indipendente + trainer di {$demo->name}) pronto: "
            ."{$quante} sovrapposizioni da decidere."
        );
    }

    /**
     * Il trainer con il suo spazio e un piano da trainer attivo.
     *
     * ⚠️ Il piano **serve**: senza, `puoAvereAllieviSuoi()` risponde di no e
     * nessun invito parte (il gratuito da persona non basta — committente,
     * 22/09: *«l'unica possibilità di essere free trainer è l'abbonamento
     * minimo»*).
     *
     * @return User|null `null` quando esiste già: in quel caso non si tocca niente
     */
    private function trainerConSpazio(string $nome, string $email, string $codicePiano): ?User
    {
        if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
            $this->command?->line("· {$nome} c'era già: lasciato com'è.");

            return null;
        }

        $piano = Plan::withoutGlobalScopes()->where('code', $codicePiano)->first();

        if ($piano === null) {
            $this->command?->warn("Il piano {$codicePiano} non c'è: lancia prima PlanSeeder.");

            return null;
        }

        $trainer = ($this->creaSpazio)(
            $nome,
            $email,
            ['password' => (string) env('DEMO_USER_PASSWORD', 'demo')],
            UserRole::FreeTrainer,
        );

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => $piano->getKey(),
            'starts_at' => now()->subMonth(),
        ]);

        return $trainer;
    }

    /** 👤 Un allievo che non aveva un account: nasce nello spazio del trainer. */
    private function allievoNuovo(User $trainer, string $nome, string $email): void
    {
        if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
            return;
        }

        $invito = $this->inviti->invita($trainer, $email);

        $this->inviti->riscatta(
            $invito->token,
            $nome,
            $email,
            ['password' => (string) env('DEMO_USER_PASSWORD', 'demo')],
            maggiorenne: true,
            condizioni: true,
        );
    }

    /**
     * 👥 Un allievo che **ha già un account** in una palestra: resta dov'è.
     *
     * ⚠️ `affianco: true` perché ce l'ha già, un trainer — quello della sua
     * palestra. 📌 Regola del committente (24/09): *«un allievo può avere
     * infiniti trainer ma essere membro di una sola palestra, e tutti i trainer
     * pagano lo slot»*.
     */
    private function allievoCheEsisteGia(User $trainer, string $email): void
    {
        $allievo = User::withoutGlobalScopes()->where('email', $email)->first();

        if ($allievo === null) {
            $this->command?->warn("L'iscritto {$email} non c'è: saltato.");

            return;
        }

        $gia = $trainer->assignedMembers()->where('member_id', $allievo->getKey())->exists();

        if ($gia) {
            return;
        }

        $invito = $this->inviti->invita($trainer);

        /*
         * 🚨 Nel contesto della **palestra dell'allievo**, non in quello del
         * trainer: `riscattaPerChiEsiste()` legge e riscrive righe che vivono
         * là, e con il contesto sbagliato `TenantScope` ne nasconde metà.
         */
        $this->context->runAs(
            $allievo->tenant ?? $trainer->tenant,
            fn () => $this->inviti->riscattaPerChiEsiste($invito->token, $allievo, affianco: true),
        );
    }
}
