<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;
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
    'tenant_id', 'name', 'email', 'username', 'password', 'phone',
    'avatar_path', 'locale', 'is_active',
])]
// `is_super_admin` NON è fillable di proposito: si concede solo da seeder o da
// console, mai per assegnazione di massa da una richiesta HTTP. Un campo del
// genere in `$fillable` è una scalata di privilegi in attesa di un controller
// distratto che passi `$request->all()`.
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

    /**
     * Devono rispecchiare i `->default()` delle migration.
     *
     * 🚨 Non è ridondanza. I default del database si applicano all'`INSERT`,
     * **non all'oggetto in memoria**: senza questi, subito dopo `User::create()`
     * `is_active` vale `null`, e `canAccessPanel()` — che comincia proprio con
     * `if (! $this->is_active)` — respinge l'utente dal suo stesso pannello.
     *
     * Il guasto è invisibile a chi rilegge l'utente con `find()` o `fresh()`
     * prima di usarlo, ed è per questo che era passato inosservato: si
     * manifesta solo nel percorso reale, dove si usa l'oggetto appena creato.
     * Stessa trappola già pagata su `Tenant`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'it',
        'is_active' => true,
        'is_super_admin' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',

            /*
             * G8 — se questa persona ha una password che conosce.
             *
             * Chi entra con Google o Apple ne riceve comunque una, casuale:
             * guardare la colonna `password` non dice niente. Serve a non
             * proporgli un modulo che chiede «la password attuale» — che non
             * ha mai saputo.
             */
            'password_is_set' => 'boolean',

            /*
             * Il tetto AI **di questa persona** — C20.
             *
             * ⚠️ Volutamente **fuori da `$fillable`**: e' una concessione, e le
             * concessioni non si assegnano in massa da una richiesta HTTP. Si
             * imposta dal pannello o da console, come `is_super_admin`.
             *
             * `null` = usa quello della palestra (e poi il default di sistema).
             * `0` = illimitato. Sono due cose diverse: vedi `MemberAiQuota`.
             */
            'ai_monthly_token_cap' => 'integer',
        ];
    }

    // ───────────────────────── identificativi ─────────────────────────

    /**
     * Nome utente sempre in minuscolo.
     *
     * Chi lo digita non pensa alle maiuscole, e un vincolo `UNIQUE` che
     * distingue `Marco` da `marco` lascerebbe registrare due account che
     * all'occhio sono lo stesso — il modo classico per farsi passare per un
     * altro. Normalizzare qui vale per ogni percorso: pannello, API, seeder.
     */
    protected function username(): Attribute
    {
        return Attribute::set(
            fn (?string $value): ?string => $value === null || trim($value) === ''
                ? null
                : mb_strtolower(trim($value)),
        );
    }

    protected function email(): Attribute
    {
        return Attribute::set(
            fn (string $value): string => mb_strtolower(trim($value)),
        );
    }

    /**
     * Trova l'utente da un identificativo, email o nome utente che sia.
     *
     * 🚨 Risolve **noi**, invece di lasciar fare a `attempt()`, per un motivo
     * preciso: l'email è unica **per palestra**, quindi lo stesso indirizzo può
     * appartenere a due persone in due palestre. Al login dei pannelli non c'è
     * un `join_code` che disambigui, e il provider di Laravel prenderebbe la
     * prima riga che capita — in silenzio, e magari quella di un iscritto, che
     * nel pannello non entra: l'amministratore vedrebbe «credenziali non
     * valide» pur avendole giuste.
     *
     * Fra i candidati si preferisce quindi chi può davvero entrare in un
     * pannello. Il nome utente non ha questo problema, essendo unico ovunque.
     *
     * @param  bool  $perPannello  se `true`, preferisce chi ha accesso a un pannello
     */
    public static function findByIdentifier(string $identifier, bool $perPannello = false): ?self
    {
        $identifier = mb_strtolower(trim($identifier));

        $candidati = static::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('email', $identifier)->orWhere('username', $identifier))
            ->get();

        if ($candidati->count() <= 1) {
            return $candidati->first();
        }

        if ($perPannello) {
            $conPannello = $candidati->first(
                fn (self $u): bool => $u->isSuperAdmin() || $u->isGymAdmin() || $u->isTrainer(),
            );

            if ($conPannello !== null) {
                return $conPannello;
            }
        }

        return $candidati->first();
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

    /** Gli account esterni collegati — C17/G8. */
    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    /** Le schede assegnate a questa persona (non i modelli della palestra). */
    public function workoutPlans(): HasMany
    {
        return $this->hasMany(WorkoutPlan::class, 'member_id');
    }

    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    public function nutritionPlans(): HasMany
    {
        return $this->hasMany(NutritionPlan::class, 'member_id');
    }

    public function bodyMetrics(): HasMany
    {
        return $this->hasMany(BodyMetric::class);
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
     * I controlli di ruolo di palestra passano tutti da qui.
     *
     * 🚨 **Valuta SEMPRE nella palestra dell'utente**, non in quella del
     * contesto corrente.
     *
     * In modalità teams `hasRole()` guarda il tenant impostato in quel momento.
     * Ma i ruoli di una persona esistono solo dentro la sua palestra, quindi
     * «è un trainer?» ha una risposta sola e non deve dipendere da uno stato
     * ambientale. Lasciandolo al contesto, la stessa chiamata rispondeva `true`
     * o `false` a seconda di dove veniva fatta — e il punto peggiore era
     * proprio il login, dove `ResolveTenant` non è ancora passato: un
     * amministratore veniva scambiato per un iscritto e rispedito fuori.
     *
     * ⚠️ NON accetta `UserRole::SuperAdmin`: quello non è un ruolo spatie ma
     * la colonna `is_super_admin`. Passarlo qui darebbe sempre `false` in
     * silenzio, quindi lancia invece di mentire.
     */
    public function hasAppRole(UserRole $role): bool
    {
        if ($role->isPlatformLevel()) {
            throw new InvalidArgumentException(
                "{$role->value} non e' un ruolo spatie: usare isSuperAdmin()."
            );
        }

        $tenant = $this->tenant;

        if ($tenant === null) {
            // Nessuna palestra: nessun ruolo di palestra. È il caso del super
            // admin, che non ne ha e non deve averne.
            return false;
        }

        return app(TenantContext::class)->runAs(
            $tenant,
            fn (): bool => $this->hasRole($role->value),
        );
    }

    /**
     * Vale su TUTTA la piattaforma, dentro e fuori da ogni palestra.
     *
     * Legge una colonna, non un ruolo spatie: i ruoli sono limitati al tenant
     * corrente e tornerebbero `false` appena il super admin entra in una
     * palestra — cioe' proprio quando gli serve (impersonazione, B2.3).
     * La motivazione completa e' nella migration `..._add_is_super_admin_...`.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
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

    /** Ultimo peso registrato, dalla tabella che lo conosce. */
    public function latestWeight(): ?float
    {
        return BodyMetric::latestWeightFor($this);
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? url($this->avatar_path) : null;
    }
}
