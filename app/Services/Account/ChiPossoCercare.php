<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\UserRole;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Chat\ChiHoBloccato;
use App\Services\Tenancy\ChiSiVede;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 🔎 **Chi posso trovare col nome utente** — blocco B3, 24/09/2026.
 *
 * 📌 Decisione 53.2 del committente, il 24/09: *«Utenti e trainer possono
 * cercare trainer e palestre. Gli utenti individuali possono essere cercati
 * solo dai loro trainer o dalle loro palestre (inclusi i trainer di quella
 * palestra)»*.
 *
 * ══ 🚨 PERCHE' IL CANCELLO STA IN UNA CLASSE SOLA ═══════════════════════════
 *
 * ⛔ Perché è già successo, **tre volte in due giorni**, con la domanda gemella
 * *«chi posso seguire»*: scritta in tre posti, sbagliata in due, e ogni volta
 * spariva qualcuno **in silenzio** (vedi `ChiPossoSeguire`, atlante be §146.5).
 *
 * 🚨 Qui sbagliare costa di più: una regola allentata non nasconde una persona,
 * ne **espone** una che non voleva essere trovata.
 *
 * ── Le tre condizioni, in ordine ────────────────────────────────────────────
 *
 * | # | Condizione | Perché |
 * |---|---|---|
 * | 1 | ha acceso **«fatti trovare»** | 53.1: la trovabilità è una scelta, e nasce **spenta** |
 * | 2 | **chi è** decide chi lo trova | 53.2: un professionista lo trova chiunque, un iscritto **solo i suoi** |
 * | 3 | ⛔ **nessun blocco** fra i due, in nessun verso | 53.3: altrimenti il blocco sarebbe una porta chiusa accanto a una aperta |
 *
 * ⚠️ **Conseguenza voluta, e non ovvia**: un trainer **non può trovare un
 * allievo nuovo** col nome utente. È l'allievo che cerca il trainer e gli
 * scrive; l'invito per link ed email resta la strada per chi l'app non ce l'ha
 * ancora (voce 11 di `DA-FARE.md`), che è il caso più frequente di tutti.
 */
class ChiPossoCercare
{
    /** ⚠️ Il tetto: senza, una ricerca per «a» sarebbe un modo di scaricare la rubrica. */
    public const QUANTI_AL_MASSIMO = 10;

    public function __construct(
        private readonly ChiSiVede $chiSiVede,
        private readonly ChiHoBloccato $blocchi,
    ) {}

    /**
     * Le persone che **questa** persona può trovare, fra quelle il cui nome
     * utente comincia per `$pezzo`.
     *
     * 🚨 **Comincia per, non «contiene»**: `LIKE '%mar%'` su una tabella di
     * persone è un modo di leggerla tutta, tre lettere alla volta. 💡 Chi cerca
     * un nome utente lo **sa**: non lo sta indovinando.
     *
     * @return Builder<User>
     */
    public function query(User $chiCerca, string $pezzo): Builder
    {
        $pezzo = IlNomeUtente::normalizza($pezzo);

        if (mb_strlen($pezzo) < 3) {
            /*
             * ⛔ Sotto le tre lettere non si cerca: due lettere sono **mille
             * persone**, e un elenco di mille persone non è una ricerca, è un
             * archivio. ⚠️ È anche il minimo del formato (53.5c).
             */
            return User::query()->whereRaw('1 = 0');
        }

        $query = User::query()
            /*
             * 🚨 `withoutGlobalScopes([TenantScope::class])`: si cerca **fra
             * tutti**, non dentro il proprio spazio. ⛔ Con lo scope attivo un
             * trainer indipendente non troverebbe mai nessuno, perché nel suo
             * spazio non c'è quasi nessuno — e non darebbe **nessun errore**.
             */
            ->withoutGlobalScopes([TenantScope::class])
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('trovabile', true)
            ->whereNotNull('username')
            ->where('username', 'like', $pezzo.'%')
            ->whereKeyNot($chiCerca->getKey());

        // ⛔ Condizione 3: chi ha bloccato, e chi è stato bloccato, non si trovano.
        $query = $this->blocchi->toglieiBloccati($query, $chiCerca);

        /*
         * ══ Condizione 2: chi è il cercato decide chi lo trova ══════════════
         *
         * 💡 Si esprime come **una sola** `where` con due rami in OR, e non
         * come due query: un professionista lo trova chiunque; un iscritto lo
         * trovano solo quelli che già lo seguono.
         */
        return $query->where(function (Builder $q) use ($chiCerca): void {
            $q->whereIn('users.id', self::idDeiProfessionisti())
                ->orWhereIn('users.id', $this->idDeiMieiAllievi($chiCerca));
        });
    }

