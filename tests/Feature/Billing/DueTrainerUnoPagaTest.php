<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Billing\AbbonamentiDegliIscritti;
use App\Services\Billing\PianoAttivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔑 **Due trainer, uno paga e uno no** — 26/09/2026.
 *
 * 📌 Il committente: *«fai un test con un utente iscritto a due trainer, uno
 * pagante l'altro sospeso, per accertarti che l'utente abbia l'abbonamento
 * anche se uno solo dei trainer paga»*.
 *
 * 🔑 È la stessa regola della palestra sospesa, applicata ai trainer: **chi
 * paga ti regala delle funzioni, non ti affitta l'identità**. Se qualcuno
 * smette di pagare, non deve poter togliere quello che un altro sta ancora
 * pagando.
 *
 * ══ ⚠️ QUESTO FILE DOCUMENTA UN DIFETTO APERTO ════════════════════════════
 *
 * 🚨 `PianoAttivo::per()` risolve i diritti da **`$utente->tenant_id`**, che è
 * **uno solo**. ⛔ Quindi i diritti non vengono dall'insieme di chi paga per
 * te: vengono dall'unico spazio a cui punti. Se quello smette di pagare, perdi
 * — anche se l'altro trainer paga regolarmente.
 *
 * 💡 È la **stessa radice** del furto dell'account con la palestra sospesa:
 * `tenant_id` confonde «di chi sono i dati» con «chi paga». La decisione del
 * 26/09 ha protetto l'**accesso**; questo file dice che i **diritti** hanno
 * ancora lo stesso difetto.
 */
class DueTrainerUnoPagaTest extends TestCase
{
    use RefreshDatabase;
    use CreaAmbiente;

    /**
     * 🎯 **La prova che conta.**
     *
     * Due trainer indipendenti, ognuno nel proprio spazio. La stessa persona è
     * seguita da tutti e due (`affianco`). ⚠️ Uno ha l'abbonamento attivo,
     * l'altro no.
     *
     * 🔑 L'atteso: la persona **è abbonata**, perché qualcuno sta pagando
     * per lei.
     */
    #[Test]
    public function basta_che_uno_dei_due_trainer_paghi(): void
    {
        [$chePaga, $iscritto] = $this->dueTrainerEUnIscritto();

        $this->assertTrue(
            app(PianoAttivo::class)->eAbbonato($iscritto->fresh()),
            'Un trainer che smette di pagare non deve poter togliere alla '
            .'persona quello che l\'altro trainer sta ancora pagando. '
            .'Chi paga: '.($chePaga->name ?? '?'),
        );
    }

    /**
     * ⚠️ E il contrario: se **nessuno** paga, non è abbonata. 💡 Serve a dire
     * che il test qui sopra non passa per caso.
     */
    #[Test]
    public function se_non_paga_nessuno_non_e_abbonata(): void
    {
        [, $iscritto] = $this->dueTrainerEUnIscritto(nessunoPaga: true);

        $this->assertFalse(
            app(PianoAttivo::class)->eAbbonato($iscritto->fresh()),
            'Senza nessuno che paga non ci sono diritti da dare.',
        );
    }

    /**
     * Due trainer in due spazi diversi, e una persona seguita da entrambi.
     *
     * @return array{0: User, 1: User}
     */
    private function dueTrainerEUnIscritto(bool $nessunoPaga = false): array
    {
        $spazioA = $this->creaPalestra('Trainer Anna', 'trainer-anna');
        $spazioB = $this->creaPalestra('Trainer Bruno', 'trainer-bruno');

        /*
         * 🚨 **`creaPalestra()` regala un abbonamento da palestra attivo.**
         * ⛔ Lasciandolo, «nessuno paga» sarebbe falso e questi test
         * passerebbero **per il motivo sbagliato** — è successo davvero al
         * primo tentativo, e l'ha scoperto il test di controllo.
         * 💡 Qui i due spazi sono di **trainer**, non palestre: l'unico
         * abbonamento che conta è quello che mette `conPianoDaTrainer()`.
         */
        PlanSubscription::withoutGlobalScopes()
            ->whereIn('tenant_id', [$spazioA->getKey(), $spazioB->getKey()])
            ->delete();

        $anna = $this->creaUtente($spazioA, UserRole::FreeTrainer, 'anna@prova.test');
        $bruno = $this->creaUtente($spazioB, UserRole::FreeTrainer, 'bruno@prova.test');

        // 💰 Anna paga (a meno che il test non voglia nessun pagante).
        if (! $nessunoPaga) {
            $this->conPianoDaTrainer($anna, Plan::TRAINER_30);
        }

        /*
         * 🚨 L'iscritto vive nello spazio di **Bruno**, quello che NON paga.
         * ⚠️ È il caso che smaschera il difetto: se i diritti venissero
         * dall'insieme di chi paga, Anna basterebbe.
         */
        $iscritto = $this->creaUtente($spazioB, UserRole::FreeUser, 'iscritto@prova.test');

        // 🔗 Seguito da tutti e due — è il caso `affianco`.
        $anna->assignedMembers()->attach($iscritto->getKey(), [
            'tenant_id' => $spazioA->getKey(),
            'assigned_at' => now(),
        ]);
        $bruno->assignedMembers()->attach($iscritto->getKey(), [
            'tenant_id' => $spazioB->getKey(),
            'assigned_at' => now(),
        ]);

        return [$anna, $iscritto];
    }

