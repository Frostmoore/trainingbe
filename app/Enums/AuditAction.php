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
    /** ⛔ Non si scrive più (F11: niente codici palestra). Resta per leggere le righe vecchie del registro. */
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

    /**
     * 🚨 Un abbonamento **pagato** disdetto dal pannello — 09/09/2026.
     *
     * ⚠️ Sta separata da [AbbonamentoRegalato] perche' e' un'altra cosa: quella
     * toglie un regalo, questa **ferma un addebito su una carta vera**. 💡 Il
     * giorno in cui qualcuno chiede «chi ha disdetto il mio abbonamento?», la
     * risposta deve essere una riga sola, non una da interpretare.
     */
    case AbbonamentoPagatoDisdetto = 'user.abbonamento_pagato_disdetto';

    /**
     * 🆕 La palestra ha **disiscritto** un iscritto — D4, 19/09/2026.
     *
     * ⛔ Prima il pannello aveva solo «Elimina»: cancellava l'**account** della
     * persona. 📌 Il committente: *«Una palestra deve poter AL MASSIMO
     * disiscrivere un allievo… l'account personale non dipende in alcun modo
     * dalla palestra»*.
     */
    case IscrittoDisiscritto = 'user.disiscritto';

    /**
     * 🆕 La persona è **uscita da sola** dalla palestra, dall'app — 19/09/2026.
     *
     * 🚨 Il valore c'era già come **stringa** in `AccountController::leaveGym`
     * dal 23/08, e `AuditLogger::log()` vuole un `AuditAction`: ogni uscita
     * riuscita finiva in un `TypeError` **dopo** aver fatto il lavoro. L'app
     * riceveva un 500 e continuava a mostrare la palestra. Trovato nel giro
     * sull'emulatore del 19/09.
     */
    case UscitoDallaPalestra = 'account.left_gym';

    /**
     * 🆕 ✂️ **Un piano cucito a mano assegnato a un cliente** — 21/09/2026.
     *
     * 🚨 È l'azione con più soldi attaccati di tutto il pannello: decide
     * **quanto paga** un cliente e **quanti posti** ha. ⚠️ Non passa da Stripe e
     * non lascia traccia in fattura finché il mese non si chiude, quindi senza
     * questa riga di registro l'unica prova di chi l'ha fatto e quando sarebbe
     * la colonna `updated_at` dell'abbonamento.
     */
    case PianoSuMisuraAssegnato = 'tenant.piano_su_misura_assegnato';

    /**
     * 🔑 **Un link per rifare la password, mandato dal pannello** - 22/09/2026, F4.
     *
     * 🚨 È un gesto che **apre un account a chi legge quella casella**: per
     * un'ora chiunque abbia il link entra al posto di quella persona. ⚠️ Senza
     * questa riga, di chi l'ha premuto non resterebbe niente - il token è in
     * tabella, ma l'impronta non dice **chi** l'ha chiesto né da dove.
     *
     * 💡 `chiesto_da_id` lo dice in `reimpostazioni_password`; qui sta nel
     * registro insieme a tutto il resto, dove lo si va davvero a cercare.
     */
    case ResetPasswordMandato = 'user.reset_password_mandato';

    /**
     * 🆕 🎁 **La palestra ha comprato gettoni per un suo iscritto** — F5.8.
     *
     * 🚨 È un gesto che **muove soldi veri** e che accredita valore a una
     * persona diversa da chi paga. ⚠️ Il movimento dei gettoni lo scrive già
     * `ai_credit_movements`, ma quello nasce **al ritorno da Stripe**: se il
     * pagamento non si conclude, di questo tentativo non resterebbe niente.
     *
     * 💡 Qui resta scritto **chi ha premuto e per chi**, che è la domanda
     * che si fa un gestore quando trova un addebito che non riconosce.
     */
    case GettoniRegalatiDallaPalestra = 'tenant.gettoni_regalati';

    /**
     * 🆕 ✂️ **La palestra ha tolto (o ridato) l'abbonamento a un iscritto** — F5.
     *
     * 🚨 **Cambia quanto paga la palestra**: chi è senza abbonamento non occupa
     * un posto, e su un piano a consumo la fattura scende. ⚠️ Resta scritto
     * chi l'ha fatto e quando, perché è la domanda che si fa chi trova una
     * fattura diversa da quella del mese prima.
     */
    case AbbonamentoDellIscritto = 'user.abbonamento_iscritto';

    /**
     * 🆕 🎁 **Una promozione revocata** (a una persona, o a tutti) — P7 di
     * `plan_promozioni.md`. 🚨 Toglie gettoni e abbonamenti già dati: resta
     * scritto chi l'ha fatto, e quanto ha tolto.
     *
     * ⚠️ Il soggetto è **la persona** (e la palestra è la sua): `AuditLog`
     * pretende una palestra, e una promozione è della piattaforma.
     */
    case PromozioneRevocata = 'promozione.revocata';

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
            self::AbbonamentoPagatoDisdetto => 'Abbonamento pagato disdetto',
            self::IscrittoDisiscritto => 'Iscritto disiscritto',
            self::UscitoDallaPalestra => 'Uscito dalla palestra',
            self::PianoSuMisuraAssegnato => 'Piano su misura assegnato',
            self::ResetPasswordMandato => 'Link per la password mandato',
            self::GettoniRegalatiDallaPalestra => 'Gettoni regalati dalla palestra',
            self::AbbonamentoDellIscritto => 'Abbonamento dell\'iscritto tolto o ridato',
            self::PromozioneRevocata => 'Promozione revocata',
        };
    }

    /** Colore del badge nell'elenco: il rosso e' per cio' che va guardato. */
    public function color(): string
    {
        return match ($this) {
            self::ImpersonationStarted, self::UserDeleted, self::TenantSuspended => 'danger',
            self::ImpersonationStopped, self::UserDeactivated, self::RoleChanged,
            self::AiQuotaChanged, self::AbbonamentoRegalato,
            self::PianoSuMisuraAssegnato, self::ResetPasswordMandato, self::PromozioneRevocata => 'warning',
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
