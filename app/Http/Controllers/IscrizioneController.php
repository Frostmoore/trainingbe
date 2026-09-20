<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanKind;
use App\Models\Plan;
use App\Services\Billing\Stripe\PianiInVendita;
use App\Services\Tenancy\IscrizioneDalSito;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * L'iscrizione dal sito — 20/09/2026.
 *
 * 📌 Il committente: *«ci deve essere un modo per permettere alle palestre e ai
 * trainers di abbonarsi direttamente dal sito»*.
 *
 * ── 🚨 I tre passi, e perché sono in quest'ordine ──────────────────────────
 *
 * 1. **Si crea l'account** (`IscrizioneDalSito`) — spazio, ruoli, utente,
 *    proprietario, dichiarazioni.
 * 2. **Si entra subito** nella sessione: chi torna da Stripe deve trovare il
 *    pannello aperto, non un modulo di accesso che gli chiede una password
 *    scritta trenta secondi fa.
 * 3. **Si va al checkout** (`PianiInVendita::apri()`), che è il posto in cui
 *    Stripe vive già.
 *
 * ⚠️ **Se il pagamento non arriva, l'account resta senza piano.** È il male
 * minore: l'alternativa sarebbe tenere nome, email e password in sessione
 * mentre si esce verso un sito esterno, e riprenderli al ritorno — cioè
 * conservare una password in chiaro in un posto in più, per evitare una riga in
 * più in una tabella.
 *
 * 💡 Chi abbandona trova il pannello con «nessun piano attivo» e ci ricompra da
 * lì: è la stessa strada di chi cambia piano, e non ne serve una seconda.
 */
class IscrizioneController extends Controller
{
    /** Il modulo: nome, email, password, e il piano già scelto. */
    public function modulo(string $piano, IscrizioneDalSito $iscrizioni): View
    {
        $scelto = $iscrizioni->pianoComprabile($piano);

        // 🚨 404 e non un messaggio: un piano che non è a listino non deve
        // nemmeno mostrare un modulo che poi rifiuterà.
        abort_if($scelto === null, 404);

        return view('sito.iscriviti', $this->datiDelModulo($scelto));
    }

    /**
     * L'iscrizione vera.
     *
     * 🚨 **La convalida sta qui e non in una FormRequest** perché due delle
     * regole dipendono dal **piano**: il nome dell'attività serve a una
     * palestra e non a un trainer, e il piano stesso dev'essere comprabile. Una
     * FormRequest dovrebbe ricaricarlo per conto suo, e sarebbero due letture
     * che possono discordare.
     */
    public function iscrivi(
        Request $request,
        string $piano,
        IscrizioneDalSito $iscrizioni,
        PianiInVendita $vendita,
    ): RedirectResponse {
        $scelto = $iscrizioni->pianoComprabile($piano);

        abort_if($scelto === null, 404);

        $ePalestra = $scelto->kind === PlanKind::Gym;

        $dati = $request->validate([
            'nome' => ['required', 'string', 'min:2', 'max:255'],
            'attivita' => [$ePalestra ? 'required' : 'nullable', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],

            /*
             * ⚠️ **Lettere e numeri, e basta** — la stessa regola della
             * registrazione dall'app. La composizione forzata produce
             * «Password1!» in tutto il mondo, che è ciò che gli attacchi provano
             * per primo: la lunghezza è la variabile che conta (NIST 800-63B).
             *
             * 🚨 `confirmed` ha senso solo perché il modulo chiede davvero due
             * campi: qui la password si digita una volta sola e si sbaglia una
             * volta sola, e chi si accorge dell'errore lo fa il giorno dopo,
             * chiuso fuori dal proprio pannello.
             */
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],

            // `accepted`: un `false` deve **fallire**, non essere registrato
            // come «ha detto di no e comunque entra».
            'accetto' => ['accepted'],
        ], [], [
            'nome' => 'nome',
            'attivita' => $ePalestra ? 'nome della palestra' : 'nome dell\'attività',
        ]);

        /*
         * 🚨 **L'indirizzo dev'essere libero su tutta la piattaforma** — la
         * regola e il perché stanno in `IscrizioneDalSito::emailGiaUsata()`.
         *
         * 💡 Qui si può dire com'è andata, al contrario della registrazione
         * dall'app: là il messaggio è volutamente vago perché rivelerebbe **a
         * quale palestra** appartiene un indirizzo. Qui non c'è niente da
         * rivelare — chi si sta iscrivendo sta creando il proprio spazio — e un
         * «non è stato possibile» manderebbe via un cliente che voleva pagare.
         */
        if ($iscrizioni->emailGiaUsata($dati['email'])) {
            throw ValidationException::withMessages([
                'email' => __('Esiste già un account con questo indirizzo. Entra nel pannello e cambia piano da lì.'),
            ]);
        }

        $utente = $iscrizioni(
            $scelto,
            $dati['nome'],
            $dati['email'],
            $dati['password'],
            $dati['attivita'] ?? null,
        );

        // 💡 Prima del checkout: chi torna da Stripe trova il pannello aperto.
        Auth::login($utente);
        $request->session()->regenerate();

        try {
            return redirect()->away($vendita->apri($utente, $scelto->code));
        } catch (Throwable $e) {
            /*
             * ⚠️ **L'account c'è già, e non si butta via.** Se Stripe non
             * risponde, la cosa peggiore da fare sarebbe cancellare tutto e
             * mostrare «riprova»: chi ha appena scritto la password se la
             * ritroverebbe rifiutata come «email già usata».
             *
             * 🚨 Il messaggio dell'eccezione **non si mostra**: può contenere
             * dettagli dell'integrazione. Va nel registro, dove serve a chi
             * indaga.
             */
            Log::error('Checkout non aperto dopo un\'iscrizione dal sito', [
                'utente' => $utente->getKey(),
                'piano' => $scelto->code,
                'errore' => $e->getMessage(),
            ]);

            return redirect('/admin')->with(
                'avviso',
                'Il tuo account è pronto, ma il pagamento non è partito. Riprova dal pannello.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function datiDelModulo(Plan $piano): array
    {
        $ePalestra = $piano->kind === PlanKind::Gym;

        return [
            'piano' => $piano,
            'ePalestra' => $ePalestra,
            'unita' => $ePalestra ? 'iscritti' : 'allievi',
            'formatta' => static fn (int $centesimi): string => number_format($centesimi / 100, 2, ',', '.').' €',
            // 💡 Il link «torna ai piani» punta alla pagina da cui si arriva,
            // non a `/prezzi`: chi cambia idea vuole gli altri piani suoi, non
            // di nuovo il bivio fra le tre platee.
            'tornaA' => $ePalestra ? '/prezzi/palestra' : '/prezzi/trainer',
        ];
    }
}
