<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantKind;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🏠 **Dà una casa a chi non ce l'ha** — F4.2 di
 * `memory/plan_spazi_personali.md`, 27/09/2026.
 *
 * ══ 🚨 LA SCOPERTA CHE HA FATTO NASCERE QUESTO COMANDO ════════════════════
 *
 * `spazi:riporta-a-casa` ha mostrato una cosa che il piano non prevedeva: la
 * maggior parte dei «traslocati» **non ha mai avuto una casa**. ⛔ Sono nati
 * **dentro** la palestra — creati dal titolare, o dall'iscrizione dal sito —
 * e per loro «riportare a casa» non è riaprire una porta: è **costruirla**.
 *
 * 💡 Per questo lo spostamento è diviso in due comandi: prima si dà una casa a
 * tutti, poi (F4.3) ci si sposta dentro. ⚠️ Un comando solo che facesse
 * entrambe le cose sarebbe impossibile da fermare a metà.
 *
 * ══ ⚖️ QUESTO COMANDO È ADDITIVO ══════════════════════════════════════════
 *
 * 🔑 **Crea tenant personali e basta.** ⛔ Non sposta un solo dato e non tocca
 * `users.tenant_id`: dopo averlo eseguito, il sistema si comporta **esattamente
 * come prima**. 💡 È quello che lo rende sicuro da lanciare in produzione anche
 * prima di aver deciso il resto.
 */
class DaiUnaCasaATutti extends Command
{
    protected $signature = 'spazi:dai-una-casa
                            {--scrivi : senza questo, elenca soltanto}';

    protected $description = 'Crea lo spazio personale a chi non ce l\'ha (additivo: non sposta niente)';

    public function handle(TenantContext $context, CreaTenantPersonale $crea): int
    {
        $scrive = (bool) $this->option('scrivi');

        $senzaCasa = $this->senzaCasa($context);

        if ($senzaCasa === []) {
            $this->info('✅ Hanno già tutti uno spazio personale.');

            return self::SUCCESS;
        }

        $this->warn('Persone senza uno spazio personale: '.count($senzaCasa));
        $this->newLine();

        if (! $scrive) {
            foreach (array_slice($senzaCasa, 0, 30) as $u) {
                $this->line('  #'.$u->getKey().'  '.($u->email ?? $u->name));
            }

            if (count($senzaCasa) > 30) {
                $this->line('  … e altri '.(count($senzaCasa) - 30));
            }

            $this->newLine();
            $this->line('⚠️ Niente è stato scritto. Rilancia con --scrivi per crearli.');

            return self::SUCCESS;
        }

        $fatti = 0;
        $falliti = [];

        foreach ($senzaCasa as $utente) {
            try {
                /*
                 * ⚠️ Ogni persona nella **sua** transazione, non tutte in una.
                 * ⛔ Con una transazione sola, un caso storto a metà elenco
                 * annullerebbe anche le case create bene: si ripartirebbe da
                 * zero ogni volta, e con mille persone non si finirebbe mai.
                 */
                DB::transaction(function () use ($crea, $utente, $context): void {
                    $casa = $context->runWithoutTenant(fn () => $crea->perChiEsisteGia($utente));

                    /*
                     * ⚠️ **Chi non aveva NESSUNO spazio ci va ad abitare.**
                     * ⛔ Per chi invece sta gia' in una palestra il
                     * `tenant_id` **non si tocca**: la casa gli serve per
                     * dopo, e spostarlo adesso sarebbe un trasloco -- cioe'
                     * la cosa che tutto questo lavoro esiste per togliere.
                     */
                    if ($utente->tenant_id === null) {
                        $utente->forceFill(['tenant_id' => $casa->getKey()])->save();
                    }
                });

                $fatti++;
            } catch (\Throwable $e) {
                $falliti[] = '#'.$utente->getKey().': '.$e->getMessage();
            }
        }

        $this->newLine();
        $this->info('✅ Case create: '.$fatti);

        if ($falliti !== []) {
            $this->error('⛔ Falliti: '.count($falliti));

            foreach (array_slice($falliti, 0, 10) as $f) {
                $this->line('  '.$f);
            }
        }

        return $falliti === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Chi non ha un tenant personale di cui è proprietario.
     *
     * ⚠️ Si guarda `proprietario_id`, **non** il `kind`: anche lo spazio di un
     * free trainer è `personal`, ⛔ ma non è casa di chi ci è stato traslocato
     * dentro.
     *
     * @return list<User>
     */
    private function senzaCasa(TenantContext $context): array
    {
        return $context->runWithoutTenant(
            fn (): array => User::withoutGlobalScopes()
                ->whereNull('deleted_at')
                /*
                 * 🆕 👑 **Anche chi non ha NESSUNO spazio** — 27/09/2026.
                 *
                 * ⛔ Qui c'era `whereNotNull('tenant_id')`, e saltava
                 * esattamente il caso peggiore: il **super admin**, che per
                 * disegno ha `tenant_id = null`.
                 *
                 * 🚨 Scoperto provando l'accesso con Google con l'account
                 * god: senza uno spazio l'app non lo fa entrare, e il
                 * messaggio parlava di una palestra sospesa che non esiste.
                 * 📌 Il committente: *«anche con l'account god devo poter
                 * entrare nell'app»*.
                 *
                 * ⚖️ Amministrare la piattaforma è un **potere**, non un
                 * motivo per non avere una casa: il flag `is_super_admin`
                 * resta dov'è, e in più la persona ha il suo spazio come
                 * tutti.
                 */
                ->whereNotExists(
                    fn ($q) => $q->select(DB::raw(1))
                        ->from('tenants')
                        ->whereColumn('tenants.proprietario_id', 'users.id')
                        ->where('tenants.kind', TenantKind::Personal->value)
                        ->whereNull('tenants.deleted_at'),
                )
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }
}
