<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * 🔕 **Disiscriversi dalle email promozionali, senza entrare** — voce 43,
 * art. 130, c. 4, Codice privacy. 25/09/2026.
 *
 * 📌 Il committente, 25/09: *«in ogni email promozionale per disiscriversi
 * dalle email promozionali. Quelle transazionali o urgenti restano»*.
 *
 * ══ 🚨 PERCHE' SENZA LOGIN ═════════════════════════════════════════════════
 *
 * ⚠️ L'art. 130, c. 4 vuole che ci si possa opporre **«con modalità
 * semplificate e gratuitamente»**. ⛔ Un collegamento che porta a una schermata
 * di accesso non è una modalità semplificata: chi non ricorda la password
 * rinuncia, e l'opposizione resta teorica. 💡 Chi ha ricevuto quell'email ha già
 * dimostrato di avere accesso a quella casella: è quello il fattore.
 *
 * 🔑 La difesa è la **firma** (`signed`): l'indirizzo lo può costruire solo chi
 * ha `APP_KEY`, quindi solo noi. ⛔ Senza, l'id nell'URL basterebbe a
 * disiscrivere chiunque tirando a indovinare un numero.
 *
 * ══ ⚠️ DUE PASSI, E NON PER BUROCRAZIA ═════════════════════════════════════
 *
 * 🚨 I filtri antispam e le anteprime dei client di posta **aprono i link da
 * soli** per controllarli. ⛔ Un `GET` che esegue vorrebbe dire persone
 * disiscritte da un antivirus, senza aver toccato niente — e la prima volta che
 * succede non lo capisce nessuno.
 *
 * 💡 Quindi il `GET` **mostra** e basta; a spegnere è il `POST`. ⚠️ E resta una
 * modalità semplificata: un clic nell'email, un bottone nella pagina.
 *
 * 🔑 **E c'è la terza strada, quella vera**: l'header `List-Unsubscribe-Post`
 * (RFC 8058) dice al client di posta che può chiamare quel `POST` da solo. Così
 * chi usa Gmail o Apple Mail preme **«Annulla iscrizione» nel client** e ha
 * finito — senza aprire niente. 💡 È il motivo per cui il `POST` non chiede
 * conferme: la conferma l'ha già data premendo quel pulsante.
 */
class NienteOfferteController extends Controller
{
    /**
     * La pagina con il bottone. ⚠️ Non spegne niente: vedi sopra.
     */
    public function mostra(Request $request, int $user): View
    {
        $utente = $this->suo($user);

        return view('sito.niente-offerte', [
            'titolo' => 'Non ricevere più offerte',
            'email' => $utente?->email,

            /*
             * ⚠️ **Si dice subito se è già spento**, invece di far premere un
             * bottone che non cambia niente: chi arriva qui da una mail vecchia
             * ha il diritto di sapere che l'aveva già fatto.
             */
            'giaFatto' => $utente?->niente_offerte === true,

            /*
             * 🔑 L'indirizzo del `POST`, firmato a sua volta. ⛔ Senza firma
             * anche qui, la pagina sarebbe un modulo che chiunque può inviare
             * per chiunque.
             */
            'azione' => self::indirizzoDelPost($user),
        ]);
    }

    /**
     * Spegne le offerte. 🚨 È questo che cambia qualcosa.
     */
    public function spegni(Request $request, int $user): RedirectResponse
    {
        $utente = $this->suo($user);

        if ($utente !== null && ! $utente->niente_offerte) {
            /*
             * ⚠️ `forceFill`: non è un campo che si scrive da un modulo. E la
             * data serve a dimostrare **da quando** vale l'opposizione.
             */
            $utente->forceFill([
                'niente_offerte' => true,
                'niente_offerte_dal' => now(),
            ])->save();
        }

        /*
         * 💡 Si torna alla stessa pagina, che adesso dirà «già fatto». ⛔ Una
         * pagina di conferma diversa vorrebbe dire due viste da tenere
         * allineate per dire la stessa cosa.
         */
        return redirect()->to(self::indirizzo($user));
    }

    /**
     * 🔗 **Il collegamento da mettere nelle email promozionali.**
     *
     * ⚠️ **Senza scadenza**, ed è deliberato: una persona può ritrovare una
     * nostra email di sei mesi fa e volersi disiscrivere allora. ⛔ Un link
     * scaduto la porterebbe a una pagina d'errore — cioè le direbbe che il
     * diritto è scaduto, che non è vero.
     */
    public static function indirizzo(int $user): string
    {
        return URL::signedRoute('sito.niente-offerte', ['user' => $user]);
    }

    /** Lo stesso, per il `POST`: è quello che spegne davvero. */
    public static function indirizzoDelPost(int $user): string
    {
        return URL::signedRoute('sito.niente-offerte.spegni', ['user' => $user]);
    }

    /**
     * ⚠️ **`withoutGlobalScopes()`**: questa rotta è pubblica e non ha un
     * tenant. ⛔ Senza, l'utente non si troverebbe mai e il link direbbe a
     * tutti «non esisti».
     *
     * 💡 E `null` non è un errore da mostrare: chi arriva con un id che non
     * c'è più vede la stessa pagina degli altri. 🚨 Rispondere «questo account
     * non esiste» a un indirizzo pubblico vorrebbe dire trasformare il link in
     * un modo per **verificare se una persona è iscritta**.
     */
    private function suo(int $user): ?User
    {
        return User::withoutGlobalScopes()->find($user);
    }
}
