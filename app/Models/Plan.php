<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un piano del listino — F4.1.
 *
 * ⚠️ **NON usa `BelongsToTenant`**, come `Tenant`: il listino è della
 * piattaforma, non di un cliente. Se avesse `tenant_id`, `TenantIsolationTest`
 * pretenderebbe il trait — ed è giusto che lo pretenda, quindi la colonna non
 * c'è proprio.
 *
 * 🚨 **I codici sono la fonte di verità, i nomi no.** «Plus» un giorno si
 * chiamerà «Pro» perché lo decide il marketing; `plans.code = 'plus'` resta.
 * Nel codice e nei test si cerca sempre per `code`.
 */
class Plan extends Model
{
    /** Il gratuito: è il piano di chi non ne ha scelto nessuno. */
    public const FREE = 'free';

    /** Persona che paga: stima da testo e da foto, consiglio del giorno. */
    public const PLUS = 'plus';

    public const TRAINER_FREE = 'trainer_free';

    /**
     * ⚠️ **Ritirato dal listino in G1, non cancellato.**
     *
     * Aveva `max_members = null` — allievi illimitati — a 19,99 €, cioe' meno
     * del tier da 30. Con la quota per allievo (D8) il costo per noi cresce con
     * gli allievi mentre il prezzo sta fermo: e' un piano che perde soldi tanto
     * piu' quanto ha successo.
     *
     * 🚨 Resta in tabella perche' `plan_subscriptions` punta a `plans.id`:
     * cancellarlo lascerebbe senza piano chi ce l'ha. `is_public = false` lo
     * toglie dal listino senza togliere niente a nessuno.
     */
    public const TRAINER_PRO = 'trainer_pro';

    public const TRAINER_10 = 'trainer_10';

    public const TRAINER_30 = 'trainer_30';

    /** ⚠️ Ritirato dal listino in G1 come `TRAINER_PRO`, e per la stessa ragione. */
    public const GYM = 'gym';

    public const GYM_5 = 'gym_5';

    public const GYM_15 = 'gym_15';

    protected $fillable = [
        'code', 'name', 'kind', 'ai_enabled',
        'max_members', 'price_cents', 'is_public',
        // G1 — D5, D6, D7.
        'max_trainers', 'max_members_per_trainer',
        'ai_monthly_calls_per_member', 'ai_monthly_photo_calls_per_member',
    ];

    protected $attributes = [
        // ⚠️ Come su `Tenant`: i default del DB si applicano all'INSERT, non
        // all'oggetto in memoria. Senza, `Plan::make()->ai_enabled` sarebbe
        // `null` — e `null` su un cancello di sicurezza è un `false` che sembra
        // un errore di configurazione invece di una decisione.
        'ai_enabled' => false,
        'kind' => 'person',
        'price_cents' => 0,
        'is_public' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => PlanKind::class,
            'ai_enabled' => 'boolean',
            'is_public' => 'boolean',
            'max_members' => 'integer',
            'price_cents' => 'integer',
            'max_trainers' => 'integer',
            'max_members_per_trainer' => 'integer',
            'ai_monthly_calls_per_member' => 'integer',
            'ai_monthly_photo_calls_per_member' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PlanSubscription::class);
    }

    public function scopePubblici(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeDiTipo(Builder $query, PlanKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }

    /** Il prezzo in euro, per il listino. */
    public function prezzoEuro(): float
    {
        return $this->price_cents / 100;
    }

    public function eGratuito(): bool
    {
        return $this->price_cents === 0;
    }

    /**
     * Quante chiamate all'AI al mese concede questo piano a ciascuna persona.
     *
     * ⚠️ `null` = «non lo decide il piano»: `MemberAiQuota` scende al default di
     * sistema. `0` = illimitato. Sono le convenzioni di tutti e cinque i livelli
     * della catena, e valgono anche qui.
     */
    public function chiamateAlMese(): ?int
    {
        return $this->ai_monthly_calls_per_member;
    }

    /**
     * Quante di quelle chiamate possono essere **stime da foto** — D7.
     *
     * 🚨 **E' un sotto-limite, non un budget a parte.** Una foto consuma sia
     * questo contatore sia quello generale: chi ha 400 chiamate di cui 40 con
     * foto, dopo 40 foto ha 360 chiamate rimaste, non 400.
     */
    public function chiamateConFotoAlMese(): ?int
    {
        return $this->ai_monthly_photo_calls_per_member;
    }

    /**
     * Quanti allievi puo' seguire **ciascun trainer** di una palestra — D5.
     *
     * 🚨 **Solo per i piani `PlanKind::Gym`.** Su un piano trainer il limite e'
     * `max_members`, che vuol dire un'altra cosa: «quanti allievi seguo io».
     * Chiedere questo a un piano trainer torna `null`, che nella catena vuol
     * dire «non lo decide questo livello» — ed e' la risposta giusta, non un
     * caso non gestito.
     */
    public function tettoAllieviPerTrainer(): ?int
    {
        return $this->kind === PlanKind::Gym ? $this->max_members_per_trainer : null;
    }
}
