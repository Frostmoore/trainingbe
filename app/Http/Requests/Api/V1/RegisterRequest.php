<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Iscrizione di una persona nuova — **con o senza** il codice di una palestra.
 *
 * Registra SEMPRE e SOLO un iscritto: il ruolo non è un dato in ingresso.
 * Se lo fosse, chiunque conosca un codice palestra potrebbe farsi amministratore
 * — e sarebbe un campo che nessuno nota finché non viene sfruttato.
 * Trainer e amministratori si creano dal pannello (B3).
 *
 * ── 🆕 `join_code` è facoltativo da F3.1 ────────────────────────────────────
 *
 * Con il codice si entra in quella palestra, come sempre. Senza, nasce un
 * **tenant personale** (`TenantKind::Personal`) e la persona diventa un
 * `UserRole::FreeUser`. Il ramo sta in `AuthController::register()`.
 *
 * 🚨 **Ciò che NON è diventato facoltativo: `age_confirmed` e `terms_accepted`.**
 * Sono ancora `required|accepted`, ed è la regola §2.4 del piano della Parte B —
 * *ogni porta d'ingresso nuova nasce già con lo sbarramento 18+*. Rendere
 * opzionale il codice palestra e lasciarsi dietro anche i consensi sarebbe stato
 * facilissimo: sono nello stesso array, a tre righe di distanza.
 *
 * ⚠️ Il test `RegistrazioneLiberaTest::the_age_gate_survives_the_optional_code`
 * esiste esattamente perché quella distanza è di tre righe.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * 🆕 **Facoltativo da F3.1.** `nullable` e non `sometimes`: l'app in
             * circolazione manda il campo **sempre**, e quando l'utente non
             * scrive niente lo manda vuoto. Con `sometimes` una stringa vuota
             * sarebbe entrata in validazione e avrebbe fallito `size:8`,
             * bloccando l'iscrizione libera proprio dai telefoni già installati.
             *
             * ⚠️ `size:8` resta e vale **solo se il campo c'è**: un codice
             * scritto male non deve diventare silenziosamente un'iscrizione
             * senza palestra. Chi digita `ALFA234` (sette caratteri) ha sbagliato
             * a copiare, e deve leggerlo — non ritrovarsi un account personale.
             */
            'join_code' => ['nullable', 'string', 'size:8'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],

            /*
             * Il nome utente.
             *
             * 🚨 **Unico su tutta la piattaforma**, non per palestra — al
             * contrario dell'email. È voluto: serve proprio a essere un
             * identificativo che non ha bisogno del codice palestra per essere
             * disambiguato, ed è quello che permette il login dai pannelli,
             * dove un `join_code` non c'è.
             *
             * ⚠️ Qui `unique` è legittimo e non è un oracolo come lo sarebbe
             * sull'email: sapere che un nome utente è già preso non dice a
             * quale palestra appartenga né che esista una persona con
             * quell'indirizzo. È la stessa informazione che dà qualunque sito
             * al momento della registrazione.
             *
             * Il formato è ristretto apposta: niente `@`, così un nome utente
             * non può mai essere scambiato per un'email al momento del login,
             * e niente maiuscole (le normalizza il modello) perché `Marco` e
             * `marco` non diventino due account che all'occhio sono lo stesso.
             */
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/',
                'unique:users,username',
            ],

            /*
             * La password.
             *
             * 🚨 **`confirmed` funziona solo se il client manda due campi
             * diversi.** L'app li chiede davvero due volte: se ricopiasse il
             * primo valore, questa regola non potrebbe fallire mai e il
             * controllo esisterebbe senza proteggere da niente — cioe' dal caso
             * per cui e' li', l'errore di battitura che chiude fuori dal proprio
             * account il giorno dopo l'iscrizione.
             *
             * ⚠️ **Lettere e numeri, e basta.** Non si pretendono maiuscole e
             * simboli: la composizione forzata produce «Password1!» in tutto il
             * mondo, che e' esattamente cio' che gli attacchi provano per primo.
             * La lunghezza e' la variabile che conta, ed e' li' che l'app spinge
             * con i suoi consigli (NIST 800-63B, §5.1.1.2).
             *
             * `uncompromised()` — il confronto con le password trapelate — e'
             * dietro `auth.password_hibp` e **spento di serie**: e' una chiamata
             * di rete verso un servizio esterno nel percorso di iscrizione, e
             * questo gira su un server condiviso con altri siti. Vale la pena
             * accenderlo, ma dev'essere una decisione presa, non un effetto
             * collaterale. Quando fallisce la chiamata, la regola lascia
             * passare: non blocca mai nessuno per un problema di rete.
             */
            'password' => [
                'required',
                'confirmed',
                config('auth.password_hibp', false)
                    ? Password::min(8)->letters()->numbers()->uncompromised()
                    : Password::min(8)->letters()->numbers(),
            ],
            'phone' => ['nullable', 'string', 'max:32'],

            /*
             * 🚨 **Lo sbarramento dei maggiorenni — S9.2.**
             *
             * `accepted` e non `boolean`: un `false` deve **fallire**, non
             * essere registrato come «ha detto di no e comunque entra».
             *
             * ⚠️ **Non e' un controllo sulla data di nascita**, ed e' voluto:
             * dopo S5 il profilo sta sul telefono e il server non sa quanti anni
             * ha la persona. E' una **dichiarazione**, conservata con data e ora
             * in `users.age_confirmed_at`.
             *
             * 💡 **Perche' una dichiarazione basta**: 18+ e' la scelta che
             * *chiude* il problema invece di gestirlo. Sotto i 14 anni
             * servirebbe raccogliere e verificare il consenso di chi esercita la
             * responsabilita' genitoriale; fra i 14 e i 17 la soglia cambia da
             * Stato a Stato dell'Unione. Una riga sola copre tutti i casi.
             */
            'age_confirmed' => ['required', 'accepted'],

            /*
             * Le condizioni d'uso: obbligatorie, perche' sono il contratto.
             *
             * ⚠️ **I consensi facoltativi NON stanno qui** e non si chiedono
             * alla registrazione: dati sanitari e invio all'AI si concedono
             * dopo, uno per uno, da `PATCH /account/consents`. Metterli in
             * questo modulo li trasformerebbe in condizioni per iscriversi, e un
             * consenso che serve per usare il servizio non e' «liberamente
             * dato» ai sensi dell'art. 7(4) — cioe' non e' consenso.
             */
            'terms_accepted' => ['required', 'accepted'],

            // Etichetta del dispositivo, mostrata poi in /devices perché
            // l'utente possa riconoscere e revocare la sessione giusta.
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * L'unicità dell'email NON si valida qui.
     *
     * È unica **per palestra**, e la palestra si conosce solo dopo aver
     * risolto il `join_code`. Una regola `unique:users,email` sarebbe:
     * - sbagliata, perché vieterebbe la stessa email in due palestre diverse;
     * - e un oracolo, perché direbbe a chiunque se un indirizzo è già iscritto.
     *
     * Il controllo vero lo fa il controller dentro il contesto del tenant, e il
     * vincolo `UNIQUE(tenant_id, email)` del database è la rete di sicurezza.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->join_code)) {
            $codice = strtoupper(trim($this->join_code));

            /*
             * 🚨 **Vuoto diventa `null`, e senza questa riga F3 non funziona.**
             *
             * `nullable` salta le regole successive solo su `null` — una stringa
             * vuota **non** è `null`, quindi `size:8` girerebbe lo stesso e
             * fallirebbe. E l'app manda il campo **sempre**: un utente che non
             * scrive il codice invia `''`, non l'assenza del campo.
             *
             * ⚠️ Il risultato sarebbe stato il peggiore possibile: l'iscrizione
             * senza palestra respinta con «il codice deve essere di 8 caratteri»,
             * cioè un errore su un campo che l'utente ha lasciato vuoto **di
             * proposito**.
             */
            $this->merge(['join_code' => $codice === '' ? null : $codice]);
        }
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }

        // Il nome utente si normalizza **prima** della validazione, non dopo:
        // altrimenti `Marco` fallirebbe la regola sul formato pur essendo un
        // nome utente perfettamente legittimo, e l'utente non capirebbe perché.
        if (is_string($this->username)) {
            $this->merge(['username' => strtolower(trim($this->username))]);
        }
    }
}
