<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\LaVerificaDellEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * ✅ **La pagina che conferma l'indirizzo** — voce 62.6, 28/09/2026.
 *
 * ══ 🔑 PERCHE' UNA PAGINA WEB E NON UNA ROTTA DELL'APP ════════════════════
 *
 * 💡 Il link si tocca **dalla posta**, e la posta si legge spesso da un altro
 * dispositivo — il computer, il tablet, la casella del lavoro. ⛔ Una rotta che
 * pretendesse l'app installata su **quel** dispositivo non funzionerebbe proprio
 * nel caso più comune.
 *
 * ⚖️ È la stessa scelta degli inviti, che una pagina web ce l'hanno già.
 *
 * ══ 🚨 LA FIRMA NON BASTA, E IL PERCHE' ═══════════════════════════════════
 *
 * ⚠️ Il middleware `signed` garantisce che il link **l'abbiamo fatto noi** e che
 * non è scaduto. ⛔ Non garantisce che l'indirizzo sia rimasto quello: un link
 * mandato a `vecchia@…` continuerebbe a valere dopo un cambio di email, e
 * toccandolo verificherebbe **il nuovo** indirizzo, che nessuno ha confermato.
 *
 * 🔑 Per questo si controlla anche l'**impronta**, che lega il link all'indirizzo
 * di allora.
 */
class VerificaEmailController extends Controller
{
    public function __construct(
        private readonly LaVerificaDellEmail $verifica,
        private readonly TenantContext $contesto,
    ) {}

    public function __invoke(Request $request, int $id, string $hash): View
    {
        /*
         * ⚠️ `runWithoutTenant` + `withoutGlobalScopes()`: questa pagina è
         * **pubblica**, non c'è nessuna palestra nel contesto, e la persona da
         * verificare può stare in qualunque spazio. 🚨 Senza, la ricerca
         * dipenderebbe da dove capita di essere — che è il difetto che
         * `PushSender` aveva sui token.
         */
        $persona = $this->contesto->runWithoutTenant(
            fn (): ?User => User::query()
                ->withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->find($id),
        );

        if ($persona === null || ! $this->verifica->lImprontaTorna($persona, $hash)) {
            /*
             * 🚨 **Una pagina sola per «non esiste» e per «l'impronta non
             * torna».** ⛔ Distinguerle direbbe a chi prova un link a caso se
             * quell'id esiste, e l'unica cosa che ci guadagna è chi cerca.
             */
            Log::info('Verifica email: link non valido.', ['id' => $id]);

            return view('sito.verifica-email', ['esito' => 'non-valido']);
        }

        $eraDaFare = $this->verifica->conferma($persona);

        return view('sito.verifica-email', [
            'esito' => $eraDaFare ? 'fatto' : 'gia-fatto',
            'nome' => $persona->name,
        ]);
    }
}
