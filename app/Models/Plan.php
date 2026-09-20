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

        // ✂️ 21/09/2026 — chi comanda su questa riga: il seeder o una persona.
        'cucito_a_mano',
        // G1 — D5, D6, D7.
        'max_trainers',

        // 🆕 3b-AH.6 — il piano a consumo: si paga a iscritto, con un minimo.
        'prezzo_iscritto_cent', 'canone_cent', 'minimo_iscritti',
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
            'cucito_a_mano' => 'boolean',
            'max_members' => 'integer',
            'price_cents' => 'integer',
            'max_trainers' => 'integer',
            'prezzo_iscritto_cent' => 'integer',
            'canone_cent' => 'integer',
            'minimo_iscritti' => 'integer',
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

    /**
     * ✂️ **I piani cuciti a mano dal pannello** — 21/09/2026.
     *
     * 📌 *«creami la possibilità di creare nuovi abbonamenti per palestre con
     * il numero di trainers che voglio io»*.
     *
     * ⚠️ **Non è la stessa cosa di «non pubblico»**: non pubblici sono anche i
     * piani ritirati, che `PlanSeeder` riscrive a ogni deploy. Solo questi si
     * possono modificare dal pannello senza che la modifica sparisca.
     */
    public function scopeCucitiAMano(Builder $query): Builder
    {
        return $query->where('cucito_a_mano', true);
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
     * 🆕 **Si paga a iscritto invece che a canone?** — 3b-AH.6 (13/09/2026).
     *
     * 📌 *«Se una palestra sceglie il piano a consumo pagherà un tot per ogni
     * iscritto a cui regala l'abbonamento, per un minimo di 100.»*
     *
     * ⚠️ **`price_cents = 0` su un piano a consumo è vero, non un buco**: non
     * c'è nessun canone. Chi mostra un prezzo deve chiedere **prima** questo
     * metodo, o scriverà «gratis» sul piano più caro del listino.
     */
    public function aConsumo(): bool
    {
        return $this->prezzo_iscritto_cent !== null;
    }

    /**
     * Quanto costa un mese con questo numero di iscritti abbonati.
     *
     * 🚨 **Il minimo si applica al CONTEGGIO, non al totale.** Una palestra con
     * 40 iscritti su un minimo di 100 paga **100 × il prezzo**: è il senso della
     * soglia. ⛔ Applicarlo al totale — «almeno tot euro» — sembrerebbe la stessa
     * cosa e non lo è appena il prezzo a iscritto cambia.
     *
     * ⚠️ Torna `null` per i piani a canone: lì il prezzo è `price_cents` e non
     * dipende da quanti sono.
     */
    public function costoDelMese(int $iscrittiAbbonati): ?int
    {
        if (! $this->aConsumo()) {
            return null;
        }

        /*
         * 🆕 **Il canone si somma, e si paga anche a zero iscritti** — 20/09/2026.
         *
         * 📌 Il listino del committente: *«2,80€/mese per allievo + 10€/mese»*
         * (trainer) e *«+ 15€/mese»* (palestra). 🚨 Il canone non è un balzello:
         * il servizio costa anche il mese in cui non si regala niente — i dati
         * restano, le chat restano, il server gira.
         */
        return (int) ($this->canone_cent ?? 0)
            + max($iscrittiAbbonati, $this->minimo_iscritti ?? 0) * $this->prezzo_iscritto_cent;
    }
}