    /**
     * 🏋️ **Chi fa questo di mestiere**: trainer di palestra e indipendenti.
     *
     * 💡 Si guarda il **ruolo, in qualunque spazio**: una persona che allena da
     * qualche parte è un professionista anche se nella sua palestra di casa è
     * un iscritto. ⚠️ È la stessa lettura di `User::allena()`, che il 24/09 ha
     * già risolto sei giorni di login rotto (atlante be §145).
     */
    private static function idDeiProfessionisti(): \Illuminate\Database\Query\Builder
    {
        return DB::table('model_has_roles as mhr')
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'mhr.role_id')->on('r.tenant_id', '=', 'mhr.tenant_id');
            })
            ->whereIn('r.name', [UserRole::Trainer->value, UserRole::FreeTrainer->value])
            ->where('mhr.model_type', (new User)->getMorphClass())
            ->select('mhr.model_id');
    }

    /**
     * 👥 **Gli iscritti che questa persona già segue**, e che quindi può
     * trovare anche per nome utente.
     *
     * 📌 *«i loro trainer o le loro palestre (inclusi i trainer di quella
     * palestra)»*.
     *
     * | Chi cerca | Cosa gli si apre |
     * |---|---|
     * | un **trainer indipendente** | i **suoi** allievi (`trainer_member`), ovunque abitino |
     * | chi **amministra** una palestra, o ci **allena** | gli iscritti **di quella palestra** — in palestra li segue ogni trainer |
     *
     * @return list<int>
     */
    private function idDeiMieiAllievi(User $chiCerca): array
    {
        /*
         * 💡 `allieviDi()` risponde **già** per tutti e due i cappelli del
         * trainer: *«i suoi, più quelli delle palestre in cui allena»*. ⛔ Non
         * si riscrive qui: è la stessa regola che il 24/09 ha fatto danni
         * proprio perché era scritta in tre posti.
         */
        $ids = $this->chiSiVede->allieviDi($chiCerca)->pluck('id')->all();

        /*
         * ⚠️ **Ma chi AMMINISTRA una palestra non "allena"**, quindi da
         * `allieviDi()` non esce niente — e la decisione dice *«i loro trainer
         * **o le loro palestre**»*. 🚨 Senza queste righe un titolare non
         * troverebbe nemmeno i propri iscritti.
         */
        $suePalestre = DB::table('model_has_roles as mhr')
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'mhr.role_id')->on('r.tenant_id', '=', 'mhr.tenant_id');
            })
            ->where('mhr.model_id', $chiCerca->getKey())
            ->where('mhr.model_type', (new User)->getMorphClass())
            ->where('r.name', UserRole::GymAdmin->value)
            ->pluck('mhr.tenant_id');

        if ($suePalestre->isNotEmpty()) {
            $ids = array_merge($ids, DB::table('model_has_roles as mhr')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'mhr.role_id')->on('r.tenant_id', '=', 'mhr.tenant_id');
                })
                ->whereIn('mhr.tenant_id', $suePalestre)
                ->where('r.name', UserRole::Member->value)
                ->pluck('mhr.model_id')
                ->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
