<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 🆔 **Il nome utente: una regola sola, in un posto solo** — blocco B3, 24/09/2026.
 *
 * ══ 🚨 PERCHE' NASCE UNA CLASSE PER UNA STRINGA ═════════════════════════════
 *
 * Perché dal 24/09 quel nome **non è più solo un modo di accedere**: diventa il
 * nome con cui una persona si fa trovare dentro l'app. E con quel cambio
 * arrivano tre regole che prima non c'erano, decise dal committente
 * (`memory/decisioni.md`):
 *
 * | Regola | Decisione |
 * |---|---|
 * | si cambia **una volta ogni 30 giorni** | 53.5b — cambiarlo di continuo è il modo di sfuggire a chi ti ha bloccato |
 * | **mai riciclato** | 53.4 — un nome riassegnato fa arrivare a un'altra persona i messaggi destinati alla prima |
 * | **facoltativo** | 53.5a — lo si chiede quando serve, cioè quando qualcuno accende «fatti trovare» |
 *
 * ⛔ Scritte dentro un controller, quelle tre regole varrebbero **solo da lì**:
 * il pannello, un comando, un seeder ne avrebbero altre. 🚨 E la terza — il
 * riciclo — non fallisce in modo visibile: il nome viene riassegnato, tutto
 * funziona, e i messaggi arrivano alla persona sbagliata.
 *
 * ══ ⚠️ IL FORMATO NON CAMBIA, ED E' UNA DECISIONE ═══════════════════════════
 *
 * Minuscolo, `a-z0-9._-`, da 3 a 30, **niente `@`** — committente, 53.5c:
 * *«quelli di adesso»*. 💡 Il divieto della chiocciola non è estetica: senza,
 * un nome utente potrebbe essere scambiato per un'email al momento del login,
 * dove `AuthController` accetta tutti e due.
 */
class IlNomeUtente
{
    /** 📅 Quanto dura la finestra fra un cambio e l'altro (53.5b). */
    public const GIORNI_FRA_DUE_CAMBI = 30;

    /** Il formato, in una espressione sola. ⚠️ Vedi il blocco in testa. */
    public const FORMATO = '/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/';

    /**
     * Le regole di validazione, per chi si registra e per chi lo cambia.
     *
     * 🚨 **Non contengono `required`**: quello lo decide chi chiama, perché il
     * nome è facoltativo alla registrazione (53.5a) ma **obbligatorio** nel
     * modulo che lo cambia — lì mandarlo vuoto vorrebbe dire cancellarlo per
     * sbaglio.
     *
     * @return list<mixed>
     */
    public static function regole(?User $tranne = null): array
    {
        return [
            'string',
            'min:3',
            'max:30',
            'regex:'.self::FORMATO,
            $tranne === null
                ? Rule::unique('users', 'username')
                : Rule::unique('users', 'username')->ignore($tranne->getKey()),

            /*
             * 🚨 **E non deve essere un nome bruciato.** ⛔ Senza questa riga la
             * regola 53.4 varrebbe solo per i nomi ancora attaccati a un
             * account: quelli di chi ha cancellato tornerebbero liberi, che è
             * esattamente il caso che la decisione vieta.
             */
            Rule::unique('nomi_utente_bruciati', 'nome'),
        ];
    }

    /** Se questo nome si può prendere adesso. */
    public function eLibero(string $nome, ?User $tranne = null): bool
    {
        $nome = self::normalizza($nome);

        if ($nome === '' || preg_match(self::FORMATO, $nome) !== 1) {
            return false;
        }

        $preso = User::withoutGlobalScopes()
            ->withTrashed()
            ->where('username', $nome)
            ->when($tranne !== null, fn ($q) => $q->whereKeyNot($tranne?->getKey()))
            ->exists();

        return ! $preso && ! DB::table('nomi_utente_bruciati')->where('nome', $nome)->exists();
    }

    /**
     * 📅 **Quando potrà cambiarlo**, o `null` se può adesso.
     *
     * 💡 La finestra parte dal **primo cambio**, non dalla registrazione: chi
     * non l'ha mai cambiato lo può fare subito, e non è un premio — è che il
     * limite difende dal *cambiare in continuazione*, non dallo scegliere.
     */
    public function quandoPuoCambiare(User $utente): ?Carbon
    {
        $ultimo = $utente->username_cambiato_il;

        if ($ultimo === null) {
            return null;
        }

        $quando = $ultimo->copy()->addDays(self::GIORNI_FRA_DUE_CAMBI);

        return $quando->isFuture() ? $quando : null;
    }

    /**
     * Cambia il nome utente, bruciando quello vecchio.
     *
     * 🚨 **Il vecchio si brucia, non si libera.** ⛔ Altrimenti basterebbe
     * cambiare nome per liberare il proprio, farlo prendere a un altro, e
     * dirottargli addosso i messaggi che cercavano noi.
     *
     * @throws ValidationException se è troppo presto, o se il nome non si può prendere
     */
    public function cambia(User $utente, string $nuovo): void
    {
        $nuovo = self::normalizza($nuovo);
        $vecchio = $utente->username;

        if ($nuovo === $vecchio) {
            return;
        }

        $quando = $this->quandoPuoCambiare($utente);

        if ($quando !== null) {
            throw ValidationException::withMessages([
                'username' => __('Potrai cambiarlo di nuovo dal :data.', [
                    'data' => $quando->locale('it')->isoFormat('D MMMM YYYY'),
                ]),
            ]);
        }

        if (! $this->eLibero($nuovo, $utente)) {
            throw ValidationException::withMessages([
                'username' => __('Questo nome utente non è disponibile.'),
            ]);
        }

        DB::transaction(function () use ($utente, $nuovo, $vecchio): void {
            $utente->forceFill([
                'username' => $nuovo,
                'username_cambiato_il' => Carbon::now(),
            ])->save();

            $this->brucia($vecchio);
        });
    }

    /**
     * 🔥 **Brucia un nome**: da ora in poi non lo prende più nessuno.
     *
     * 📌 Decisione 53.4 del committente: *«mai riciclati, non avrebbe alcun
     * senso»*.
     *
     * ⚠️ Si chiama anche dalla **cancellazione dell'account**
     * (`AccountEraser::anonimizzaUtente`), che mette `username = null`: senza,
     * quel nome tornerebbe libero nel momento peggiore — quando la persona che
     * lo usava non c'è più per accorgersene.
     *
     * 💡 `insertOrIgnore`: bruciare due volte lo stesso nome non è un errore, è
     * la stessa cosa detta due volte.
     */
    public function brucia(?string $nome): void
    {
        $nome = self::normalizza((string) $nome);

        if ($nome === '') {
            return;
        }

        DB::table('nomi_utente_bruciati')->insertOrIgnore([
            'nome' => $nome,
            'bruciato_il' => Carbon::now(),
        ]);
    }

    /**
     * ⚠️ Minuscolo e senza spazi ai lati, **sempre**: `Marco` e `marco` non
     * devono diventare due account che all'occhio sono lo stesso.
     */
    public static function normalizza(string $nome): string
    {
        return mb_strtolower(trim($nome));
    }
}