    /**
     * 🚨 **Paga il SECONDO trainer, non il primo.**
     *
     * ⛔ Serve a dire che non conta l'ordine delle righe: con un `first()`
     * al posto della scelta del migliore, questo caso passerebbe o no a
     * seconda di come il database restituisce gli abbonamenti — cioè un
     * difetto **a intermittenza**, il peggiore da cercare.
     */
    #[Test]
    public function non_conta_quale_dei_due_paga(): void
    {
        $spazioA = $this->creaPalestra('Trainer Anna', 'trainer-anna');
        $spazioB = $this->creaPalestra('Trainer Bruno', 'trainer-bruno');

        PlanSubscription::withoutGlobalScopes()
            ->whereIn('tenant_id', [$spazioA->getKey(), $spazioB->getKey()])
            ->delete();

        $anna = $this->creaUtente($spazioA, UserRole::FreeTrainer, 'anna2@prova.test');
        $bruno = $this->creaUtente($spazioB, UserRole::FreeTrainer, 'bruno2@prova.test');

        // 💰 Paga BRUNO, che è anche lo spazio dell'iscritto.
        $this->conPianoDaTrainer($bruno, Plan::TRAINER_30);

        $iscritto = $this->creaUtente($spazioA, UserRole::FreeUser, 'iscritto2@prova.test');

        $anna->assignedMembers()->attach($iscritto->getKey(), [
            'tenant_id' => $spazioA->getKey(), 'assigned_at' => now(),
        ]);
        $bruno->assignedMembers()->attach($iscritto->getKey(), [
            'tenant_id' => $spazioB->getKey(), 'assigned_at' => now(),
        ]);

        $this->assertTrue(
            app(PianoAttivo::class)->eAbbonato($iscritto->fresh()),
            'Paga il trainer che NON è lo spazio di casa: deve valere lo stesso.',
        );
    }

    /**
     * 🎯 **La palestra ti toglie l'abbonamento, ma un trainer paga.**
     *
     * 📌 È il caso che prova la scelta più delicata di F1: `eTolto` si valuta
     * **per ciascun pagante**, non una volta sola sul proprio spazio.
     *
     * ⛔ Valutandolo prima, come faceva il codice vecchio, chi è stato tolto
     * dalla palestra ricadeva sul gratuito **anche avendo un trainer che
     * paga** — cioè lo stesso furto, in piccolo: qualcuno che smette di
     * pagare porta via quello che un altro sta ancora pagando.
     */
    #[Test]
    public function tolto_dalla_palestra_ma_un_trainer_paga_resta_abbonato(): void
    {
        $palestra = $this->creaPalestra('Palestra', 'palestra');
        $spazioTrainer = $this->creaPalestra('Trainer Carla', 'trainer-carla');

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $spazioTrainer->getKey())
            ->delete();

        $carla = $this->creaUtente($spazioTrainer, UserRole::FreeTrainer, 'carla@prova.test');
        $this->conPianoDaTrainer($carla, Plan::TRAINER_30);

        // 🏠 L'iscritto sta nella palestra, che paga.
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'tolto@prova.test');

        $carla->assignedMembers()->attach($iscritto->getKey(), [
            'tenant_id' => $spazioTrainer->getKey(),
            'assigned_at' => now(),
        ]);

        // ✂️ La palestra gli toglie l'abbonamento (F5).
        app(AbbonamentiDegliIscritti::class)->togli($iscritto);

        $this->assertTrue(
            app(PianoAttivo::class)->eAbbonato($iscritto->fresh()),
            'La palestra gliel\'ha tolto, ma Carla lo sta pagando: '
            .'chi smette di pagare non porta via quello che paga un altro.',
        );
    }

    /**
     * ⚠️ E il contrario, perché il test qui sopra non passi per caso: se la
     * palestra toglie ed **è l'unica** a pagare, si ricade sul gratuito.
     */
    #[Test]
    public function tolto_dalla_palestra_e_senza_trainer_ricade_sul_gratuito(): void
    {
        $palestra = $this->creaPalestra('Palestra Sola', 'palestra-sola');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'solo@prova.test');

        app(AbbonamentiDegliIscritti::class)->togli($iscritto);

        $this->assertFalse(
            app(PianoAttivo::class)->eAbbonato($iscritto->fresh()),
            'Nessun altro paga: il tasto «togli l\'abbonamento» deve funzionare.',
        );
    }
}
