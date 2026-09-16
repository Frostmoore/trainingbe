<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 🚪 **Congedare un trainer che ha un giro suo** — 3b-AJ.6, 16/09/2026.
 *
 * ══ 🚨 PERCHE' SERVIVA UN GESTO APPOSTA ═══════════════════════════════════
 *
 * ⛔ **La palestra non aveva nessun modo di mandarlo via.** Dal pannello ci sono
 * solo *spegni l'account* e *cancella l'account*, che stanno sull'**account** e
 * non sul rapporto con quella palestra: userebbero una porta globale per
 * chiudere una faccenda locale, e chiuderebbero quella persona fuori **anche da
 * casa sua**.
 *
 * ✅ `UserPolicy` glielo impedisce già — confronta i `tenant_id`, e quello di un
 * trainer con un giro suo punta al proprio spazio. ⚠️ Ma il rovescio era che non
 * poteva **nemmeno congedarlo**.
 *
 * ══ 📌 COSA RESTA A CHI, DETTO DAL COMMITTENTE ════════════════════════════
 *
 * > *«Le schede restano sia dell'allievo che del trainer. Il trainer le ha
 * > scritte, mi pare ridicolo togliergliele; l'allievo le ha pagate, mi pare un
 * > furto togliergliele.»*
 *
 * 💡 Sono **due proprietari**, non uno, e il sistema lo sa fare perché una
 * scheda consegnata vive in due posti: la riga sul server e la copia **sul
 * telefono di chi l'ha ricevuta**, arrivata dalla chat cifrata (D11/D13).
 *
 * | Cosa | Dove va |
 * |---|---|
 * | I suoi **allievi personali** | ⛔ non si toccano: stanno nel suo spazio, e la palestra non c'entra |
 * | Gli **iscritti della palestra** | restano alla palestra: non erano suoi |
 * | Le **schede e i piani che ha scritto** | 🎯 tornano **nel suo spazio** |
 * | Le schede **già consegnate** | restano anche a chi le ha ricevute: sono sul suo telefono |
 * | La **libreria esercizi** della palestra | resta alla palestra, ma lui continua a **leggerla** |
 *
 * ⚠️ **L'ultima riga non è un dettaglio**: le sue schede parlano di esercizi
 * della palestra. Portarsi via le schede senza il permesso di leggere quegli
 * esercizi vorrebbe dire ritrovarsi righe mute — non cancellate, **peggio**. È
 * lo stesso ragionamento, e lo stesso gesto, di
 * `EsciDaUnaPalestra::ricordaLaLibreria()`.
 *
 * ══ ⛔ E CHI NON HA UN GIRO SUO ═══════════════════════════════════════════
 *
 * 🚨 Questo servizio è **solo** per chi ha uno spazio proprio. Un trainer che
 * vive dentro la palestra non ha un posto dove tornare: congedarlo così lo
 * lascerebbe con un account senza ruoli, dentro uno spazio che non è suo. ⚠️ Per
 * lui la strada resta quella di sempre — lo spegne o lo cancella la palestra, e
 * `ChiEsceDallaPalestraOsservatore` chiude i rapporti.
 */
final class TogliUnTrainerDallaPalestra
{
    public function __construct(private readonly TenantContext $contesto) {}

    public function __invoke(User $trainer, Tenant $palestra): void
    {
        $this->pretendiCheSiaUnCasoDaGestire($trainer, $palestra);

        DB::transaction(function () use ($trainer, $palestra): void {
            $ruoli = $this->togliIRuoli($trainer, $palestra);
            $rapporti = $this->chiudiIRapportiDiQuellaPalestra($trainer, $palestra);
            $schede = $this->riportaACasaIlSuoLavoro($trainer, $palestra);

            $this->ricordaLaLibreria($trainer, $palestra);

            Log::info('Un trainer e stato congedato da una palestra', [
                'user_id' => $trainer->getKey(),
                'tenant_id' => $palestra->getKey(),
                'ruoli_tolti' => $ruoli,
                'rapporti_chiusi' => $rapporti,
                'righe_tornate_a_casa' => $schede,
            ]);
        });
    }

    /**
     * ⚠️ **Due rifiuti, e dicono due cose diverse.**
     *
     * 💡 Chi chiama è il pannello, quindi i messaggi li legge una persona: «non
     * lavora qui» e «non ha un posto dove tornare» portano a due gesti diversi,
     * e un messaggio solo per tutti e due manderebbe a tentativi.
     */
    private function pretendiCheSiaUnCasoDaGestire(User $trainer, Tenant $palestra): void
    {
        if ($trainer->tenant_id === $palestra->getKey()) {
            throw ValidationException::withMessages([
                'trainer' => __('Questo trainer non ha uno spazio suo: per congedarlo si disattiva o si cancella il suo account.'),
            ]);
        }

        $haIlRuolo = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $trainer->getKey())
            ->where('model_has_roles.model_type', $trainer->getMorphClass())
            ->where('model_has_roles.tenant_id', $palestra->getKey())
            ->where('roles.name', UserRole::Trainer->value)
            ->exists();

