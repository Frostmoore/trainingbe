<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * L'abbonamento di un tenant a un piano — F4.1.
 *
 * 🚨 **Usa `BelongsToTenant`**, e non è una formalità: `TenantIsolationTest`
 * enumera i modelli con `tenant_id` e pretende il trait. Senza, una palestra
 * vedrebbe gli abbonamenti — e quindi i piani e i prezzi — di tutte le altre.
 *
 * ── ⚠️ Perché la storia si conserva invece di sovrascrivere una colonna ────
 *
 * Perché la domanda «perché a marzo questo cliente aveva l'AI e ad aprile no?»
 * deve avere una risposta. Con una sola colonna `tenants.plan_id` quella
 * risposta sarebbe «non si sa»: l'unico stato conservato sarebbe l'ultimo.
 */
class PlanSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'plan_id', 'starts_at', 'ends_at',
        'stripe_subscription_id', 'stripe_customer_id', 'rinnova',

        // 🆕 3b-AH 8.1.6 — la pausa di chi entra in una palestra.
        'in_pausa_dal', 'in_pausa_per_tenant_id', 'restavano_giorni',

        // 🆕 🎁 P3 di plan_promozioni.md — il premio «prezzo bloccato».
        'prezzo_bloccato',

        // 🆕 🎀 P4 — da quale promozione viene un abbonamento regalato o un upgrade.
        'promozione_assegnazione_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'in_pausa_dal' => 'datetime',
            'restavano_giorni' => 'integer',
            'prezzo_bloccato' => 'boolean',

            // 🆕 §9.4 — la prima volta che un rinnovo è stato rifiutato.
            'pagamento_fallito_il' => 'datetime',

            /*
             * 🆕 ⏳ voce 62 — quando gli si è detto che stava scadendo.
             *
             * ⚠️ Serve il cast, non basta la colonna: `booted()` confronta
             * `ends_at` con `getOriginal()`, e senza cast il confronto fra una
             * data e una stringa dipenderebbe da come MariaDB l'ha scritta.
             */
            'scadenza_avvisata_il' => 'datetime',
        ];
    }

    /**
     * 🆕 ⏸️ **È fermo perché questa persona è entrata in una palestra?**
     *
     * ⚠️ Diverso da «scaduto»: un abbonamento in pausa **riprenderà**, e la
     * data di fine non è stata toccata. Vedi `LaPausaDellAbbonamento`.
     */
    public function eInPausa(): bool
    {
        return $this->in_pausa_dal !== null;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Gli abbonamenti in corso **adesso**.
     *
     * 🚨 `ends_at IS NULL` significa **«non scade»**, non «scaduto». Leggerlo al
     * contrario spegnerebbe l'AI a ogni cliente che non ha una data di fine —
     * cioè a tutti quelli che pagano regolarmente.
     *
     * ⚠️ E `starts_at <= now()`: un abbonamento firmato oggi e valido dal primo
     * del mese prossimo non deve dare accesso da subito.
     */
    public function scopeAttivi(Builder $query): Builder
    {
        return $query
            /*
             * 🆕 🚨 **Un abbonamento in pausa NON è attivo** — 3b-AH 8.1.6.
             *
             * ⛔ Senza questa riga la pausa sarebbe solo decorativa: la persona
             * entra in palestra, viene coperta dalla palestra, e **resta anche
             * abbonata** — due coperture per la stessa cosa, e nessun errore.
             *
             * ⚠️ Sta **nello scope** e non nei chiamanti perché i chiamanti sono
             * tanti — `PianoAttivo`, `AddebitoDelMese`, `InvitiInPalestra`,
             * `Cassa` — e basterebbe dimenticarsene in uno.
             */
            ->whereNull('in_pausa_dal')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', now())
            );
    }

    public function eAttivo(): bool
    {
        if ($this->eInPausa()) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    /**
     * 🆕 ⏳ **Se la scadenza si allontana, l'avviso si dimentica** — voce 62,
     * 27/09/2026.
     *
     * ══ 📌 COSA FA, E PERCHE' STA QUI ═════════════════════════════════════
     *
     * `scadenza_avvisata_il` è la memoria di «gli abbiamo detto che stava
     * scadendo» (vedi `LAvvisoDellaScadenza`). ⚠️ Quando l'abbonamento viene
     * **allungato**, quella memoria è vecchia: la scadenza di cui si parlava non
     * esiste più, e la prossima va annunciata da capo.
     *
     * ⛔ Senza questo, la **seconda** scadenza passerebbe in silenzio — un difetto
     * che si vede una volta al mese e che nessuno collegherebbe a una colonna.
     *
     * ══ 🔑 PERCHE' NEL MODELLO E NON NEI CHIAMANTI ════════════════════════
     *
     * 🚨 `ends_at` viene allungato da **cinque posti diversi**:
     * `Cassa::abbonamentoRinnovato()` e `abbonamentoAggiornato()`,
     * `RinnovaGliAbbonamentiRegalati`, `PianiInVendita`, `LaPausaDellAbbonamento`.
     * ⛔ Azzerare la colonna in ognuno sarebbe cinque sedi della stessa regola, e
     * la prima che qualcuno dimentica non dà **nessun errore**: dà solo un avviso
     * che non parte, mesi dopo.
     *
     * 💡 Qui invece la regola è attaccata al **fatto** — «la scadenza si è
     * spostata in avanti» — e non a chi lo fa. ⚖️ È la stessa ragione per cui il
     * canale delle notifiche sta nell'enum e non nei chiamanti.
     *
     * ══ ⚠️ SOLO IN AVANTI, E IL CASO `null` ═══════════════════════════════
     *
     * 🚨 `ends_at = null` significa **«non scade»**, non «scaduto» — e quindi è il
     * massimo degli allontanamenti: l'avviso va dimenticato. ⛔ Leggerlo come
     * «scaduto» è il difetto che `scopeAttivi()` documenta qui sopra, e non va
     * rifatto qui.
     *
     * 💡 Se la data si avvicina o resta uguale — una disdetta, una revoca — la
     * memoria **si tiene**: quella scadenza è la stessa di cui si è già parlato,
     * o una anticipata, e in nessuno dei due casi si riavvisa.
     */
    protected static function booted(): void
    {
        static::updating(function (self $abbonamento): void {
            if (! $abbonamento->isDirty('ends_at')) {
                return;
            }

            /*
             * 🚨 **Niente scorciatoia del tipo «se è già null non fare niente»**
             * — e ci sono cascato il 27/09, il test `se_la_scadenza_si_allontana`
             * l'ha preso subito.
             *
             * ⛔ Quella guardia guarderebbe `scadenza_avvisata_il` **di questa
             * istanza**, che può essere **vecchia**: `LAvvisoDellaScadenza` scrive
             * la colonna su una riga che ha caricato lui, quindi un oggetto preso
             * prima ha ancora `null` in memoria mentre il database ha una data.
             * 🚨 Con la scorciatoia si usciva subito e la colonna restava piena.
             *
             * 💡 Azzerarla sempre non costa niente — se è già `null`, Eloquent non
             * la vede nemmeno come modificata — e non dipende da quando è stata
             * letta la riga.
             */
            $prima = $abbonamento->getOriginal('ends_at');
            $adesso = $abbonamento->ends_at;

            /* 🔑 `null` = non scade più: l'avviso mandato non vale più niente. */
            if ($adesso === null) {
                $abbonamento->scadenza_avvisata_il = null;

                return;
            }

            if ($prima === null) {
                return;
            }

            if ($adesso->greaterThan(Carbon::parse($prima))) {
                $abbonamento->scadenza_avvisata_il = null;
            }
        });
    }
}
