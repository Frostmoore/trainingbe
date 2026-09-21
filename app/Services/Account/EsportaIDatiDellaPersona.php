<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 📦 **Tutto quello che il server sa di una persona** — artt. 15 e 20 GDPR, 19/09/2026.
 *
 * 📌 Voce 7 dell'elenco legale (`memory/informativa_privacy.md`): «Esportazione
 * dei dati — non esiste». Il committente, 19/09: *«sistema tutte le ultime cose
 * che puoi sistemare»*.
 *
 * ── 💡 PERCHÉ UN ELENCO ESPLICITO E NON «TUTTE LE RIGHE CON user_id» ──────
 *
 * Perché in quelle righe ci sono anche cose che **non vanno consegnate**: il
 * token delle notifiche, la chiave cifrata di recupero, il token di un invito
 * ancora valido. Consegnarle in un file che finisce in una cartella di
 * download vorrebbe dire regalarle a chiunque trovi quel file. ⚠️ E non sono
 * «dati della persona» nel senso dell'art. 15: sono chiavi, e la persona le ha
 * già — sul proprio telefono.
 *
 * 🚨 **Una tabella nuova legata a una persona deve finire in uno dei due
 * elenchi**: `EsportaIDatiDellaPersonaTest` fallisce altrimenti. È la stessa
 * difesa di `AccountErasureSweepTest` per la cancellazione.
 *
 * ⚠️ **I messaggi escono senza testo**: il server ha solo buste cifrate che
 * non sa aprire. Il testo lo legge l'app, sul telefono.
 */
final class EsportaIDatiDellaPersona
{
    /**
     * Le tabelle che si esportano: la colonna (o le colonne) che dice «è di
     * questa persona», e le colonne che restano fuori.
     *
     * @var array<string, array{0: list<string>, 1: list<string>}>
     */
    public const ESPORTATE = [
        'ai_advices' => [['user_id'], []],
        'ai_credit_movements' => [['user_id'], ['operatore_id']],
        'ai_usage_logs' => [['user_id'], []],
        // ⚠️ Il token è il link per scaricare l'allegato: finché vale, apre.
        'allegati_cifrati' => [['sender_id'], ['token']],
        // 💡 Anche l'IP e il browser: sono dati della persona, e l'art. 15 li comprende.
        'audit_logs' => [['actor_id'], []],
        /*
         * 🆕 💰 **I pagamenti** — 22/09/2026, fase F3.
         *
         * 🚨 Sono **suoi**: quanto ha speso, quando e per cosa. L'art. 15
         * comprende i dati di pagamento, e questa è la riga che li riassume.
         *
         * ⚠️ L'id di Stripe esce: non è una chiave d'accesso, è il numero
         * che le serve se contesta un addebito.
         */
        'pagamenti_incassati' => [['user_id'], []],

        /*
         * 🆕 ✂️ **Quando la palestra le ha tolto l'abbonamento** — 23/09/2026.
         *
         * 🚨 È un dato **su di lei**: dice che in quelle date non era più
         * coperta, e spiega perché l'app le mostrava il piano gratuito.
         * L'art. 15 lo comprende. ⚠️ Chi l'ha tolto (`tolto_da_id`,
         * `ridato_da_id`) esce come id e basta: è una persona della palestra,
         * e il suo nome non è un dato di chi chiede l'export.
         */
        'abbonamenti_tolti' => [['user_id'], []],

        'campagne' => [['user_id'], []],
        // La chiave PUBBLICA: è fatta per essere data a chiunque.
        'chat_keys' => [['user_id'], []],
        'conversations' => [['member_id', 'trainer_id'], []],
        // ⛔ Il token delle notifiche: chi lo ha può mandare notifiche a quel telefono.
        'device_tokens' => [['user_id'], ['token']],
        'food_entries' => [['user_id'], []],
        'food_favorites' => [['user_id'], []],
        'importazioni_da_documento' => [['user_id'], []],
        // 🆕 ✍️ La liberatoria firmata: è un atto suo, e gli spetta.
        'liberatorie_firmate' => [['user_id'], []],
        // ⚠️ Senza `body` e `nonce`: sono la busta cifrata, illeggibile per noi e inutile fuori dall'app.
        'messages' => [['sender_id'], ['body', 'nonce']],
        'nutrition_plans' => [['member_id'], []],
        'profili_pubblici' => [['user_id'], []],
        // ⛔ Sale, nonce e chiave avvolta: materiale crittografico, non dati. Resta la data.
        'recovery_keys' => [['user_id'], ['salt', 'nonce', 'wrapped_key', 'ops_limit', 'mem_limit']],
        'social_identities' => [['user_id'], []],
        'stime_cibo' => [['user_id'], []],
        'trainer_member' => [['member_id', 'trainer_id'], []],
        // ⛔ Il token è l'invito stesso: finché vale, fa entrare chi lo usa.
        'trainer_invites' => [['trainer_id'], ['token']],
        'visualizzazioni' => [['user_id'], []],
        'workout_plan_imports' => [['member_id'], []],
        'workout_plans' => [['member_id'], []],
    ];

