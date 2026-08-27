<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * L'elenco chiuso delle azioni che finiscono in `audit_logs`.
 *
 * E' un enum e non una stringa libera per una ragione operativa: il registro
 * serve a rispondere a domande («tutte le impersonazioni dell'ultimo mese»), e
 * su una colonna riempita a mano quelle domande si scrivono con dei LIKE che
 * prima o poi mancano una riga. Con l'enum l'elenco delle azioni tracciate e'
 * leggibile in un colpo d'occhio, e aggiungerne una e' una decisione esplicita.
 *
 * L'elenco corrisponde a B10.2 del piano.
 */
enum AuditAction: string
{
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationStopped = 'impersonation.stopped';
    case RoleChanged = 'user.role_changed';
    case UserDeactivated = 'user.deactivated';
    case UserDeleted = 'user.deleted';
    case TenantSuspended = 'tenant.suspended';
    case TenantReactivated = 'tenant.reactivated';
    case JoinCodeRegenerated = 'tenant.join_code_regenerated';
    case WorkoutPlanPublished = 'workout_plan.published';
    case NutritionPlanPublished = 'nutrition_plan.published';

    /*
     * G8 — email e password cambiate dall'interessato.
     *
     * 🚨 Un cambio di email è il passo che precede quasi ogni presa di
     * controllo di un account: chi ci riesce si sposta l'indirizzo e poi usa il
     * recupero password. Senza una riga qui, il giorno che qualcuno contesta
     * «non sono stato io» non c'è modo di dire quando è successo né da quale
     * indirizzo si veniva.
     */
    case EmailChanged = 'user.email_changed';
    case PasswordChanged = 'user.password_changed';

    /*
     * La quota AI concessa a mano dalla piattaforma — 14/08/2026.
     *
     * 🚨 **E' una concessione, e le concessioni si tracciano.** Da qui si puo'
     * mettere una persona a **illimitato**: chi guarda il costo del mese dopo
     * deve poter risalire a chi gliel'ha dato e quando, senza doverlo dedurre
     * dai consumi.
     *
     * ⚠️ E' anche l'unico campo del pannello che puo' far spendere denaro vero
     * **senza che nessuno compri niente**.
     */
    case AiQuotaChanged = 'user.ai_quota_changed';

    /**
     * 🎁 Un abbonamento dato (o tolto) a mano dal pannello god — 3b-H.10.
     *
     * 🚨 **Causale sua e non `AiQuotaChanged`**: sono due concessioni diverse.
     * L'AI illimitata regala **consumo** e costa a noi in chiamate; questa
     * regala **il prodotto** e costa in mancato incasso. ⚠️ Con una causale
     * sola, la domanda «a quanta gente abbiamo regalato l'abbonamento» non si
     * potrebbe piu' fare.
     */
    case AbbonamentoRegalato = 'user.abbonamento_regalato';

    public function label(): string
    {
        return match ($this) {
            self::ImpersonationStarted => 'Impersonazione iniziata',
            self::ImpersonationStopped => 'Impersonazione terminata',
            self::RoleChanged => 'Ruolo modificato',
            self::UserDeactivated => 'Utente disattivato',
            self::UserDeleted => 'Utente eliminato',
            self::TenantSuspended => 'Palestra sospesa',
            self::TenantReactivated => 'Palestra riattivata',
            self::JoinCodeRegenerated => 'Codice d\'invito rigenerato',
            self::WorkoutPlanPublished => 'Scheda pubblicata',
            self::NutritionPlanPublished => 'Piano alimentare pubblicato',
            self::EmailChanged => 'Email modificata',
            self::PasswordChanged => 'Password modificata',
            self::AiQuotaChanged => 'Quota AI modificata',
            self::AbbonamentoRegalato => 'Abbonamento regalato',
        };
    }

    /** Colore del badge nell'elenco: il rosso e' per cio' che va guardato. */
    public function color(): string
    {
        return match ($this) {
            self::ImpersonationStarted, self::UserDeleted, self::TenantSuspended => 'danger',
            self::ImpersonationStopped, self::UserDeactivated, self::RoleChanged,
            self::AiQuotaChanged, self::AbbonamentoRegalato => 'warning',
            default => 'gray',
        };
    }

    /** @return array<string, string> value => label, per i filtri Filament */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
