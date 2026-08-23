<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountEraser;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\EsciDaUnaPalestra;
use App\Services\Tenancy\UnisciAUnaPalestra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * L'eliminazione del proprio account — C6.
 *
 * 🚨 **Apple la pretende** per ogni app che permette di registrarsi: dev'essere
 * raggiungibile dall'app, senza scrivere a nessuno e senza passare da un sito.
 * Senza, la pubblicazione viene rifiutata.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountEraser $eraser,
        private readonly AuditLogger $audit,
        private readonly UnisciAUnaPalestra $unisci,
        private readonly EsciDaUnaPalestra $esci,
    ) {}

    /**
     * Entra in una palestra, portandosi dietro la propria storia — B4.
     *
     * 🚨 **Il lavoro vero è in `UnisciAUnaPalestra`**, e il perché sta lì: non è
     * un `UPDATE` su una colonna, è una **migrazione di dati**. Qui c'è solo la
     * porta.
     *
     * ⚠️ **Si risponde con il branding nuovo**: l'app deve poter cambiare
     * colori e nome subito, senza un secondo giro. Chi entra in una palestra si
     * aspetta di vederla, e vedere ancora la schermata neutra farebbe pensare
     * che non abbia funzionato.
     */
    /**
     * I dettagli della palestra a cui si e' iscritti — 3b-P.13.1, 23/08/2026.
     *
     * 📌 Il committente: *«una volta connesso con una palestra ci dovranno
     * essere i dettagli della palestra»*.
     *
     * ── ⚠️ Perche' non basta il branding che l'app ha gia' ──────────────────
     *
     * `GET /branding/lookup` e' **pubblico** e serve a vestire la schermata di
     * accesso: da' insegna, logo e colori, e si ferma li' per forza — e' quello
     * che vede chiunque conosca il codice.
     *
     * 💡 Qui si e' dentro, autenticati, e si puo' dire **di piu'**: da quando
     * si e' iscritti, e come si contatta la palestra. Sono le due cose che uno
     * cerca quando apre questa scheda.
     *
     * ⛔ **E niente di piu' di cosi'.** Non il numero di iscritti, non il piano
     * commerciale, non chi altro c'e' dentro: sono affari della palestra, e chi
     * ne fa parte non ha per questo il diritto di leggerli.
     *
     * 🚨 **Chi non ha una palestra riceve `null`, non un errore.** Un 404 qui
     * vorrebbe dire che l'app deve distinguere «non ce l'hai» da «e' andata
     * storta», e sono due cose diverse che meritano due schermate diverse.
     */
    /**
     * Esce dalla palestra — 3b-P.13.3, 23/08/2026.
     *
     * 🚨 **Il lavoro vero è in `EsciDaUnaPalestra`**, e il perché sta lì: non è
     * l'inverso simmetrico di `join-gym`. Entrare sposta tutte le righe di un
     * tenant che ha una persona sola; uscire deve estrarre le righe di **una**
     * persona da un tenant che ne ha molte.
     *
     * ⚠️ **Si risponde con il branding nuovo**, come `joinGym`: l'app deve
     * poter togliere colori e insegna subito. Chi esce da una palestra si
     * aspetta di smettere di vederla.
     */
    public function leaveGym(Request $request): JsonResponse
    {
        $utente = $request->user();

        if ($utente === null) {
            return response()->json(['message' => __('Non autenticato.')], 401);
        }

        $vecchia = $utente->tenant?->name;
        $personale = ($this->esci)($utente);

        /*
         * ⚖️ **Va nel registro**, e non è burocrazia: è la traccia che permette
         * di rispondere alla domanda «dov'è finito quell'iscritto?» il giorno in
         * cui la palestra la fa. ⛔ Senza, l'unica risposta sarebbe «non lo
         * sappiamo».
         */
        $this->audit->log('account.left_gym', $utente, ['gym' => $vecchia]);

        return response()->json([
            'message' => __('Sei uscito da :palestra.', ['palestra' => $vecchia ?? '—']),
            'branding' => $personale->branding(),
        ]);
    }

    public function gym(Request $request): JsonResponse
    {
        $palestra = $request->user()?->tenant;

        if ($palestra === null || $palestra->ePersonale()) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'name' => $palestra->name,
            'slug' => $palestra->slug,
            'logo_url' => $palestra->logo_path ? url($palestra->logo_path) : null,
            'contact_email' => $palestra->contact_email,

            /*
             * 💡 Da quando ne fai parte: e' `created_at` **dell'utente dentro
             * quel tenant**, non del tenant. ⚠️ Chi e' entrato con `join-gym`
             * ha portato con se' la propria data di iscrizione originale, che
             * e' la risposta giusta alla domanda «da quando sono qui?» solo se
             * ci si e' iscritti da li'. Per gli altri e' comunque la data piu'
             * onesta che abbiamo.
             */
            'iscritto_dal' => $request->user()?->created_at?->toDateString(),
        ]]);
    }

    public function joinGym(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'join_code' => ['required', 'string', 'size:8'],
        ]);

        $palestra = ($this->unisci)(
            $request->user(),
            strtoupper(trim($dati['join_code'])),
        );

        return response()->json([
            'message' => __('Sei entrato in :palestra.', ['palestra' => $palestra->name]),
            'branding' => $palestra->branding(),
        ]);
    }

    /**
     * Cosa succede se si cancella: da mostrare **prima** del pulsante.
     *
     * ⚠️ Un'azione irreversibile va spiegata nel punto in cui si compie. Un
     * elenco scritto solo nell'app diventa falso il giorno che il server cambia
     * politica, e nessuno se ne accorge.
     */
    public function preview(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'deleted' => [
                'Il diario alimentare e i preferiti',
                'Le misure e lo storico del peso',
                'Gli allenamenti registrati e le schede che hai scritto tu',
                'Le foto dei progressi',
                'I dati del sonno e i consigli ricevuti',
            ],
            'kept' => [
                'I messaggi già scambiati con il tuo trainer restano nella sua'
                    .' conversazione, ma non risulteranno più tuoi.',
                'Le schede che ti ha scritto il trainer restano alla palestra.',
            ],
            'irreversible' => true,
        ]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        /*
         * 🚨 La password si richiede, e non è pignoleria.
         *
         * È l'unica azione irreversibile che l'app offre, e un telefono
         * sbloccato lasciato sul tavolo non deve bastare a compierla. È lo
         * stesso motivo per cui i sistemi operativi richiedono il PIN prima di
         * un ripristino di fabbrica.
         */
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $utente = $request->user();

        if (! Hash::check($request->string('password')->toString(), (string) $utente->password)) {
            return response()->json([
                'message' => __('Password non corretta.'),
                'errors' => ['password' => [__('Password non corretta.')]],
            ], 422);
        }

        // Si registra PRIMA di cancellare: dopo, l'utente non c'è più e la riga
        // di controllo nascerebbe senza autore. È la riga che serve il giorno in
        // cui qualcuno contesta la cancellazione.
        $this->audit->log(
            AuditAction::UserDeleted,
            $utente,
            ['reason' => 'account_self_deletion'],
        );

        $this->eraser->erase($utente);

        return response()->json(null, 204);
    }

    // ───────────────────────── email e password (G8) ─────────────────────────

    /**
     * Cambiare la propria email.
     *
     * 🚨 **Serve la password attuale.** Un telefono lasciato sbloccato sulla
     * panca dello spogliatoio basterebbe altrimenti a spostare l'account su un
     * indirizzo che non e' il tuo — e da li', con un recupero password, a
     * prenderselo. E' la stessa ragione per cui la chiede l'eliminazione.
     *
     * ⚠️ **L'unicita' e' per palestra**, non globale: lo stesso indirizzo puo'
     * appartenere a due persone in due palestre diverse, ed e' una situazione
     * prevista. Una `unique` senza il vincolo sul tenant rifiuterebbe un'email
     * perche' usata da qualcun altro **altrove**, e chi la subisce non avrebbe
     * modo di capire perche'.
     */
    public function updateEmail(Request $request): JsonResponse
    {
        $utente = $request->user();

        $dati = $request->validate([
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')
                    ->where('tenant_id', $utente->tenant_id)
                    ->ignore($utente->getKey())
                    ->whereNull('deleted_at'),
            ],
            'current_password' => ['required', 'string'],
        ]);

        $this->assertPassword($utente, $dati['current_password']);

        $vecchia = $utente->email;

        $utente->forceFill(['email' => $dati['email']])->save();

        // Un cambio di email e' il passo che precede quasi ogni presa di
        // controllo di un account: va lasciata traccia di chi e quando.
        $this->audit->log(AuditAction::EmailChanged, $utente, ['da' => $vecchia]);

        return response()->json(['data' => ['email' => $utente->email]]);
    }

    /**
     * Cambiare la propria password.
     *
     * 🚨 **Le altre sessioni si chiudono.** Chi cambia password spesso lo fa
     * perche' teme che qualcuno sia entrato: lasciare attivi i token degli altri
     * dispositivi vorrebbe dire non aver cambiato niente per chi e' gia' dentro.
     * Il token della richiesta corrente resta, o ci si disconnetterebbe da soli
     * premendo «salva».
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $utente = $request->user();

        $dati = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $this->assertPassword($utente, $dati['current_password']);

        $utente->forceFill([
            'password' => $dati['password'],
            'password_is_set' => true,
        ])->save();

        $corrente = $request->user()->currentAccessToken();

        $utente->tokens()
            ->when(
                $corrente instanceof PersonalAccessToken,
                fn ($q) => $q->whereKeyNot($corrente->getKey()),
            )
            ->delete();

        $this->audit->log(AuditAction::PasswordChanged, $utente);

        return response()->json([
            'message' => __('Password aggiornata. Le altre sessioni sono state chiuse.'),
        ]);
    }

    /**
     * La password attuale dev'essere quella giusta.
     *
     * ⚠️ Per chi entra con Google o Apple non esiste una password che conosca
     * (`password_is_set` e' falso): glielo si dice, invece di lasciarlo
     * indovinare contro un hash casuale che non indovinera' mai.
     */
    private function assertPassword(User $utente, string $password): void
    {
        if (! $utente->password_is_set) {
            throw ValidationException::withMessages([
                'current_password' => __('Questo account accede con Google o Apple e non ha una password.'),
            ]);
        }

        if (! Hash::check($password, (string) $utente->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('La password attuale non è corretta.'),
            ]);
        }
    }
}