    /**
     * Le tabelle legate a una persona che **non** si esportano, e perché.
     *
     * @var array<string, string>
     */
    public const ESCLUSE = [
        'exercises' => 'Il catalogo: gli esercizi di un trainer o di una palestra sono il suo lavoro per gli allievi, non dati su di lui; quelli di un allievo stanno sul telefono (D6).',
        'personal_access_tokens' => 'Le sessioni aperte dell\'app: sono chiavi d\'accesso, e in un file scaricato aprirebbero l\'account a chi lo trova.',
        'sessions' => 'Le sessioni del pannello web: stesse ragioni dei token d\'accesso.',
        'password_reset_tokens' => 'Un link per cambiare la password: consegnarlo è consegnare l\'account.',

        /*
         * 🆕 🎁 22/09/2026, fase F2 — i bersagli delle promozioni.
         *
         * ⚠️ Una riga con dentro il suo `user_id` c'è, ma non dice niente **su
         * di lei**: dice che un'offerta **nostra** la riguarda. 💡 Le
         * promozioni che ha davvero usato si leggono dove si leggono i soldi —
         * i movimenti dei gettoni e le ricevute di Stripe — e quelli escono già.
         */
        'promozione_bersagli' => 'Dice che un\'offerta nostra la riguarda, non è un dato su di lei: quello che ha usato sta nei movimenti.',

        /*
         * 🆕 🔑 22/09/2026, fase F4 — le reimpostazioni della password.
         *
         * 🚨 **Stessa regola di `password_reset_tokens`, e per lo stesso
         * motivo**: una riga qui dentro è una **chiave d'accesso**. ⛔ In un
         * file scaricato — che finisce in Download, in una chat, in un
         * backup — aprirebbe l'account a chiunque lo trovi entro l'ora.
         *
         * 💡 E non si perde niente: l'unico dato «suo» sarebbe *quante volte
         * ha chiesto il reset*, che non è un'informazione che la riguarda più
         * di quanto la esponga.
         */
        'reimpostazioni_password' => 'Sono chiavi d\'accesso a tempo: consegnarle in un export è consegnare l\'account.',
    ];

    /** Le colonne di `users` che non escono mai. */
    private const UTENTE_SENZA = ['password', 'remember_token'];

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $persona): array
    {
        $id = $persona->getKey();

        $account = (array) DB::table('users')->where('id', $id)->first();

        foreach (self::UTENTE_SENZA as $colonna) {
            unset($account[$colonna]);
        }

        $tabelle = [];

        foreach (self::ESPORTATE as $tabella => [$chiavi, $senza]) {
            $righe = DB::table($tabella)
                ->where(function ($q) use ($chiavi, $id): void {
                    foreach ($chiavi as $chiave) {
                        $q->orWhere($chiave, $id);
                    }
                })
                ->orderBy('id')
                ->get()
                ->map(function (object $riga) use ($senza): array {
                    $riga = (array) $riga;

                    foreach ($senza as $colonna) {
                        unset($riga[$colonna]);
                    }

                    return $riga;
                })
                ->all();

            if ($righe !== []) {
                $tabelle[$tabella] = $righe;
            }
        }

        return [
            'formato' => 1,
            'generato_il' => now()->toIso8601String(),
            'avvertenze' => [
                'Questo file contiene quello che il SERVER sa di te. La maggior parte dei tuoi dati (profilo, peso, allenamenti, diario, sonno) sta sul tuo telefono, e l\'app la aggiunge in fondo a questo stesso file.',
                'I messaggi sono cifrati da un capo all\'altro: il server ha solo buste che non sa aprire, e qui trovi quando e a chi li hai mandati, non cosa c\'era scritto. Il testo lo leggi nell\'app.',
                'Restano fuori token, chiavi e link d\'accesso: in un file scaricato aprirebbero il tuo account a chiunque lo trovi.',
            ],
            'account' => $account,
            'ruoli' => DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $id)
                ->where('model_has_roles.model_type', $persona->getMorphClass())
                ->select('roles.name as ruolo', 'model_has_roles.tenant_id')
                ->get()
                ->map(fn (object $r): array => (array) $r)
                ->all(),
            'spazi' => DB::table('tenants')
                ->where('id', $persona->tenant_id)
                ->orWhere('proprietario_id', $id)
                ->select('id', 'name', 'kind', 'status', 'created_at')
                ->get()
                ->map(fn (object $t): array => (array) $t)
                ->all(),
            'abbonamenti' => DB::table('plan_subscriptions')
                ->whereIn('tenant_id', DB::table('tenants')->where('proprietario_id', $id)->select('id'))
                ->get()
                ->map(fn (object $s): array => (array) $s)
                ->all(),
            'tabelle' => $tabelle,
        ];
    }
}