        if (! $haIlRuolo) {
            throw ValidationException::withMessages([
                'trainer' => __('Questa persona non allena in questa palestra.'),
            ]);
        }
    }

    /**
     * 🚨 **Tutti i ruoli in quel tenant, non solo `trainer`.**
     *
     * 💡 Se domani gliene fosse stato dato un secondo — poniamo `gym_admin` — e
     * qui si togliesse solo il primo, resterebbe dentro con l'altro: un congedo
     * che non congeda, e nessun errore da nessuna parte.
     */
    private function togliIRuoli(User $trainer, Tenant $palestra): int
    {
        return DB::table('model_has_roles')
            ->where('model_id', $trainer->getKey())
            ->where('model_type', $trainer->getMorphClass())
            ->where('tenant_id', $palestra->getKey())
            ->delete();
    }

    /**
     * ⛔ **Solo i rapporti di QUESTA palestra**, e in tutte e due le direzioni.
     *
     * 🚨 Senza il filtro sul tenant si chiuderebbero anche i suoi allievi
     * personali — che è precisamente ciò che il committente ha chiesto di non
     * far succedere, ed è lo stesso difetto già corretto in
     * `ChiEsceDallaPalestraOsservatore` il 16/09.
     *
     * ⚠️ Si **disattivano**, non si cancellano (D5): la storia resta, il canale
     * si chiude. E si toccano solo quelli ancora in piedi, perché uno chiuso sei
     * mesi fa conserva la sua data.
     */
    private function chiudiIRapportiDiQuellaPalestra(User $trainer, Tenant $palestra): int
    {
        return $this->contesto->runWithoutTenant(
            fn (): int => DB::table('trainer_member')
                ->where('tenant_id', $palestra->getKey())
                ->whereNull('disattivato_il')
                ->where(fn ($q) => $q
                    ->where('trainer_id', $trainer->getKey())
                    ->orWhere('member_id', $trainer->getKey()))
                ->update(['disattivato_il' => now()]),
        );
    }

    /**
     * 🎯 **Le schede e i piani che ha scritto lui tornano nel suo spazio.**
     *
     * 📌 *«Il trainer le ha scritte, mi pare ridicolo togliergliele.»*
     *
     * ⚠️ **Due condizioni, e servono tutte e due:**
     *
     * 1. `created_by` è lui — il lavoro di un collega resta dov'è;
     * 2. 🚨 `member_id IS NULL` — cioè **non è di nessun iscritto**.
     *
     * 💡 La seconda è quella che protegge l'altra metà della regola. Una riga con
     * `member_id` valorizzato è una scheda che **un iscritto si è scritto da
     * solo** dall'app (dal pannello quel campo non esiste più da D4): portarla
     * via sarebbe prendere la roba di qualcun altro.
     *
     * ⚖️ E le schede che ha **consegnato** non serve spostarle: sono già sul
     * telefono di chi le ha ricevute, arrivate dalla chat cifrata. Restano a
     * tutti e due senza che nessuno debba muovere niente.
     *
     * @return array<string, int>
     */
    private function riportaACasaIlSuoLavoro(User $trainer, Tenant $palestra): array
    {
        $tornate = [];

        return $this->contesto->runWithoutTenant(function () use ($trainer, $palestra, &$tornate): array {
            foreach (['workout_plans', 'nutrition_plans'] as $tabella) {
                $righe = DB::table($tabella)
                    ->where('tenant_id', $palestra->getKey())
                    ->where('created_by', $trainer->getKey())
                    ->whereNull('member_id')
                    ->update(['tenant_id' => $trainer->tenant_id]);

                if ($righe > 0) {
                    $tornate[$tabella] = $righe;
                }
            }

            return $tornate;
        });
    }

    /**
     * ⚖️ **La libreria esercizi resta leggibile.**
     *
     * 🚨 Le schede che si porta a casa parlano di esercizi **della palestra**,
     * che restano alla palestra. ⛔ Senza il permesso di leggerli si ritroverebbe
     * righe mute: non cancellate, **peggio**.
     *
     * 💡 Dà lettura e basta, ed è idempotente: la stessa palestra non ci finisce
     * due volte.
     */
    private function ricordaLaLibreria(User $trainer, Tenant $palestra): void
    {
        $viste = $trainer->librerie_viste ?? [];
        $viste[] = $palestra->getKey();

        $trainer->forceFill([
            'librerie_viste' => array_values(array_unique(array_map(intval(...), $viste))),
        ])->save();
    }
}
