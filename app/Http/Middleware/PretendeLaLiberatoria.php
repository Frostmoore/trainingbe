<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Privacy\LeLiberatorie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ✍️ **Il pannello non si apre finché la liberatoria non è firmata** — 20/09/2026.
 *
 * 📌 Il committente: *«dovrà firmare la liberatoria specifica per le palestre»*.
 *
 * 🚨 **Un muro, non un avviso.** Un banner che si può ignorare lascerebbe
 * guardare l'elenco degli iscritti a chi non ha ancora accettato niente: l'atto
 * dell'art. 28 serve **prima** del trattamento, non durante.
 *
 * ⚠️ La pagina della firma resta raggiungibile, o il muro chiuderebbe anche la
 * porta per passarlo. Stessa ragione per `logout`: chi non vuole firmare deve
 * poter uscire.
 */
class PretendeLaLiberatoria
{
    /** Le rotte che restano aperte: la firma stessa, e l'uscita. */
    private const APERTE = ['*/liberatoria', '*/logout', 'livewire/*'];

    public function __construct(private readonly LeLiberatorie $liberatorie) {}

    public function handle(Request $request, Closure $next): Response
    {
        $persona = $request->user();

        if ($persona === null || $request->is(...self::APERTE)) {
            return $next($request);
        }

        $tipo = $this->liberatorie->serveA($persona);

        if ($tipo === null) {
            return $next($request);
        }

        /*
         * 💡 Si manda alla pagina del pannello corrente, non a un URL fisso:
         * il pannello della palestra e quello della piattaforma hanno prefissi
         * diversi, e scriverne uno solo qui li legherebbe per sempre.
         */
        return redirect(filament()->getCurrentPanel()?->getPath().'/liberatoria' ?? '/admin/liberatoria');
    }
}
