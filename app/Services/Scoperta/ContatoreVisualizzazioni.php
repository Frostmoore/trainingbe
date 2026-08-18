<?php

declare(strict_types=1);

namespace App\Services\Scoperta;

use App\Models\Campagna;
use App\Models\ProfiloPubblico;
use App\Models\User;
use App\Models\Visualizzazione;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Il conteggio che decide la fattura — 18/08/2026. **M5.3 e M5.4.**
 *
 * ── 🚨 Si conta DOPO aver deciso i risultati, mai prima ────────────────────
 *
 * ⚠️ Contare prima vorrebbe dire fatturare schede che non sono state
 * restituite: una ricerca che ne trova trenta e ne mostra venti farebbe pagare
 * anche le dieci che nessuno ha visto. E' esattamente il tipo di errore che chi
 * paga scopre guardando la fattura, non prima.
 *
 * ── 🚨 Non deve MAI far fallire il catalogo ────────────────────────────────
 *
 * Se il conteggio si rompe — vincolo, transazione, disco pieno — la persona che
 * sta cercando una palestra **deve vedere i risultati lo stesso**. ⚠️ Un
 * catalogo che risponde `500` perche' non e' riuscito a segnare una
 * visualizzazione ha sbagliato le priorita': si perde un centesimo, non un
 * utente.
 */
class ContatoreVisualizzazioni
{
    /**
     * Segna le schede sponsorizzate appena mostrate a questa persona.
     *
     * 💡 `$chi` puo' essere `null` — il catalogo e' aperto agli anonimi — e in
     * quel caso **non si conta niente**. Vedi `PERCHE_SOLO_IDENTIFICATE`.
     *
     * @param  Collection<int, ProfiloPubblico>  $mostrate
     */
    public function segna(?User $chi, Collection $mostrate): void
    {
        /*
         * 🚨 **Le visite anonime sono gratis, ed e' una decisione.**
         *
         * L'alternativa sarebbe contare per indirizzo IP: gonfiabile da chiunque
         * con un telefono in tethering, e vorrebbe dire conservare gli IP di chi
         * consulta un elenco — un dato personale raccolto **per fatturare**.
         *
         * 💡 Cosi' invece l'inserzionista compare anche agli anonimi e non paga:
         * e' un regalo, non una perdita.
         */
        if ($chi === null) {
            return;
        }

        $campagne = $mostrate
            ->filter(fn (ProfiloPubblico $p): bool => $p->campagna_id !== null)
            ->pluck('campagna_id')
            ->unique();

        if ($campagne->isEmpty()) {
            return;
        }

        $giorno = $this->giornoDi($chi);

        foreach ($campagne as $campagnaId) {
            try {
                $this->segnaUna((int) $campagnaId, $chi, $giorno);
            } catch (\Throwable $e) {
                /*
                 * ⚠️ Si annota e si va avanti. Il catalogo ha gia' i suoi
                 * risultati in mano: non e' il momento di far fallire la
                 * richiesta di qualcuno per un problema di contabilita'.
                 */
                Log::warning('Visualizzazione non registrata', [
                    'campagna_id' => $campagnaId,
                    'errore' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 🚨 Una sola campagna, una sola persona, un solo giorno.
     *
     * ── Perche' l'inserimento viene PRIMA dell'addebito ────────────────────
     *
     * L'unicita' su `(campagna, giorno, persona)` e' il conteggio: se
     * l'inserimento fallisce, quella persona aveva gia' visto questa campagna
     * oggi e **non si addebita niente**. ⚠️ Facendo il contrario — controllare
     * con un `SELECT` e poi addebitare — resterebbe la finestra fra i due, e due
     * richieste simultanee (che all'apertura di una schermata capitano)
     * addebiterebbero due volte.
     */
    private function segnaUna(int $campagnaId, User $chi, string $giorno): void
    {
        DB::transaction(function () use ($campagnaId, $chi, $giorno): void {
            /** @var Campagna|null $campagna */
            $campagna = Campagna::query()->lockForUpdate()->find($campagnaId);

            if ($campagna === null || ! $campagna->puoComparire()) {
                return;
            }

            try {
                Visualizzazione::create([
                    'campagna_id' => $campagnaId,
                    'user_id' => $chi->getKey(),
                    'giorno' => $giorno,
                ]);
            } catch (QueryException $e) {
                // 💡 Doppione = l'aveva gia' vista oggi. E' il caso **normale**,
                // non un errore: si esce senza addebitare.
                if ($this->eUnDoppione($e)) {
                    return;
                }

                throw $e;
            }

            $this->addebita($campagna);
        });
    }

    /**
     * L'addebito, con l'azzeramento pigro del mese e lo spegnimento automatico.
     *
     * 🚨 **Il tetto di spesa non e' facoltativo** (§4.3.2 del piano): quando il
     * budget finisce la campagna si spegne **da sola**, e si riaccende il mese
     * dopo solo se qualcuno la riattiva.
     */
    private function addebita(Campagna $campagna): void
    {
        $mese = Campagna::meseCorrente();

        /*
         * 🚨 L'azzeramento pigro: se il mese conservato non e' questo, il conto
         * riparte. ⚠️ Va fatto **qui dentro**, sotto lo stesso `lockForUpdate`
         * dell'addebito, o due richieste al primo del mese azzererebbero
         * entrambe e una delle due spesa andrebbe persa.
         */
        if ($campagna->mese !== $mese) {
            $campagna->mese = $mese;
            $campagna->speso_mese_cent = 0;

            /*
             * 💡 Un mese nuovo cancella anche la data di esaurimento: e' la
             * risposta a «perche' non compaio piu'», e a settembre quella
             * risposta non vale piu'.
             */
            $campagna->esaurita_il = null;
        }

        $campagna->speso_mese_cent += $campagna->costo_visualizzazione_cent;

        /*
         * ⚠️ Si spegne quando non c'e' piu' abbastanza per pagare **la
         * prossima**, non quando il budget e' esattamente finito: con 1
         * centesimo residuo e un costo di 2 la campagna comparirebbe
         * all'infinito senza mai riuscire a pagarsi.
         */
        if ($campagna->budget_mensile_cent - $campagna->speso_mese_cent < $campagna->costo_visualizzazione_cent) {
            $campagna->attiva = false;
            $campagna->esaurita_il = now();
        }

        $campagna->save();
    }

    /**
     * 💡 Il giorno **locale di chi guarda**, non l'istante UTC.
     *
     * ⚠️ Con UTC, chi apre l'app alle 01:30 in Italia risulterebbe di ieri, e
     * «una persona al giorno» diventerebbe «una persona al giorno di Greenwich»
     * — con due addebiti nella stessa serata per chi guarda a cavallo della
     * mezzanotte.
     */
    private function giornoDi(User $chi): string
    {
        return $chi->dataDiOggi();
    }

    /**
     * 💡 Si guarda il codice SQL e non il testo: il testo cambia fra MySQL,
     * MariaDB e SQLite, e un confronto su quello funzionerebbe in sviluppo e
     * fallirebbe in produzione. Stessa scelta di `StripeWebhookController`.
     */
    private function eUnDoppione(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
