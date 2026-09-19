<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoConversazione;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use App\Models\Scopes\TenantOrGlobalScope;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Il filo di conversazione fra due persone — B8.1, esteso in M3/M4.
 *
 * ── 🚨 Perche' dal 18/08 usa `BelongsToTenantOrGlobal` ─────────────────────
 *
 * Perche' una conversazione **puo' attraversare due palestre**: dal catalogo,
 * qualcuno del tenant A scrive al proprietario del tenant B. ⚠️ Con
 * `BelongsToTenant` uno dei due non l'avrebbe mai vista — il global scope
 * l'avrebbe filtrata via — e non ci sarebbe stato nessun errore: il messaggio
 * scritto, e il destinatario che non lo trova.
 *
 * 💡 `tenant_id = NULL` vuol dire **«di nessuna palestra»**, visibile in ogni
 * contesto. E' lo stesso schema di `exercises` e `media`.
 *
 * 🚨 **E non e' un buco nell'isolamento.** Il controllo vero su una
 * conversazione non e' mai stato il tenant: e' `includes()`, che confronta i due
 * partecipanti con l'id di **chi chiede**. Indovinare l'id di una conversazione
 * non serve a niente, perche' bisogna comunque *essere* uno dei due. Il tenant
 * era una seconda cintura, e per i fili fra due palestre e' la misura sbagliata.
 */
class Conversation extends Model
{
    use BelongsToTenantOrGlobal;

