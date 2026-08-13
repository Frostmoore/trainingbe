<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * La quota in chiamate su tutti e cinque i livelli, e il portafoglio — G2.
 *
 * ── 🚨 Perche' TUTTI i livelli, e non solo il piano ───────────────────────
 *
 * `MemberAiQuota` e' una catena: persona → palestra → trainer indipendente →
 * piano → default di sistema. Convertire **solo** il piano lascerebbe i primi
 * tre a contare token mentre il quarto conta chiamate, e la catena
 * confronterebbe numeri di due unita' diverse **senza dare errore**: un tetto
 * personale di 500.000 letto come «500.000 chiamate» e' AI illimitata a chi
 * aveva un tetto stretto.
 *
 * ⚠️ E' la stessa forma di errore del §14 di `plan_parte_b.md` — una regola che
 * poggia su un presupposto vero *prima* e falso *dopo* — quindi si converte
 * tutto in un colpo solo.
 *
 * ── D16 — il portafoglio dei gettoni ──────────────────────────────────────
 *
 * `tenants.ai_credits` e' il saldo, `ai_credit_movements` il registro. 🚨 Un
 * saldo senza registro non si puo' contestare ne' correggere, e i soldi si
 * contestano sempre.
 */
return new class extends Migration
{
    /** Vedi la nota in `2026_08_14_100000_il_listino_a_livelli.php`. */
    private const TOKEN_PER_CHIAMATA = 1730;

    private const QUOTA_FOTO = 0.10;

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Livello 1 della catena — l'eccezione per **una persona**.
            $table->unsignedInteger('ai_monthly_call_cap')->nullable()->after('ai_monthly_token_cap');
            $table->unsignedInteger('ai_monthly_photo_call_cap')->nullable()->after('ai_monthly_call_cap');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            // Livello 2 — quanto la palestra concede a ciascun iscritto.
            $table->unsignedInteger('ai_monthly_calls_per_member')
                ->nullable()->after('ai_monthly_tokens_per_member');
            $table->unsignedInteger('ai_monthly_photo_calls_per_member')
                ->nullable()->after('ai_monthly_calls_per_member');

            /*
             * D16 — il portafoglio.
             *
             * ⚠️ Sta su `tenants` e non su `users`: lo compra il trainer
             * indipendente o la palestra, cioe' **chi paga**, e lo consumano i
             * suoi allievi. Metterlo sull'utente vorrebbe dire che ogni allievo
             * ha un portafoglio suo da ricaricare, che non e' il modello
             * commerciale deciso.
             */
            $table->unsignedBigInteger('ai_credits')->default(0)->after('ai_monthly_photo_calls_per_member');
        });

        Schema::create('ai_credit_movements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // + accredito, − consumo. `integer` e non `unsignedInteger`: il
            // segno **e'** l'informazione.
            $table->integer('delta');

            /*
             * 🚨 **Ridondante di proposito, ed e' l'unica ridondanza ammessa in
             * tutto questo piano.**
             *
             * Un saldo che si puo' solo ricalcolare sommando tutto il registro
             * non si puo' **contestare**: il giorno in cui un cliente dice «mi
             * mancano dei gettoni», serve poter dire quanto ne aveva **a quel
             * movimento li'**. Vale per i soldi e non varrebbe per le calorie:
             * i soldi si contestano.
             */
            $table->unsignedBigInteger('saldo_dopo');

            $table->string('causale', 32); // acquisto | consumo | rettifica

            // Quale chiamata l'ha consumato. `null` per gli accrediti.
            $table->foreignId('ai_usage_log_id')->nullable()->constrained()->nullOnDelete();

            // Chi ha fatto l'accredito a mano. `null` per i consumi.
            $table->foreignId('operatore_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('nota')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            /*
             * 🚨 **Senza questo indice ogni chiamata all'AI fa una scansione.**
             *
             * Da G2 il conteggio della quota non somma piu' i token: conta le
             * righe di questa persona, in questo mese, di questo tipo. E'
             * `ai_usage_logs` la tabella che cresce piu' in fretta di tutte —
             * 122 righe oggi in staging, ma cresce con **ogni** interazione di
             * **ogni** utente.
             */
            $table->index(['user_id', 'feature', 'created_at'], 'ai_usage_logs_quota_index');
        });

        $this->portaDietroITetti();
    }

    /**
     * Da token a chiamate sui livelli 1 e 2 della catena.
     *
     * 🚨 **Chi aveva un tetto stretto deve continuare ad averlo.** Lasciare i
     * campi nuovi a `null` farebbe scendere quelle persone al livello
     * successivo — cioe' **alzerebbe** il loro tetto invece di conservarlo, che
     * e' il modo piu' silenzioso di regalare l'AI a chi era stato limitato
     * apposta.
     */
    private function portaDietroITetti(): void
    {
        $utenti = DB::table('users')->whereNotNull('ai_monthly_token_cap')->get(['id', 'ai_monthly_token_cap']);

        foreach ($utenti as $u) {
            [$chiamate, $foto] = $this->converti((int) $u->ai_monthly_token_cap);

            DB::table('users')->where('id', $u->id)->update([
                'ai_monthly_call_cap' => $chiamate,
                'ai_monthly_photo_call_cap' => $foto,
            ]);
        }

        $tenant = DB::table('tenants')
            ->whereNotNull('ai_monthly_tokens_per_member')
            ->get(['id', 'ai_monthly_tokens_per_member']);

        foreach ($tenant as $t) {
            [$chiamate, $foto] = $this->converti((int) $t->ai_monthly_tokens_per_member);

            DB::table('tenants')->where('id', $t->id)->update([
                'ai_monthly_calls_per_member' => $chiamate,
                'ai_monthly_photo_calls_per_member' => $foto,
            ]);
        }

        Log::info('G2 — tetti convertiti da token a chiamate', [
            'utenti' => $utenti->count(),
            'tenant' => $tenant->count(),
        ]);
    }

    /**
     * @return array{0: int, 1: int} chiamate, di cui con foto
     */
    private function converti(int $token): array
    {
        // 🚨 `0` = illimitato, e resta illimitato. Scritto esplicitamente:
        // `intdiv(0, 1730)` darebbe la risposta giusta per caso, e «per caso»
        // smette di valere il giorno che cambia il divisore.
        if ($token === 0) {
            return [0, 0];
        }

        // ⚠️ Almeno una chiamata: `0` qui vorrebbe dire **illimitato**, cioe'
        // l'opposto di un tetto stretto.
        $chiamate = max(1, intdiv($token, self::TOKEN_PER_CHIAMATA));

        return [$chiamate, (int) ceil($chiamate * self::QUOTA_FOTO)];
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->dropIndex('ai_usage_logs_quota_index');
        });

        Schema::dropIfExists('ai_credit_movements');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_monthly_calls_per_member',
                'ai_monthly_photo_calls_per_member',
                'ai_credits',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['ai_monthly_call_cap', 'ai_monthly_photo_call_cap']);
        });
    }
};
