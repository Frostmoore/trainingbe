<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\InvitoInPalestra;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CosaOttieniInPalestra;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Gli inviti di una palestra — 3b-V.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«Il link d'invito deve essere monouso, e a chi ci clicca si deve aprire l'app
 * in una pagina con la descrizione della palestra, il logo, i colori, un
 * messaggio di congratulazioni, le cose a cui avrà accesso e due tasti, uno per
 * accettare e uno per rifiutare»* — 29/08/2026.
 *
 * ══ 🚨 L'INVITO AUTORIZZA, `UnisciAUnaPalestra` ESEGUE ════════════════════
 *
 * ⛔ Qui dentro **non c'e' una riga** che sposti dati da un tenant a un altro, ed
 * e' deliberato: quello lo sa fare `UnisciAUnaPalestra`, con l'elenco delle
 * tabelle che restano indietro, la transazione e il controllo sull'email gia'
 * presa nella palestra nuova.
 *
 * ⚠️ Reimplementarlo qui vorrebbe dire **due modi di entrare in palestra**, e il
 * secondo si accorgerebbe dell'ultima tabella aggiunta solo quando qualcuno si
 * ritrova lo storico dimezzato.
 *
 * 💡 Per questo `accetta()` finisce con una chiamata sola. La differenza fra il
 * codice palestra e l'invito e' **chi ti autorizza**, non cosa succede dopo.
 */
