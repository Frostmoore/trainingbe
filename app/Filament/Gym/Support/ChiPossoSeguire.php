<?php

declare(strict_types=1);

namespace App\Filament\Gym\Support;

use App\Enums\UserRole;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Tenancy\LoSlotDelTrainer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 👥 **Le persone che chi guarda il pannello può seguire** — F10, 24/09/2026.
 *
 * ══ 🚨 PERCHE' ESISTE, INVECE DI DUE QUERY UGUALI ════════════════════════════
 *
 * ⛔ **La risposta serviva in due posti e l'ho scritta in uno solo.** Il 23/09
 * ho insegnato a `MemberResource` che gli allievi di un trainer indipendente
 * **non stanno tutti nel suo spazio**; il 24/09 il committente ha chiesto
 * *«sei sicuro che l'import da pdf funzioni qui?»* — e no: il menù a tendina
 * di `PlanImportResource::iscritti()` (⛔ **non esiste più dal 27/09/2026**) andava a cercare *«chi ha il ruolo
 * member in questo tenant»*.
 *
 * 📏 Misurato su una trainer con due allievi: ne offriva **uno**. ⚠️ E l'altro
 * spariva **in silenzio** — il menù si apre, dentro c'è un nome, e chi guarda
 * non ha modo di sapere che ne manca uno. 🚨 Su una schermata che assegna un
 * programma di allenamento, il nome mancante è la persona che non lo riceve.
 *
 * 💡 Da qui in avanti la domanda si fa **in un posto solo**, e chi ne aggiunge
 * un terzo la trova già scritta.
 *
 * ── Le tre risposte ─────────────────────────────────────────────────────────
 *
 * | Chi guarda | Cosa vede | Perché |
 * |---|---|---|
 * | Chi **amministra** una palestra | tutti gli iscritti della palestra | è casa sua |
 * | Un **trainer di palestra** | tutti gli iscritti della palestra | decisione del committente, 16/09: *«tutti in lettura»* |
 * | Un **trainer indipendente** | i **suoi** allievi, ovunque abitino | i suoi non hanno un ruolo nel suo tenant: hanno una riga in `trainer_member` |
 */
class ChiPossoSeguire
{
    /**
     * La query degli utenti da mostrare, già filtrata per chi sta guardando.
     *
     * ⚠️ Torna **il vuoto** e non tutto se non c'è nessuno autenticato: in un
     * pannello non capita, ma il valore prudente è quello che non fa danni se
     * un giorno capitasse. È la stessa scelta di `ScopedToTrainer`.
     */
    public static function query(): Builder
    {
        $utente = auth()->user();

        if (! $utente instanceof User) {
            return User::query()->whereRaw('1 = 0');
        }

        /*
         * ══ 🏋️ IL TRAINER INDIPENDENTE PASSA DA `trainer_member` ═════════════
         *
         * 🚨 **Non si può passare dal ruolo nel tenant**, che è la strada di una
         * palestra: i suoi allievi arrivano per due strade diverse, e solo una
         * lo porta nel suo spazio.
         *
         * | Come è arrivato | Dove vive |
         * |---|---|---|
         * | invitato **senza account** | `nasceNelloSpazioDi($trainer)` → **spazio del trainer** |
         * | aveva **già un account** | resta **nel suo** |
         *
         * 💡 `assignedMembers()` toglie già `TenantScope` e filtra il pivot sul
         * tenant **del trainer**, che è chi possiede il rapporto (F6.1).
         *
         * ⚠️ E `nonLiberati()` come fa l'app in `ChiSiVede::allieviDi()`: un
         * rapporto chiuso non torna in elenco. 🚨 Due risposte diverse alla
         * stessa domanda, a seconda di dove si guarda, sono il difetto che il
         * 16/09 ha fatto riscrivere `limitaAgliAssegnati`.
         */
        if ($utente->isFreeTrainer()) {
            return self::iSuoiAllievi($utente);
        }

        // La palestra: chi ha il ruolo `member` **in questo spazio**.
        return User::query()->whereIn('users.id', self::idConRuoloQui(UserRole::Member));
    }

    /**
     * 🆕 **Gli allievi SUOI** — quelli che porta lui, ovunque abitino.
     *
     * ══ ⛔ NON SONO «GLI ISCRITTI ASSEGNATI A LUI» ═════════════════════════
     *
     * 🚨 La differenza è una regola del committente, ripetuta il 16/09, il 23/09
     * e il 24/09: *«I TRAINER DELLA PALESTRA NON HANNO ALLIEVI ASSEGNATI,
     * SEGUONO TUTTI»*.
     *
     * | Domanda | Risposta |
     * |---|---|
     * | «chi posso seguire in questa palestra?» | **tutti** gli iscritti — `query()` |
     * | «quali allievi sono **miei**?» | le righe di `trainer_member` — **questo metodo** |
     *
     * ⛔ Il secondo insieme **non è un sottoinsieme del primo**: un allievo suo
     * può abitare in tutt'altro spazio (F6.1). 💡 Per questo qui si toglie
     * `TenantScope` invece di incrociare con la palestra: incrociando, i suoi
     * allievi di fuori sparirebbero dal conto — **in silenzio**, come sempre.
     *
     * ⚠️ `nonLiberati()` come fa l'app in `ChiSiVede::allieviDi()`: un rapporto
     * chiuso non torna in elenco.
     */
    public static function iSuoiAllievi(User $trainer): Builder
    {
        return User::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->whereIn(
                'users.id',
                $trainer->assignedMembers()
                    ->where(fn ($q) => LoSlotDelTrainer::nonLiberati($q))
                    ->select('users.id'),
            );
    }

    /**
     * Le stesse persone, come `[id => nome]` per un menù a tendina.
     *
     * 💡 Ordinate per nome: un elenco di persone si scorre cercando un nome,
     * non un id.
     */
    public static function perIlMenu(): array
    {
        return self::query()->orderBy('name')->pluck('name', 'users.id')->all();
    }

    /**
     * Gli id di chi ha un dato ruolo **nello spazio corrente**.
     *
     * ⚠️ Non `->role()` di spatie: quella relazione filtra sul team corrente e
     * funzionerebbe, ma genera una sottoquery diversa a ogni versione del
     * pacchetto. Una `whereIn` esplicita è leggibile e non cambia sotto i piedi.
     */
    private static function idConRuoloQui(UserRole $ruolo): \Illuminate\Database\Query\Builder
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', $ruolo->value)
            ->where('model_has_roles.tenant_id', app(TenantContext::class)->id())
            ->select('model_has_roles.model_id');
    }
}
