<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountEraser;
use App\Services\Account\EsportaIDatiDellaPersona;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\EsciDaUnaPalestra;
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
        private readonly EsciDaUnaPalestra $esci,
    ) {}

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
     * l'inverso simmetrico dell'ingresso da invito. Entrare sposta tutte le righe di un
     * tenant che ha una persona sola; uscire deve estrarre le righe di **una**
     * persona da un tenant che ne ha molte.
     *
     * ⚠️ **Si risponde con il branding nuovo**, come l'accettazione di un invito: l'app deve
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
        $this->audit->log(AuditAction::UscitoDallaPalestra, $utente, ['gym' => $vecchia]);

        return response()->json([
            'message' => __('Sei uscito da :palestra.', ['palestra' => $vecchia ?? '—']),
            'branding' => $personale->branding(),
        ]);
    }

    public function gym(Request $request): JsonResponse
    {
        $utente = $request->user();
        $palestra = $utente?->tenant;

        /*
         * ══ 🆕 Si mostra chi ti COPRE, non «una palestra» — 3b-AH, 12/09/2026 ══
         *
         * ⛔ Prima la domanda era `ePersonale()`, e lasciava fuori due persone:
         *
         * | Chi | Cosa vedeva prima | Cosa vede adesso |
         * |---|---|---|
         * | Un `gym_admin` | la scheda della **propria** palestra, con il tasto per uscirne — che poi rifiutava | ⛔ niente: da casa propria non si esce |
         * | 🆕 L'allievo di un trainer (dopo AH.1) | niente, quindi **nessun modo di andarsene** | ✅ chi lo copre, e come uscirne |
         *
         * 💡 `name` si legge dal tenant e non da `branding()`, che per un tenant
         * personale risponde `null` apposta: quello è il payload **pubblico**,
         * questo lo legge solo chi sta già dentro.
         */
        if ($palestra === null || $palestra->eDi($utente)) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'name' => $palestra->name,
            'slug' => $palestra->slug,
            'logo_url' => $palestra->logo_path ? url($palestra->logo_path) : null,
            'contact_email' => $palestra->contact_email,

            /*
             * 💡 Da quando ne fai parte: e' `created_at` **dell'utente dentro
             * quel tenant**, non del tenant. ⚠️ Chi e' entrato da un invito
             * ha portato con se' la propria data di iscrizione originale, che
             * e' la risposta giusta alla domanda «da quando sono qui?» solo se
             * ci si e' iscritti da li'. Per gli altri e' comunque la data piu'
             * onesta che abbiamo.
             */
            'iscritto_dal' => $request->user()?->created_at?->toDateString(),
        ]]);
    }

    /**
     * 📦 **I miei dati** — artt. 15 e 20 GDPR, 19/09/2026.
     *
     * Tutto quello che il server sa della persona, in JSON: l'app ci aggiunge
     * l'archivio del telefono e ne fa un file solo. Vedi
     * `EsportaIDatiDellaPersona` per cosa resta fuori e perché.
     */
    public function esporta(Request $request, EsportaIDatiDellaPersona $esporta): JsonResponse
    {
        return response()->json(['data' => $esporta($request->user())]);
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
        /*
         * ══ 🆕 A CHI NON HA UNA PALESTRA NON SI PARLA DI PALESTRA ════════════
         *
         * ⛔ **Le due righe di «Resteranno» erano fisse**, e a un iscritto
         * individuale dicevano due cose false: che i suoi messaggi restano
         * «nella conversazione del tuo trainer», e che le schede «restano alla
         * palestra». 🚨 Visto sull'emulatore il 14/09 con un account senza
         * palestra e senza trainer.
         *
         * ⚠️ **Su una schermata irreversibile è peggio che altrove**: chi legge
         * una cosa che non lo riguarda smette di leggere il resto, e il resto è
         * l'elenco di quello che sta per perdere.
         *
         * 💡 Chi non ha una palestra ha comunque qualcosa che resta — il
         * conto dei pagamenti — e quel qualcosa si dice, invece di lasciare la
         * sezione vuota: «non resta niente» sarebbe un'altra frase falsa.
         */
        $utente = $request->user();
        $inPalestra = $utente?->tenant?->ePersonale() === false;

        return response()->json(['data' => [
            'deleted' => [
                'Il diario alimentare e i preferiti',
                'Le misure e lo storico del peso',
                'Gli allenamenti registrati e le schede che hai scritto tu',
                'Le foto dei progressi',
                'I dati del sonno e i consigli ricevuti',
            ],
            'kept' => $inPalestra
                ? [
                    'I messaggi già scambiati con il tuo trainer restano nella sua'
                        .' conversazione, ma non risulteranno più tuoi.',
                    'Le schede che ti ha scritto il trainer restano alla palestra.',
                ]
                : [
                    'Le ricevute dei pagamenti restano a chi li ha incassati,'
                        .' come vuole la legge.',
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
