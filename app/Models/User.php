<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * Un utente appartiene a una palestra — tranne il super admin, che ha
 * `tenant_id = null` ed e' l'unico caso legittimo.
 *
 * Usa BelongsToTenant come tutti gli altri modelli: il global scope non filtra
 * a contesto vuoto, quindi il pannello /god continua a vedere tutti. Escluderlo
 * qui avrebbe significato una regola in meno da rispettare, e le regole con
 * un'eccezione sono quelle che si dimenticano.
 */
#[Fillable([
    'tenant_id', 'name', 'email', 'password', 'phone',
    'avatar_path', 'locale', 'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasMedia
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant;
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use InteractsWithMedia;
    use Notifiable;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // ───────────────────────── pannelli ─────────────────────────

    /**
     * Chi entra in quale pannello Filament.
     *
     * Tre regole, in ordine di severita':
     *  - `god`   → solo il super admin;
     *  - `admin` → gym_admin e trainer, ma solo se l'utente e' attivo E la sua
     *              palestra lo e' (un abbonamento scaduto chiude anche il
     *              pannello, non solo l'app);
     *  - gli ISCRITTI non entrano MAI in un pannello: usano solo l'app. E' un
     *    `false` esplicito e non una conseguenza dei ruoli, cosi' aggiungere un
     *    permesso per sbaglio non gli apre una porta.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'god' => $this->isSuperAdmin(),
            'admin' => ($this->isGymAdmin() || $this->isTrainer())
                && $this->tenant?->isActive() === true,
            default => false,
        };
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** Gli iscritti seguiti da questo trainer. */
    public function assignedMembers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'trainer_member', 'trainer_id', 'member_id')
            ->withPivot(['tenant_id', 'assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    /** I trainer che seguono questo iscritto. */
    public function assignedTrainers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'trainer_member', 'member_id', 'trainer_id')
            ->withPivot(['tenant_id', 'assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    // ───────────────────────── ruoli ─────────────────────────

    /**
     * I controlli di ruolo passano tutti da qui.
     *
     * `hasRole()` di spatie e' gia' limitato al tenant corrente grazie a
     * TenantTeamResolver, quindi «e' trainer» significa sempre «e' trainer in
     * QUESTA palestra».
     */
    public function hasAppRole(UserRole $role): bool
    {
        return $this->hasRole($role->value);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasAppRole(UserRole::SuperAdmin);
    }

    public function isGymAdmin(): bool
    {
        return $this->hasAppRole(UserRole::GymAdmin);
    }

    public function isTrainer(): bool
    {
        return $this->hasAppRole(UserRole::Trainer);
    }

    public function isMember(): bool
    {
        return $this->hasAppRole(UserRole::Member);
    }

    // ───────────────────────── utilita' ─────────────────────────

    /**
     * Ultimo peso registrato.
     *
     * TODO(B4.2): leggerlo da `body_metrics` quando la tabella esistera'.
     */
    public function latestWeight(): ?float
    {
        return null;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? url($this->avatar_path) : null;
    }
}