class InvitiInPalestra
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UnisciAUnaPalestra $unisci,
        private readonly CosaOttieniInPalestra $cosaOttieni,
    ) {}

    /**
     * Crea un invito per una persona.
     *
     * @throws ValidationException
     */
    public function invita(Tenant $palestra, User $chiInvita, ?string $email = null): InvitoInPalestra
    {
        if ($palestra->ePersonale()) {
            /*
             * ⛔ Un tenant personale non e' una palestra: non ha iscritti e non
             * puo' invitare nessuno. Senza questa riga, chiunque abbia un
             * account potrebbe generare inviti per «entrare in se stesso».
             */
            throw ValidationException::withMessages([
                'palestra' => __('Solo una palestra può invitare.'),
            ]);
        }

        return $this->context->runAs($palestra, fn (): InvitoInPalestra => InvitoInPalestra::create([
            'tenant_id' => $palestra->id,
            'invitato_da' => $chiInvita->getKey(),
            'token' => InvitoInPalestra::generaToken(),
            'email' => $email,
            'expires_at' => now()->addDays(InvitoInPalestra::GIORNI_DI_VITA),
        ]));
    }

    /**
     * Cosa mostrare a chi tocca il link — **senza consumarlo**.
     *
     * 🚨 **L'anteprima non brucia l'invito**, e va detto perche' sembra una
     * sfumatura: la gente apre i link, li chiude, li riapre da un altro
     * telefono, li manda a sua moglie per chiederle se ha senso. ⛔ Un invito
     * che si consuma guardandolo e' un invito che si perde la prima volta che
     * qualcuno lo legge senza decidere.
     *
     * @return ?array{palestra: array<string, mixed>, descrizione: ?string, cosa_ottieni: list<array<string, string>>, scade_il: string}
     */
    public function anteprima(string $token): ?array
    {
        $invito = $this->trova($token);

        if ($invito === null) {
            return null;
        }

        $palestra = $invito->palestra;

        /*
         * ⚠️ Una palestra sospesa non deve poter far entrare nessuno, e non e'
         * pignoleria contabile: chi entrasse si troverebbe dentro un servizio
         * spento, con un messaggio che parla di un abbonamento non suo.
         */
        if ($palestra === null || ! $palestra->isActive() || $palestra->ePersonale()) {
            return null;
        }

        return [
            /*
             * 💡 `branding()` e non i campi a mano: e' lo stesso corpo che l'app
             * riceve gia' da `/branding/lookup`, quindi la schermata dell'invito
             * si veste con il codice che c'e' gia'.
             */
            'palestra' => $palestra->branding(),
            'descrizione' => $this->descrizioneDi($palestra),
            'cosa_ottieni' => $this->cosaOttieni->per($palestra),
            'scade_il' => $invito->expires_at->toIso8601String(),
        ];
    }

    /**
     * Accetta: la persona entra nella palestra, e l'invito si brucia.
     *
     * 🚨 **L'ordine e' entrare-poi-bruciare, non il contrario.** Se
     * `UnisciAUnaPalestra` fallisce — l'email e' gia' presa in quella palestra,
     * la persona sta gia' in un'altra — l'invito deve restare **valido**:
     * bruciarlo prima vorrebbe dire che un tentativo fallito per un motivo
     * risolvibile costa alla persona l'unico invito che aveva.
     *
     * @throws ValidationException
     */
    public function accetta(string $token, User $utente): Tenant
    {
        $invito = $this->trova($token);

        if ($invito === null) {
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        $palestra = $invito->palestra;

        if ($palestra === null || ! $palestra->isActive() || $palestra->ePersonale()) {
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        /*
         * ⚠️ **Si passa dal `join_code` della palestra**, e sembra un giro
         * strano: l'invito ha gia' il `tenant_id`.
         *
         * 💡 E' voluto. `UnisciAUnaPalestra` e' l'unico posto che sa spostare
         * una persona — tabelle che restano indietro, transazione, email gia'
         * presa — e prende un codice. Dargli un secondo ingresso «per tenant»
         * vorrebbe dire due strade dentro lo stesso metodo, e la seconda e'
         * sempre quella meno provata.
         *
         * 🚨 **Non e' un buco**: il codice non esce di qui. Chi accetta non lo
         * vede mai, e l'autorizzazione l'ha data l'invito, non il codice.
         */
        $entrata = $this->unisci->__invoke($utente, $palestra->join_code);

        $invito->forceFill([
            'used_at' => now(),
            'accepted_by' => $utente->getKey(),
        ])->save();

        return $entrata;
    }

    /**
     * Rifiuta: l'invito si brucia, e nessuno entra.
     *
     * ── 🚨 Perche' il rifiuto BRUCIA ───────────────────────────────────────
     *
     * Quell'invito era per **quella** persona, e quella persona ha detto no.
     * ⛔ Lasciarlo valido vorrebbe dire un invito che nessuno usera' mai e che
     * la palestra crede ancora in piedi — cioe' una persona che risulta
     * «invitata, in attesa» per sempre.
     *
     * 💡 Meglio che la palestra lo veda rifiutato: se e' stato un errore ne
     * manda un altro, che costa un secondo.
     *
     * ⚠️ **Non chiede l'autenticazione**, e non e' una dimenticanza: chi non ha
     * l'account deve poter dire di no **senza crearne uno per farlo**. Il link
     * e' la credenziale, come per l'anteprima.
     */
    public function rifiuta(string $token): void
    {
        $invito = $this->trova($token);

        /*
         * 💡 Silenzio se non c'e': rifiutare un invito gia' scaduto e rifiutarne
         * uno mai esistito devono essere indistinguibili, altrimenti questo
         * endpoint diventa un modo per sapere quali token sono validi.
         */
        $invito?->forceFill(['rifiutato_il' => now()])->save();
    }

    /**
     * Revoca: ci ha ripensato la palestra.
     *
     * ⚠️ Diverso dal rifiuto, e le due colonne restano separate: «non l'ho
     * voluto» e «non te lo do piu'» sono due cose che la palestra ha diritto di
     * distinguere quando guarda l'elenco.
     */
    public function revoca(InvitoInPalestra $invito): void
    {
        $invito->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * La descrizione che la palestra ha scritto su di se'.
     *
     * ── ⚠️ Viene dal CATALOGO, e non da una colonna nuova ─────────────────
     *
     * 🚨 `tenants` non ha nessun campo «descrizione», e il primo tentativo di
     * questo metodo ne leggeva uno inesistente: avrebbe risposto `null` per
     * sempre, **senza nessun errore**. Una pagina d'invito con un buco al posto
     * della presentazione, e nessuno in grado di dire perche'.
     *
     * 💡 Il testo esiste gia' in `profili_pubblici.descrizione` (M2): e' quello
     * che la palestra ha scritto per il catalogo, cioe' letteralmente la sua
     * presentazione.
     *
     * ── 🚨 E si legge anche se il profilo non e' `visibile` ────────────────
     *
     * ⚠️ Sembra il contrario della prudenza di casa, e non lo e': `visibile`
     * vuol dire *«non mettermi nell'elenco pubblico»*, non *«nascondi chi sono
     * a chi invito io»*. 💡 Qui la palestra **sta scegliendo** di presentarsi a
     * quella persona: e' lei che ha generato il link.
     */
    private function descrizioneDi(Tenant $palestra): ?string
    {
        return $this->context->runWithoutTenant(
            fn (): ?string => ProfiloPubblico::query()
                ->where('tenant_id', $palestra->id)
                ->whereNull('user_id')
                ->value('descrizione'),
        );
    }

    /**
     * L'invito valido con questo token, o `null`.
     *
     * 🚨 **`withoutGlobalScopes()`, e va spiegato.** `InvitoInPalestra` e'
     * scopato per tenant, ma qui non c'e' nessun tenant: chi tocca il link e'
     * fuori da tutto, e spesso non ha nemmeno un account. Con lo scope attivo
     * questa query non troverebbe **mai** niente.
     *
     * ⛔ Non e' un bypass: il token e' l'autorizzazione, e un token di 32
     * caratteri identifica una riga sola. Il filtro per tenant qui non
     * proteggerebbe niente — proteggerebbe solo dal trovare l'invito giusto.
     */
    private function trova(string $token): ?InvitoInPalestra
    {
        return InvitoInPalestra::withoutGlobalScopes()
            ->validi()
            ->with('palestra')
            ->where('token', $token)
            ->first();
    }
}
