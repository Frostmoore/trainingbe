<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;

/**
 * «Le cose a cui avrai accesso» — 3b-V.2.2.
 *
 * 📌 Il committente, sulla pagina dell'invito: *«un messaggio di congratulazioni,
 * le cose a cui avrà accesso e due tasti»*.
 *
 * ══ 🚨 PERCHE' LO DICE IL SERVER E NON L'APP ══════════════════════════════
 *
 * ⛔ Un elenco scritto nell'app sarebbe **una promessa che l'app fa a nome della
 * palestra**. E diventerebbe falsa il giorno che la palestra cambia piano —
 * senza che nessuno se ne accorga, perche' non e' collegata a niente.
 *
 * ⚠️ Il caso concreto: una palestra su un piano senza AI. L'app scriverebbe
 * comunque «consigli e stime con l'intelligenza artificiale», la persona
 * entrerebbe, cercherebbe quella funzione e non la troverebbe. 🚨 **Una
 * promessa non mantenuta al primo minuto** e' peggio di non averla fatta.
 *
 * 💡 Qui invece l'elenco si **costruisce dal listino**: se un giorno il piano
 * cambia, cambia anche quello che si promette, da solo.
 *
 * ══ ⚠️ COSA NON C'E' DENTRO ═══════════════════════════════════════════════
 *
 * ⛔ Niente numeri di iscritti, niente stato dell'abbonamento, niente prezzi:
 * questo finisce in un endpoint **pubblico**, e ogni campo in piu' e' un campo
 * che qualcuno usera' per sapere come vanno gli affari di quella palestra. E'
 * la stessa regola gia' scritta su `BrandingController`.
 */
class CosaOttieniInPalestra
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Le righe da mostrare, gia' in italiano e gia' leggibili.
     *
     * ⚠️ **Stringhe e non chiavi da tradurre nell'app.** Il contenuto dipende
     * dai numeri del piano («150 chiamate»), quindi una chiave costringerebbe a
     * mandare anche i parametri e a rimontare la frase di la' — cioe' a tenere
     * allineate due meta' della stessa cosa.
     *
     * @return list<array{icona: string, titolo: string, dettaglio: string}>
     */
    public function per(Tenant $palestra): array
    {
        $righe = [
            [
                /*
                 * 🚨 **Le schede per prime, e non e' un ordine casuale.** E' la
                 * cosa per cui una persona entra in una palestra: il resto e'
                 * contorno, per quanto bello.
                 */
                'icona' => 'fitness_center',
                'titolo' => 'Le schede del tuo trainer',
                'dettaglio' => 'Arrivano sul telefono e le usi in palestra, anche senza rete.',
            ],
            [
                'icona' => 'chat',
                'titolo' => 'La chat con chi ti segue',
                'dettaglio' => 'I messaggi li leggete solo voi due: nemmeno noi possiamo aprirli.',
            ],
            [
                'icona' => 'restaurant',
                'titolo' => 'Il diario di quello che mangi',
                'dettaglio' => 'Con i tuoi obiettivi calcolati sui tuoi numeri.',
            ],
        ];

        $piano = $this->pianoDi($palestra);

        /*
         * ⚠️ **L'AI si promette solo se c'e' davvero.**
         *
         * `ai_enabled` e' del piano, non della palestra: una palestra su un
         * piano senza AI non deve leggere qui una funzione che poi non trovera'.
         */
        if ($piano !== null && $piano->ai_enabled) {
            $chiamate = $palestra->ai_monthly_calls_per_member
                ?? $piano->ai_monthly_calls_per_member;

            $righe[] = [
                'icona' => 'auto_awesome',
                'titolo' => 'I consigli e le stime con l\'AI',
                'dettaglio' => $chiamate !== null && $chiamate > 0
                    ? "Fino a {$chiamate} richieste al mese, comprese nell'iscrizione."
                    : 'Comprese nell\'iscrizione.',
            ];
        }

        return $righe;
    }

    /**
     * Il piano in corso della palestra, o `null`.
     *
     * ⚠️ `runWithoutTenant` e il filtro rimesso a mano: questo servizio gira da
     * un endpoint **pubblico**, dove non c'e' nessun contesto di palestra e la
     * query scopata non troverebbe niente. E' la stessa nota gia' scritta su
     * `PianoAttivo::per()`.
     */
    private function pianoDi(Tenant $palestra): ?Plan
    {
        return $this->context->runWithoutTenant(
            fn (): ?Plan => PlanSubscription::query()
                ->where('tenant_id', $palestra->id)
                ->attivi()
                ->with('plan')
                ->orderByDesc('starts_at')
                ->first()?->plan,
        );
    }
}