    protected $fillable = ['tenant_id', 'trainer_id', 'member_id', 'tipo', 'messaggi_di', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'tipo' => TipoConversazione::class,
            'messaggi_di' => 'array',
        ];
    }

    /**
     * 🚨 **Una conversazione di tipo `informazioni` non appartiene a nessuna
     * palestra, e va imposto qui.**
     *
     * ⚠️ `BelongsToTenantOrGlobal` riempie `tenant_id` dal contesto quando e'
     * nullo — che e' giusto per tutto il resto e sbagliato per questi fili: chi
     * scrive dal catalogo ha un contesto (la **sua** palestra), e la
     * conversazione finirebbe assegnata a un tenant che non c'entra niente,
     * rendendola di nuovo invisibile all'altro capo.
     *
     * 💡 Questo ascoltatore si registra **dopo** quello del trait (`booted()`
     * gira in fondo a `boot()`), quindi vince.
     */
    protected static function booted(): void
    {
        static::creating(function (self $c): void {
            if ($c->tipo === TipoConversazione::Informazioni) {
                $c->tenant_id = null;
            }
        });
    }

    /** Se e' un filo nato dal catalogo, e quindi soggetto al limite dei tre. */
    public function eDiInformazioni(): bool
    {
        return $this->tipo === TipoConversazione::Informazioni;
    }

    /**
     * ══ 🆕 SENZA IL GLOBAL SCOPE, E PER LA STESSA IDENTICA RAGIONE ═══════════
     *
     * ⛔ **Il modello era stato liberato dal tenant, le due relazioni no.** Una
     * conversazione nata dal catalogo attraversa due tenant — lo dice il
     * dartdoc qui sopra — quindi l'altra persona sta **sempre** dall'altra
     * parte: `User` usa `BelongsToTenant`, e il global scope la filtrava via.
     *
     * 🚨 Il risultato l'ha mostrato l'emulatore il 14/09: l'elenco dei messaggi
     * disegnava una riga con **«—» al posto del nome** e un cerchio vuoto al
     * posto dell'iniziale. L'API rispondeva `with: {id: null, name: null}` —
     * nessun errore, una conversazione con nessuno.
     *
     * ⚖️ **E non apre niente.** Chi legge il nome è già passato da `includes()`,
     * che confronta i due partecipanti con l'id di chi chiede: per arrivare qui
     * bisogna **essere** uno dei due. Sapere con chi si sta parlando è il minimo
     * che una conversazione debba dire — e il nome della palestra, nel caso del
     * catalogo, era già pubblico prima che il filo esistesse.
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id')
            ->withoutGlobalScopes([TenantScope::class]);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id')
            ->withoutGlobalScopes([TenantScope::class]);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * 🚨 L'unico controllo di accesso alla chat.
     *
     * Non basta essere della stessa palestra: una conversazione e' fra **due**
     * persone. Un altro trainer della stessa palestra non deve leggerla — e in
     * modalita' teams il global scope da solo lo lascerebbe passare.
     */
    public function includes(User|int $utente): bool
    {
        $id = $utente instanceof User ? $utente->getKey() : $utente;

        return $this->trainer_id === $id || $this->member_id === $id;
    }

    /** L'altro partecipante, visto da chi guarda. */
    public function otherParty(User $utente): ?User
    {
        return $utente->getKey() === $this->trainer_id ? $this->member : $this->trainer;
    }

    /**
     * La conversazione fra due persone, creandola se non c'e'.
     *
     * `firstOrCreate` sul vincolo unico: due tocchi ravvicinati su una rete
     * lenta creerebbero altrimenti due thread paralleli, e i due si
     * scriverebbero in stanze diverse senza capire perche'.
     */
    public static function between(User $trainer, User $membro, TipoConversazione $tipo = TipoConversazione::Iscritto): self
    {
        /*
         * ⚠️ **Il tipo NON entra nella chiave di ricerca**, ed e' deliberato.
         *
         * Fra due persone c'e' **un filo solo**: se qualcuno ha scritto a una
         * palestra dal catalogo e poi si e' iscritto, la conversazione dev'essere
         * la stessa — con la storia che continua, non una seconda stanza vuota.
         *
         * 🚨 E' anche la meta' del meccanismo di M4.4: diventare iscritti
         * **sblocca la conversazione esistente** invece di aprirne un'altra.
         *
         * ══ 🆕 SI CERCA SENZA IL FILTRO DEL TENANT — 16/09/2026 ══════════════
         *
         * 🚨 Un `firstOrCreate` normale cerca **dentro il tenant corrente**: se
         * il filo esiste ma vive altrove, non lo trova, prova a crearlo e sbatte
         * sul vincolo unico — errore 500, e l'app non apre una chat che c'e'.
         *
         * ⚠️ Non è teorico: succede al trainer con un giro suo. La
         * conversazione con un iscritto della palestra nasce **nella palestra**
         * (`$membro->tenant_id`), ma lui la riapre dal **proprio** spazio.
         * Provato: 201 al primo tocco, 500 al secondo.
         *
         * 💡 Non è un bypass: la coppia di id è già la chiave più stretta
         * possibile — è il vincolo unico stesso — e chi ha il diritto di stare
         * in questo filo l'ha deciso `coppia()` un attimo fa. È lo stesso
         * ragionamento, e lo stesso gesto, di `CancelloDellaChat::canaleChiuso()`.
         */
        return static::query()
            ->withoutGlobalScopes([TenantOrGlobalScope::class])
            ->firstOrCreate(
                ['trainer_id' => $trainer->getKey(), 'member_id' => $membro->getKey()],
                [
                    'tipo' => $tipo,
                    // 💡 Per un filo di informazioni resta `null` comunque: ci
                    // pensa l'ascoltatore in `booted()`.
                    'tenant_id' => $membro->tenant_id,
                ],
            );
    }

    /**
     * I fili di questa persona.
     *
     * ══ 🆕 GLI SPAZI A CUI APPARTIENE, NON SOLO QUELLO CORRENTE — 16/09 ═════
     *
     * 🚨 Il global scope limita al tenant **corrente**, e per il trainer con un
     * giro suo quello è il **proprio** spazio: la conversazione con un iscritto
     * della palestra nasce **nella palestra** e da lì spariva. ⛔ L'avrebbe
     * aperta, scritto dentro, e poi non trovata più — né nell'elenco né
     * aprendola per id, perché `conversazioneDi()` passa da qui.
     *
     * ✅ Si sostituisce il filtro con quello giusto: **i tenant di cui questa
     * persona fa parte** — il suo, più le palestre dove ha il ruolo di trainer,
     * più i fili di nessuno (`NULL`, quelli nati dal catalogo).
     *
     * ══ 🆕 🚨 E ADESSO NEMMENO QUELLO: CHI NE FA PARTE LA VEDE — D9, 19/09 ══
     *
     * ⛔ **Qui c'era scritto «chi esce da una palestra continua a non vederle,
     * e deve restarci».** Il filtro sui tenant lo faceva: uscendo, il filo con
     * il trainer della palestra spariva dall'elenco — e aprendolo per id dava
     * 404. Il testo della dissociazione lo diceva pure: *«la conversazione
     * resta alla palestra»*.
     *
     * 📌 Il committente, 19/09: *«La conversazione non deve né sparire né
     * nascondersi mai. Se io mi disiscrivo da un trainer, al massimo non gli
     * posso più scrivere ma i messaggi vecchi ce li devo avere»*.
     *
     * 💡 Quindi il filtro e' **uno solo: farne parte**. Il tenant del filo
     * dice dove e' nato, non chi puo' rileggerlo. ⚠️ **Scrivere e' un'altra
     * cosa**, e lo decide `CancelloDellaChat::puoScrivere()`: se i due non sono
     * piu' legati, il filo e' in sola lettura.
     *
     * ⚖️ Non si allarga niente a nessun altro: `trainer_id` o `member_id` deve
     * essere quella persona, come prima.
     */
    public function scopeForUser(Builder $query, User|int $utente): Builder
    {
        $id = $utente instanceof User ? $utente->getKey() : $utente;

        return $query
            ->withoutGlobalScopes([TenantOrGlobalScope::class])
            ->where(fn (Builder $q) => $q->where('trainer_id', $id)->orWhere('member_id', $id));
    }

    /**
     * L'ordine dell'elenco: l'ultimo messaggio in cima.
     *
     * 🚨 **Il secondo criterio non e' decorativo.** `last_message_at` ha la
     * precisione del secondo: due messaggi nello stesso secondo — che su una
     * chat attiva capita — lascerebbero l'ordine deciso dal motore, cioe'
     * diverso a ogni richiesta. L'elenco «salterebbe» sotto le dita di chi lo
     * guarda, senza nessun errore da nessuna parte.
     */
    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('last_message_at')->orderByDesc('id');
    }

    /** Quanti messaggi non letti ha questa persona in questa conversazione. */
    public function unreadFor(User|int $utente): int
    {
        $id = $utente instanceof User ? $utente->getKey() : $utente;

        return $this->messages()
            ->where('sender_id', '!=', $id)
            ->whereNull('read_at')
            ->count();
    }
}
