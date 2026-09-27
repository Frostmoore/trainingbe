<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Filament\Gym\Pages\IlTuoPiano;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\LaSospensioneAutomatica;
use App\Services\Billing\Stripe\PianiInVendita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔒 **Prima si paga il mese, poi si cambia piano** — 27/09/2026.
 *
 * ══ 📌 LA DECISIONE ═══════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«quando clicchi su rinnova ora, deve solo farmi pagare, non
 * mi deve permettere di cambiare il piano. Quello devo poterlo fare solo dopo che
 * il pagamento è stato effettuato per il mese corrente»*.
 *
 * ⚖️ **La ragione commerciale**: chi non ha saldato il mese non scappa su un piano
 * più piccolo prima di pagare, e non ne compra uno più grande lasciando indietro il
 * dovuto.
 *
 * ══ 🔑 TRE STRADE PER NON AVER PAGATO, UNA SOLA DOMANDA ═══════════════════
 *
 * | Come manca l'incasso | Cosa si guarda |
 * |---|---|
 * | il canone è **scaduto** | nessun abbonamento vivo |
 * | il canone è stato **rifiutato** | `plan_subscriptions.pagamento_fallito_il` |
 * | la fattura del **consumo** è insolvente | `addebiti_mensili.fallito_il` |
 *
 * 🚨 Il terzo caso è quello che fa la differenza: là l'abbonamento è **vivo**,
 * `ends_at` è nel futuro, e Stripe sta ancora riprovando. ⛔ *«Il servizio è
 * acceso»* e *«il mese è pagato»* sono due cose diverse, e qui conta la seconda.
 *
 * 💡 La domanda unica è `LaSospensioneAutomatica::staPagando()`.
 */
class PrimaSiPagaPoiSiCambiaPianoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /** ✅ Il controllo: chi è in regola può cambiare piano. */
    #[Test]
    public function chi_ha_pagato_puo_cambiare_piano(): void
    {
        [$palestra, $capo] = $this->unaPalestra('Regolare');

        self::assertFalse(
            $this->inSospeso($capo),
            'Il presupposto del test: con tutto pagato il cambio è aperto.',
        );
    }

    /**
     * 🚨 **Il canone rifiutato blocca il cambio — anche se il servizio è ACCESO.**
     *
     * ⛔ È il caso che `haUnAbbonamentoVivo()` da solo non vede: Stripe ha detto no,
     * `Cassa` ha segnato `pagamento_fallito_il` e **non ha spento niente** — ed è
     * voluto, perché spegnere al primo rifiuto punirebbe chi ha cambiato carta di
     * venerdì. 💡 Ma il mese non è pagato, quindi il piano non si cambia.
     */
    #[Test]
    public function un_canone_rifiutato_blocca_il_cambio_pur_col_servizio_acceso(): void
    {
        [$palestra, $capo] = $this->unaPalestra('Carta Rifiutata');

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->update(['pagamento_fallito_il' => now()->subDays(2)]);

        self::assertTrue(
            $this->inSospeso($capo),
            '🚨 Il cambio di piano è aperto a chi non ha saldato il mese: '
            .'potrebbe scappare su un piano più piccolo prima di pagare.',
        );
    }

    /**
     * 🔓 **E appena l'incasso va a buon fine, il cambio si riapre.**
     *
     * ⚠️ È la metà che si dimentica: una regola che blocca e non sblocca diventa un
     * cliente che telefona. 💡 `Cassa::rinnovo()` azzera `pagamento_fallito_il`, ed
     * è quello che questo test riproduce.
     */
    #[Test]
    public function incassato_il_cambio_si_riapre(): void
    {
        [$palestra, $capo] = $this->unaPalestra('Poi Pagata');

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->update(['pagamento_fallito_il' => now()->subDays(2)]);

        self::assertTrue($this->inSospeso($capo), 'Il presupposto: prima è bloccato.');

        /* 💳 Pagato: è quello che fa `Cassa::rinnovo()`. */
        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->update(['pagamento_fallito_il' => null]);

        self::assertFalse(
            $this->inSospeso($capo->fresh()),
            '⛔ Ha pagato e il cambio di piano resta chiuso.',
        );
    }

    /**
     * 🧱 **E il cancello vero è sul SERVER, non nei pulsanti.**
     *
     * 🚨 `scegli()` è un metodo Livewire: chi apre la console del browser lo chiama
     * con qualunque codice di piano. ⛔ Spegnere i pulsanti **non è un controllo** —
     * è la stessa lezione della `v36.29.0` al rovescio: là c'era il controllo e non
     * la porta, qui ci sarebbe la porta senza il controllo.
     *
     * 💡 Il test guarda che il piano **non cambi** davvero, non che compaia un
     * messaggio: un avviso si può anche scrivere sbagliato, un abbonamento no.
     */
    #[Test]
    public function chiamare_scegli_a_mano_non_cambia_il_piano(): void
    {
        [$palestra, $capo] = $this->unaPalestra('Da Console');

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->update(['pagamento_fallito_il' => now()->subDays(2)]);

        $prima = $this->pianoInCorso($palestra);

        /*
         * 🚨 **Si guarda l'AVVISO, e non solo il piano immutato.**
         *
         * ⛔ Il primo giro di questo test asseriva solo che il piano non cambiasse, e
         * **restava verde anche togliendo il cancello**: senza Stripe nei test un
         * cambio di piano non va a buon fine comunque, quindi il piano resta com'era
         * **per un motivo che non è il nostro**.
         *
         * 💡 Trovato provando a falsificare. L'avviso di rifiuto invece lo manda
         * **solo** il cancello: se non arriva, `scegli()` ha tirato avanti.
         *
         * 🔑 Restano entrambe le asserzioni: la prima prova che il cancello sia
         * **passato**, la seconda che il risultato sia quello voluto.
         */
        Livewire::actingAs($capo->fresh())
            ->test(IlTuoPiano::class)
            ->call('scegli', Plan::TRAINER_PRO)
            ->assertNotified('Prima sistemiamo il pagamento');

        self::assertSame(
            $prima,
            $this->pianoInCorso($palestra),
            '🚨 Chiamando `scegli` a mano il piano è cambiato pur col mese non pagato.',
        );
    }

    // ───────────────────────── aiuti ─────────────────────────

    /**
     * 🔎 La domanda come la vede l'interfaccia: dal listino, non dal servizio.
     *
     * ⚠️ **Apposta.** Il servizio lo provano altri test; qui interessa che la
     * risposta **arrivi fino al listino**, che è quello che la pagina legge. ⛔ Un
     * test sul solo servizio passerebbe anche con il campo mai aggiunto.
     */
    private function inSospeso(User $chi): bool
    {
        $listino = app(PianiInVendita::class)->listinoPer($chi);

        self::assertArrayHasKey(
            'pagamento_in_sospeso',
            $listino,
            '⛔ Il listino non dice se il pagamento è in sospeso: la pagina non può saperlo.',
        );

        return (bool) $listino['pagamento_in_sospeso'];
    }

    private function pianoInCorso(Tenant $spazio): ?string
    {
        return PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $spazio->getKey())
            ->attivi()
            ->orderByDesc('starts_at')
            ->first()?->plan?->code;
    }

    /** @return array{0: Tenant, 1: User} */
    private function unaPalestra(string $nome): array
    {
        $slug = str_replace(' ', '-', mb_strtolower($nome));

        $palestra = $this->creaPalestra($nome, $slug);
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@'.$slug.'.test');

        /* ⚠️ Il presupposto: un abbonamento vivo e incassato. */
        if (! app(LaSospensioneAutomatica::class)->eraUnClientePagante($palestra)) {
            $piano = Plan::query()->where('code', Plan::GYM)->firstOrFail();

            PlanSubscription::withoutGlobalScopes()->create([
                'tenant_id' => $palestra->getKey(),
                'plan_id' => $piano->getKey(),
                'starts_at' => now()->subMonth(),
            ]);
        }

        return [$palestra, $capo];
    }
}
