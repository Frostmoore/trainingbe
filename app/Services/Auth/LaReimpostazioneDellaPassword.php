<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Jobs\MandaLaMail;
use App\Mail\LinkPerLaPassword;
use App\Mail\PasswordCambiataMail;
use App\Models\ReimpostazionePassword;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * 🔑 **«Ho dimenticato la password»** — 22/09/2026, fase F4.
 *
 * 📌 *«Eh sì va creata la pagina di reimpostazione password»* · *«mi serve un
 * tasto per mandare il reset password»*.
 *
 * ══ 🚨 IL TOKEN È LEGATO ALLA PERSONA, NON ALL'EMAIL ═════════════════════
 *
 * L'unicità dell'email in questo progetto è **per palestra**, ed è voluta: è il
 * caso di chi iscrive il figlio col proprio indirizzo. ⛔ Il broker di Laravel
 * cerca per email e ne pesca **uno**: una volta su due cambierebbe la password
 * alla persona sbagliata.
 *
 * ══ 🔒 LE TRE REGOLE DI UN LINK CHE APRE UN ACCOUNT ══════════════════════
 *
 * 1. **In tabella ci va l'impronta**, non il token: in chiaro sarebbe una
 *    password temporanea leggibile da chiunque legga il database.
 * 2. **Scade** — un'ora. Più resta valido, più è probabile che finisca a
 *    qualcun altro: inoltrato, in una cronologia, in una casella condivisa.
 * 3. **Si usa una volta sola**, o diventa una seconda password che la persona
 *    non sa di avere.
 *
 * ══ ⚠️ E NON SI DICE MAI SE QUELL'INDIRIZZO ESISTE ═══════════════════════
 *
 * ⛔ «Non esiste nessun account con questa email» è un modo gentile di
 * confermare a un estraneo **quali indirizzi sono iscritti**. La risposta è
 * sempre la stessa: *«se quell'indirizzo è nostro, il link è partito»*.
 */
class LaReimpostazioneDellaPassword
{
    /** ⏳ Un'ora: abbastanza per leggere una mail, poco per perdere un link. */
    public const MINUTI = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Apre una reimpostazione per questa persona e **manda la mail**.
     *
     * @param  User|null  $chiesta  Da chi, se l'ha chiesta qualcuno dal pannello
     * @return string il link, che serve anche a chi lo vuole copiare a mano
     */
    public function apriPer(User $persona, ?User $chiesta = null): string
    {
        $token = Str::random(64);

        $this->context->runWithoutTenant(function () use ($persona, $token, $chiesta): void {
            /*
             * 💡 Le richieste precedenti si spengono: se una persona preme il
             * pulsante tre volte, vale l'ultimo link. ⛔ Tre link vivi insieme
             * sono tre chiavi in giro per la stessa porta.
             */
            ReimpostazionePassword::query()
                ->where('user_id', $persona->getKey())
                ->whereNull('usato_il')
                ->update(['usato_il' => Carbon::now()]);

            ReimpostazionePassword::query()->create([
                'user_id' => $persona->getKey(),
                'impronta' => self::impronta($token),
                'scade_il' => Carbon::now()->addMinutes(self::MINUTI),
                'chiesto_da_id' => $chiesta?->getKey(),
            ]);
        });

        $link = self::linkDi($token);

        /*
         * ⚠️ **La mail non deve far fallire il gesto.** Oggi `MAIL_MAILER` è
         * `log`: la mail «parte» finendo in un file, ed è giusto così finché
         * non c'è Zeptomail. 🚨 Ma il link è già valido, e chi l'ha chiesto dal
         * pannello lo vede a schermo: se il trasporto è rotto, si copia.
         */
        try {
            MandaLaMail::dispatch($persona->email, new LinkPerLaPassword($persona, $link));
        } catch (Throwable $e) {
            Log::error('Reimpostazione password: mail non partita.', [
                'utente' => $persona->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }

        return $link;
    }

    /**
     * La persona di questo token, se il token vale ancora.
     *
     * ⚠️ Torna `null` in tutti e tre i casi — inesistente, scaduto, già usato —
     * e **di proposito**: distinguere aiuterebbe chi prova i token, non chi ha
     * perso la password.
     */
    public function personaDi(string $token): ?User
    {
        $riga = $this->context->runWithoutTenant(
            fn (): ?ReimpostazionePassword => ReimpostazionePassword::query()
                ->where('impronta', self::impronta($token))
                ->whereNull('usato_il')
                ->where('scade_il', '>', Carbon::now())
                ->first(),
        );

        return $riga === null
            ? null
            : User::withoutGlobalScopes()->find($riga->user_id);
    }

    /**
     * Cambia la password e **brucia il token**.
     *
     * @return bool `false` se il link non vale più
     */
    public function completa(string $token, string $password): bool
    {
        return (bool) $this->context->runWithoutTenant(function () use ($token, $password): bool {
            $riga = ReimpostazionePassword::query()
                ->where('impronta', self::impronta($token))
                ->whereNull('usato_il')
                ->where('scade_il', '>', Carbon::now())
                ->first();

            if ($riga === null) {
                return false;
            }

            $persona = User::withoutGlobalScopes()->find($riga->user_id);

            if ($persona === null) {
                return false;
            }

            $persona->forceFill([
                'password' => Hash::make($password),
                'password_is_set' => true,
            ])->save();

            $riga->forceFill(['usato_il' => Carbon::now()])->save();

            /*
             * 🆕 📧 **E si avvisa che è cambiata** — 22/09/2026.
             *
             * 🚨 Una password cambiata senza avviso è il modo in cui un accesso
             * rubato resta invisibile: chi entra nella casella di posta di
             * qualcuno chiede il reset, e il proprietario se ne accorge solo
             * quando non riesce più a entrare.
             *
             * ⚠️ Dentro il `try`: la password **è già cambiata**, e far fallire
             * qui vorrebbe dire dire di no a chi ha appena scelto la password
             * giusta. Se la mail non parte resta nel log.
             */
            try {
                MandaLaMail::dispatch($persona->email, new PasswordCambiataMail($persona));
            } catch (Throwable $e) {
                Log::error('Password cambiata: avviso non partito.', [
                    'utente' => $persona->getKey(),
                    'errore' => $e->getMessage(),
                ]);
            }

            /*
             * 🚨 **Chi cambia la password chiude le sessioni aperte**, e non è
             * zelo: se la password si cambia perché qualcuno l'aveva, quel
             * qualcuno resterebbe dentro con il suo token dell'app.
             */
            $persona->tokens()->delete();

            return true;
        });
    }

    /**
     * L'unica persona con questo indirizzo, se ce n'è **una sola**.
     *
     * 🚨 Con due account sullo stesso indirizzo non si indovina: si risponde
     * la solita frase e si scrive nel registro, perché è un caso che va
     * guardato a mano, non tirato a sorte.
     */
    public function unicaCon(string $email): ?User
    {
        $candidati = User::withoutGlobalScopes()
            ->where('email', mb_strtolower(trim($email)))
            ->where('is_active', true)
            ->get();

        if ($candidati->count() === 1) {
            return $candidati->first();
        }

        if ($candidati->count() > 1) {
            Log::warning('Reimpostazione password: più account con lo stesso indirizzo.', [
                'quanti' => $candidati->count(),
            ]);
        }

        return null;
    }

    public static function linkDi(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/reimposta-password/'.$token;
    }

    private static function impronta(string $token): string
    {
        return hash('sha256', $token);
    }
}
