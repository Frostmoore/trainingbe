<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * La palestra cliente. È il tenant della piattaforma.
 *
 * NON usa BelongsToTenant: è la tabella radice, non appartiene a nessuno.
 */
class Tenant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'join_code', 'status', 'plan',
        'logo_path', 'color_primary', 'color_secondary', 'color_accent',
        'contact_email', 'locale', 'timezone',
        'ai_monthly_token_cap', 'ai_driver', 'settings', 'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'ai_monthly_token_cap' => 'integer',
        ];
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // ───────────────────────── stato ─────────────────────────

    /**
     * Gli utenti di questa palestra possono entrare?
     *
     * Un trial scaduto blocca comunque, anche se lo stato è ancora `trial`:
     * altrimenti basterebbe non toccare il record per avere accesso illimitato.
     */
    public function isActive(): bool
    {
        if (! $this->status->allowsLogin()) {
            return false;
        }

        if ($this->status === TenantStatus::Trial && $this->trial_ends_at !== null) {
            return $this->trial_ends_at->isFuture();
        }

        return true;
    }

    // ───────────────────────── branding ─────────────────────────

    /**
     * Il payload dell'endpoint pubblico /api/v1/branding/lookup.
     *
     * È l'unica cosa che l'app scarica prima di autenticarsi, quindi qui NON
     * deve finire nulla oltre al branding: l'endpoint è pubblico e diventerebbe
     * un modo per enumerare i clienti.
     */
    public function branding(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'logo_url' => $this->logo_path ? url($this->logo_path) : null,
            'colors' => [
                'primary' => $this->color_primary,
                'secondary' => $this->color_secondary,
                'accent' => $this->color_accent,
            ],
            'locale' => $this->locale,
        ];
    }

    // ───────────────────────── quote AI ─────────────────────────

    /** Token AI consumati nel mese corrente (input + output). */
    public function aiTokensUsedThisMonth(): int
    {
        // TODO(B6.4): implementare quando esiste la tabella ai_usage_logs.
        return 0;
    }

    public function hasAiQuotaLeft(): bool
    {
        if ($this->ai_monthly_token_cap === null) {
            return true;
        }

        return $this->aiTokensUsedThisMonth() < $this->ai_monthly_token_cap;
    }

    // ───────────────────────── utilità ─────────────────────────

    /** Codice d'invito leggibile: niente 0/O/1/I, che al telefono si confondono. */
    public static function generateJoinCode(): string
    {
        do {
            $code = Str::upper(Str::password(8, letters: true, numbers: true, symbols: false));
            $code = str_replace(['0', 'O', '1', 'I', 'L'], ['2', '3', '4', '5', '6'], $code);
        } while (static::where('join_code', $code)->exists());

        return $code;
    }
}
