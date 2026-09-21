<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * La scheda con cui una palestra o un trainer compare nel catalogo — 18/08/2026.
 * **M2.1.**
 *
 * 🚨 **Non ha `BelongsToTenant`, ed e' l'unica tabella del progetto per cui e'
 * giusto.** Il `TenantScope` filtra per la palestra di chi guarda: applicato
 * qui, ogni palestra vedrebbe solo la propria scheda — cioe' il catalogo non
 * mostrerebbe niente a nessuno.
 *
 * ⚠️ Conseguenza da tenere presente: **l'isolamento qui lo fa `visibile`**, non
 * lo scope. Ogni query che legge questa tabella per mostrarla a qualcuno deve
 * filtrare `visibile`, perche' non c'e' nessuna rete sotto.
 */
class ProfiloPubblico extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** ⚠️ Esplicita: Laravel cercherebbe `profilo_pubblicos`. */
    protected $table = 'profili_pubblici';

    /**
     * 🖼️ **L'immagine di testata** — 23/09/2026, fase F6.2 (A2).
     *
     * 📌 *«mettiamoci anche un'immagine di testata (si dovrà vedere
     * nell'app)»*. 💡 Si vede nella pagina dell'**invito**, che è dove oggi una
     * persona incontra la scheda di una palestra.
     */
    public const COLLECTION_TESTATA = 'testata';

    protected $fillable = [
        'tenant_id', 'user_id', 'comune_id', 'titolo', 'descrizione',
        'visibile', 'campagna_id',

        // 🆕 📍 F6.2 (A3) — la via e il CAP: la città la dice già `comune_id`.
        'indirizzo', 'cap',
    ];

    protected function casts(): array
    {
        return [
            'visibile' => 'boolean',
        ];
    }

    public function comune(): BelongsTo
    {
        return $this->belongsTo(Comune::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Il trainer indipendente, quando la scheda e' sua. */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** La campagna pubblicitaria, quando ce n'e' una — M5. */
    public function campagna(): BelongsTo
    {
        return $this->belongsTo(Campagna::class);
    }

    /**
     * 🚨 **Sponsorizzata VUOL DIRE che sta pagando adesso**, non che ha una
     * campagna collegata.
     *
     * ⚠️ Una campagna spenta o col budget esaurito non deve comparire in cima
     * ne' essere etichettata: chi ha finito i soldi torna a essere un risultato
     * come gli altri. Guardare solo `campagna_id` avrebbe lasciato in testa le
     * campagne esaurite — gratis, e togliendo il posto a chi paga.
     */
    public function eSponsorizzata(): bool
    {
        return $this->campagna?->puoComparire() === true;
    }

    /** Se e' la scheda di una palestra e non di un trainer indipendente. */
    public function ePalestra(): bool
    {
        return $this->tenant_id !== null;
    }

    /**
     * 🚨 **A chi arriva un messaggio mandato a questa scheda.**
     *
     * ── Perche' il proprietario e non un referente scelto ──────────────────
     *
     * Per un trainer indipendente e' lui stesso. Per una palestra e' il
     * **proprietario** — `UserRole::GymAdmin` del tenant — e non un dipendente
     * designato, per due ragioni che si tengono insieme:
     *
     * 1. **La cifratura.** La chat e' `crypto_box` con **una chiave pubblica per
     *    persona**. Un messaggio cifrato «per lo staff» vorrebbe dire cifrarlo N
     *    volte (e chi entra dopo non legge comunque il passato) oppure smettere
     *    di cifrarlo punto a punto — cioe' rendere la palestra ⚠️ **l'unico
     *    posto dove noi potremmo leggere**, che e' la crepa esatta nella
     *    promessa fatta in prima pagina.
     * 2. **Chi risponde a «come ci si iscrive» sta trattando un cliente.** E' una
     *    cosa commerciale, non tecnica. Un dipendente che cambia lavoro si porta
     *    via la casella, e chi arriva dopo non trova niente.
     *
     * ── ⚠️ La trappola che resta, e va detta nel pannello ──────────────────
     *
     * Se la palestra cambia proprietario, **le conversazioni gia' avvenute
     * restano illeggibili al successore**. Non e' un difetto: e' la cifratura
     * che funziona come promesso. Ma va scritto, non scoperto.
     *
     * 💡 Torna `null` quando la palestra non ha (ancora) un proprietario: chi
     * legge deve trattarlo come «scheda non contattabile», non come errore.
     */
    public function destinatario(): ?User
    {
        if (! $this->ePalestra()) {
            /*
             * 🚨 `withoutGlobalScopes()`, e senza sarebbe `null`.
             *
             * ⚠️ `User` usa `BelongsToTenant`: la relazione `trainer` viene
             * filtrata dal tenant **corrente**, che qui e' quello di chi guarda
             * il catalogo — un'altra palestra. Il trainer indipendente sta nel
             * suo tenant personale, quindi la relazione non lo troverebbe.
             *
             * 🚨 Il guasto era muto ed esattamente quello gia' visto sul
             * proprietario: la scheda risultava «non contattabile», e chi
             * provava a scrivere riceveva un `409` senza capire perche'.
             *
             * 💡 Non e' un bypass dell'isolamento: `user_id` e' scritto su
             * questa riga, e la scheda e' pubblica per definizione. Si sta
             * risolvendo un identificativo che il catalogo mostra gia'.
             */
            return $this->user_id === null
                ? null
                : User::query()->withoutGlobalScopes()->find($this->user_id);
        }

        return User::query()
            ->withoutGlobalScopes()
            ->where('users.tenant_id', $this->tenant_id)
            ->where('users.is_active', true)
            /*
             * 🚨 **Il pivot si interroga a mano, e non con `whereHas('roles')`.**
             *
             * ⚠️ I ruoli di `spatie/permission` qui sono **per squadra**, e la
             * squadra e' il tenant (`team_foreign_key = tenant_id`). La relazione
             * `roles` aggiunge da sola un filtro sul tenant **corrente**, preso
             * dal contesto: fuori da un `TenantContext::runAs()` quel contesto
             * non c'e', e la condizione non trova niente.
             *
             * 🚨 Il guasto era muto: `destinatario()` rispondeva `null`, il
             * catalogo mostrava la palestra come «non contattabile», e non c'era
             * nessun errore da nessuna parte. L'ha trovato un test.
             *
             * 💡 E il rimedio non e' avvolgere tutto in `runAs()`: questo metodo
             * viene chiamato da una **rotta pubblica**, dove un contesto di
             * palestra non esiste e non deve esistere. Interrogare il pivot con
             * il `tenant_id` **esplicito** — quello della scheda — non dipende da
             * nessuno stato ambientale, ed e' la stessa risposta ovunque la si
             * chieda.
             */
            ->join('model_has_roles', function ($j): void {
                $j->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.tenant_id', $this->tenant_id)
            ->where('roles.name', UserRole::GymAdmin->value)
            ->select('users.*')
            /*
             * ⚠️ `orderBy` e non `first()` nudo: se per un accidente di dati ci
             * fossero due proprietari, senza un ordine la risposta cambierebbe da
             * una chiamata all'altra — e i messaggi finirebbero a persone diverse
             * a seconda di come il database si sveglia.
             */
            ->orderBy('users.id')
            ->first();
    }

    /** @param  Builder<ProfiloPubblico>  $query */
    public function scopeVisibili(Builder $query): void
    {
        $query->where('visibile', true);
    }

    // ───────────────────────── testata e indirizzo ─────────────────────────

    /**
     * Un file solo, come le copertine delle schede. ⚠️ Caricandone un altro il
     * precedente sparisce: una testata è **una**.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION_TESTATA)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();
    }

    public function testataUrl(): ?string
    {
        return $this->getFirstMediaUrl(self::COLLECTION_TESTATA) ?: null;
    }

    /**
     * 📍 **L'indirizzo per esteso**, come si scrive su una busta — F6.2 (A3).
     *
     * 📌 *«serve anche l'indirizzo preciso (con cap provincia e tutto)»*.
     *
     * 💡 «Via Roma, 12 · 01036 Nepi (VT)». La via e il CAP li ha scritti la
     * palestra; città e provincia vengono dal comune — ⛔ scriverli due volte
     * vorrebbe dire due versioni che divergono al primo trasloco.
     *
     * ⚠️ `null` se non c'è **nemmeno la via**: una città senza via non è un
     * indirizzo, e l'app mostrerebbe un pulsante «apri nelle mappe» che porta
     * al centro di Roma.
     */
    public function indirizzoPerEsteso(): ?string
    {
        $via = trim((string) $this->indirizzo);

        if ($via === '') {
            return null;
        }

        $citta = trim(((string) $this->cap).' '.((string) $this->comune?->esteso()));

        return $citta === '' ? $via : "{$via} · {$citta}";
    }

    /**
     * 🗺️ **Il collegamento alle mappe**, per il pulsante dell'app.
     *
     * 💡 L'indirizzo di Google Maps con `api=1` è **universale**: su Android
     * apre l'app Mappe, su iPhone apre Google Maps se c'è e il browser se no.
     * ⛔ Un link `geo:` funzionerebbe solo su Android, e uno di Apple Maps solo
     * su iPhone — cioè l'app dovrebbe sapere su che telefono gira per scegliere.
     *
     * ⚠️ Si cerca per **testo**, non per coordinate: le coordinate che abbiamo
     * sono quelle del **centro del comune**, e porterebbero la gente in piazza
     * invece che davanti alla palestra.
     */
    public function mappaUrl(): ?string
    {
        $indirizzo = $this->indirizzoPerEsteso();

        if ($indirizzo === null) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='
            .rawurlencode(str_replace(' · ', ', ', $indirizzo));
    }
}
