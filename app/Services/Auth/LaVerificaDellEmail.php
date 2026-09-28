<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Jobs\MandaLaMail;
use App\Mail\VerificaLEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * ✅ **La verifica dell'email** — voce 62.6, 28/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE, E A COSA SERVE DAVVERO ═════════════════════════════
 *
 * 📌 Il committente: *«Eh, facciamola, che ci vuole, mi pare che su laravel sia
 * tipo un paio di righe di codice»*, parlando dell'invito che deve portare alla
 * schermata dell'invito.
 *
 * 🔑 **Non nasce per sé stessa**: nasce perché senza di lei la rotta «i miei
 * inviti» non si può fare. ⛔ Quella rotta consegna **codici monouso**, e chi si
 * registra con l'indirizzo di un altro si prenderebbe i suoi inviti — mentre
 * oggi, per rubarne uno, serve l'accesso alla casella di posta.
 *
 * ══ 🚨 E NON BLOCCA L'ACCESSO — LA REGOLA IN UNA RIGA ═════════════════════
 *
 * ⛔ **Il middleware `verified` sta su una rotta sola**: `GET inviti-ricevuti`.
 * Non sul login, non sull'app, non sui pannelli.
 *
 * 🚨 La **voce 63** dice *«NIENTE AL MONDO DEVE IMPEDIRE A UN UTENTE DI
 * ACCEDERE»*, e una verifica dell'email sull'accesso è il cancello che chiude
 * fuori chi ha cambiato casella, chi non ha ricevuto la mail, chi l'ha persa
 * nello spam. ⚠️ Il DMARC del dominio è in `p=none` da ieri: le nostre mail non
 * sono ancora al sicuro dallo spam, e legarci l'accesso sarebbe temerario.
 *
 * ══ ⚖️ PERCHE' UN LINK WEB E NON UN CODICE NELL'APP ═══════════════════════
 *
 * 💡 Il link firmato apre una **pagina web** che conferma e basta. ⛔ Un codice
 * da ricopiare nell'app vorrebbe dire una schermata nuova, una tastiera e un
 * errore possibile; il link si tocca dalla posta e funziona anche da un altro
 * dispositivo. 🔑 Ed è la stessa forma degli inviti, che una pagina web ce
 * l'hanno già.
 */
class LaVerificaDellEmail
{
    /**
     * ⏳ **Quanto vale il link.**
     *
     * ⚖️ Sessanta minuti: abbastanza per accorgersi della mail e toccarla,
     * poco perché un link dimenticato in una casella resti utile a lungo.
     * 💡 È il doppio del reset della password, perché qui non c'è niente da
     * difendere se non l'indirizzo stesso.
     */
    public const MINUTI = 60;

    /**
     * 📧 Manda (o rimanda) la mail con il link.
     *
     * ⚠️ **Non lancia e non dice se è partita**: la mail va in coda come tutte
     * le altre dal 28/09. 💡 Chi chiama sa solo che è stata presa in carico.
     *
     * @return bool `false` se non c'era niente da mandare — già verificata,
     *              o senza indirizzo
     */
    public function manda(User $persona): bool
    {
        if ($persona->hasVerifiedEmail()) {
            return false;
        }

        if ($persona->email === null || $persona->email === '') {
            return false;
        }

        MandaLaMail::dispatch(
            $persona->email,
            new VerificaLEmailMail($persona, $this->link($persona)),
        );

        return true;
    }

    /**
     * 🔗 Il link firmato che conferma l'indirizzo.
     *
     * ══ 🔑 PERCHE' C'E' L'IMPRONTA DELL'EMAIL NEL LINK ════════════════════
     *
     * ⛔ Con il solo id, un link mandato a `vecchia@…` continuerebbe a valere
     * dopo che la persona ha cambiato indirizzo in `nuova@…`: toccandolo
     * verificherebbe **il nuovo**, che nessuno ha mai confermato.
     *
     * 💡 L'impronta lega il link **all'indirizzo di allora**: cambiando email, i
     * link vecchi smettono di funzionare da soli. ⚖️ È lo stesso schema di
     * Laravel, e la ragione è questa.
     */
    public function link(User $persona): string
    {
        return URL::temporarySignedRoute(
            'verifica.email',
            now()->addMinutes(self::MINUTI),
            [
                'id' => $persona->getKey(),
                'hash' => sha1((string) $persona->email),
            ],
        );
    }

    /**
     * ✅ Segna l'indirizzo come verificato, se non lo era già.
     *
     * 💡 `false` quando lo era: ⛔ non è un errore, è un link toccato due volte —
     * e succede spesso, perché le app di posta li precaricano.
     */
    public function conferma(User $persona): bool
    {
        if ($persona->hasVerifiedEmail()) {
            return false;
        }

        $persona->forceFill(['email_verified_at' => now()])->save();

        return true;
    }

    /**
     * 🔎 L'impronta nel link corrisponde all'indirizzo di adesso?
     *
     * 🚨 Va controllata **anche** con la firma valida: la firma dice che il link
     * l'abbiamo fatto noi, ⛔ non che l'indirizzo sia rimasto quello.
     */
    public function lImprontaTorna(User $persona, string $hash): bool
    {
        return hash_equals(sha1((string) $persona->email), $hash);
    }
}
