<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanKind;
use App\Models\OffertaDiPiano;
use App\Models\User;
use App\Services\Billing\Stripe\PianiInVendita;
use App\Services\Tenancy\IscrizioneDalSito;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * 🔗 **La pagina di un piano su misura: si legge e si paga** — 21/09/2026.
 *
 * 📌 Il committente: *«certo che il piano cucito a mano deve essere fatto con
 * stripe»*.
 *
 * ── ⛔ Cosa c'era stamattina ───────────────────────────────────────────────
 *
 * Il piano si **assegnava** dal pannello: il cliente si ritrovava il servizio
 * acceso e la fattura da nessuna parte. 🚨 Per un canone voleva dire
 * ricordarsi ogni mese di incassarlo a mano — cioè, prima o poi, non
 * incassarlo.
 *
 * ── 💡 Come funziona adesso ───────────────────────────────────────────────
 *
 * 1. Si scrive dal modulo di contatto e si concorda a voce.
 * 2. Dal pannello `/god` si cuce il piano e si crea l'**offerta**: un link.
 * 3. Il cliente apre il link, vede **cosa** e **quanto**, e paga su Stripe.
 * 4. Il webhook accende l'abbonamento e consuma l'offerta.
 *
 * ── 🚨 Le due strade dal link, e perché sono due ──────────────────────────
 *
 * | Offerta | Chi la apre | Cosa vede |
 * |---|---|---|
 * | Intestata a un cliente (`tenant_id`) | dev'essere **quel** titolare | il piano e il pulsante; se non è entrato, il modulo d'accesso |
 * | Senza intestatario | chiunque abbia il link | il piano e il modulo d'**iscrizione**: lo spazio nasce lì |
 *
 * ⛔ Un link intestato che aprisse anche uno spazio nuovo sarebbe il modo di
 * far nascere un doppione della stessa palestra al primo inoltro della mail.
 */
class OffertaController extends Controller
{
    /** La pagina dell'offerta. */
    public function mostra(string $token, PianiInVendita $vendita): View
    {
        $offerta = $this->offerta($token);

        return view('sito.offerta', $this->datiDellaPagina($offerta));
    }

    /**
     * Il pulsante: iscrizione (se serve), poi Stripe.
     *
     * 🚨 **L'ordine è lo stesso di `IscrizioneController`**: si crea l'account,
     * si entra, e solo dopo si va al checkout. Chi torna da Stripe deve trovare
     * il pannello aperto, non un modulo d'accesso.
     */
    public function attiva(
        Request $request,
        string $token,
        IscrizioneDalSito $iscrizioni,
        PianiInVendita $vendita,
    ): RedirectResponse {
        $offerta = $this->offerta($token);
        $piano = $offerta->plan;

        if ($piano === null || ! $offerta->eValida()) {
            return back()->with('avviso', $offerta->perchePiuNonVale() ?? 'Questo link non è più valido.');
        }

        $utente = Auth::user();

        if ($utente === null) {
            /*
             * ⛔ **Su un'offerta intestata non si crea niente**: quel cliente
             * esiste già, e il pulsante che fa nascere un secondo spazio con lo
             * stesso nome è il difetto che si scopre quando le fatture sono due.
             */
            if ($offerta->tenant_id !== null) {
                return redirect('/admin/login')->with(
                    'avviso',
                    'Entra col tuo account di titolare, poi riapri questo link.',
                );
            }

            $utente = $this->iscrivi($request, $offerta, $iscrizioni);
        }

        try {
            $dove = $vendita->attivaOfferta($utente, $offerta, null);
        } catch (InvalidArgumentException $e) {
            // ⚠️ Il messaggio di questa eccezione è scritto per essere letto:
            // «hai già questo piano», «è intestata a un altro cliente».
            return back()->with('avviso', $e->getMessage());
        } catch (Throwable $e) {
            /*
             * 🚨 Il messaggio **non si mostra**: può contenere dettagli
             * dell'integrazione. Va nel registro, dove serve a chi indaga.
             */
            Log::error('Offerta: il checkout non si è aperto.', [
                'offerta' => $offerta->getKey(),
                'errore' => $e->getMessage(),
            ]);

            return back()->with('avviso', 'Il pagamento non è partito. Riprova fra poco, o scrivici.');
        }

        /*
         * 💡 `null` = non c'era niente da aprire: il piano è stato attivato
         * cambiando l'abbonamento Stripe che c'era già, col conguaglio.
         */
        if ($dove === null) {
            return redirect('/admin')->with('avviso', 'Il tuo piano è stato aggiornato: la differenza la conteggia Stripe.');
        }

        return redirect()->away($dove);
    }

    /**
     * L'iscrizione da un'offerta senza intestatario.
     *
     * ⚠️ **Le regole sono quelle di `IscrizioneController`**, e stanno scritte
     * là per esteso: password lunga invece che complicata, email libera su
     * tutta la piattaforma, dichiarazioni con la loro data.
     */
    private function iscrivi(Request $request, OffertaDiPiano $offerta, IscrizioneDalSito $iscrizioni): User
    {
        $ePalestra = $offerta->plan?->kind === PlanKind::Gym;

        $dati = $request->validate([
            'nome' => ['required', 'string', 'min:2', 'max:255'],
            'attivita' => [$ePalestra ? 'required' : 'nullable', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'accetto' => ['accepted'],
        ], [], [
            'attivita' => $ePalestra ? 'nome della palestra' : 'nome dell\'attività',
        ]);

        if ($iscrizioni->emailGiaUsata($dati['email'])) {
            throw ValidationException::withMessages([
                'email' => __('Esiste già un account con questo indirizzo: entra, e poi riapri questo link.'),
            ]);
        }

        $utente = $iscrizioni(
            $offerta->plan,
            $dati['nome'],
            $dati['email'],
            $dati['password'],
            $dati['attivita'] ?? null,
        );

        Auth::login($utente);
        $request->session()->regenerate();

        return $utente;
    }

    /**
     * 🚨 **404 e non un messaggio**: un token inventato non deve ricevere una
     * pagina che spiega cosa sarebbe successo con quello giusto.
     */
    private function offerta(string $token): OffertaDiPiano
    {
        $offerta = OffertaDiPiano::withoutGlobalScopes()
            ->with(['plan', 'tenant'])
            ->where('token', $token)
            ->first();

        abort_if($offerta === null || $offerta->plan === null, 404);

        return $offerta;
    }

    /** @return array<string, mixed> */
    private function datiDellaPagina(OffertaDiPiano $offerta): array
    {
        $piano = $offerta->plan;
        $ePalestra = $piano->kind === PlanKind::Gym;

        return [
            'offerta' => $offerta,
            'piano' => $piano,
            'ePalestra' => $ePalestra,
            'unita' => $ePalestra ? 'iscritti' : 'allievi',
            'valida' => $offerta->eValida(),
            'motivo' => $offerta->perchePiuNonVale(),

            // 💡 Chi è già entrato non deve rivedere un modulo d'iscrizione.
            'giaDentro' => Auth::check(),

            'formatta' => static fn (int $centesimi): string => number_format($centesimi / 100, 2, ',', '.').' €',
        ];
    }
}
