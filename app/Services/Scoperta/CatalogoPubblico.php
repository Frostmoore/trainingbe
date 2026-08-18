<?php

declare(strict_types=1);

namespace App\Services\Scoperta;

use App\Models\Comune;
use App\Models\ProfiloPubblico;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Chi c'e' vicino a me — 18/08/2026. **M2.3.**
 *
 * ── 🚨 Il catalogo lo vedono TUTTI ─────────────────────────────────────────
 *
 * *(correzione del committente, 18/08)*. La prima stesura del piano lo riservava
 * agli abbonati. ⚠️ Era sbagliato per due ragioni che si vedono solo mettendole
 * insieme:
 *
 * 1. **E' l'imbuto d'ingresso.** Chiuderlo a chi non paga vuol dire nasconderlo
 *    a tutti quelli che non sono ancora clienti — cioe' esattamente le persone
 *    che deve raggiungere.
 * 2. **Danneggia chi paga la pubblicita'.** Una palestra paga per comparire, e
 *    comparirebbe davanti a un pubblico ridotto ai soli abbonati, che sono gia'
 *    dentro.
 *
 * 💡 Quindi il catalogo e' aperto, e **l'abbonamento vende un'altra cosa**:
 * scrivere senza limite a chi non ti segue (`CancelloDellaChat`, M3).
 *
 * ── ⚠️ I trainer dipendenti NON compaiono ──────────────────────────────────
 *
 * Nel catalogo ci sono **le palestre** (tramite il proprietario) e i **trainer
 * indipendenti**. Un trainer dipendente di una palestra non e' contattabile da
 * fuori: chi vuole quella palestra scrive alla palestra.
 *
 * 💡 Non e' una restrizione arbitraria: un dipendente **non decide lui** chi
 * seguire, e riceverebbe richieste che non puo' accettare. Qui la regola e'
 * automatica — una scheda ha `tenant_id` (la palestra) oppure `user_id` (il
 * trainer indipendente), e un dipendente non e' ne' l'uno ne' l'altro.
 */
class CatalogoPubblico
{
    /** ⚠️ Il tetto: senza, `?limite=100000` sarebbe un modo per scaricare tutto. */
    public const LIMITE_MASSIMO = 50;

    public function __construct(
        private readonly Vicinanza $vicinanza,
        private readonly ChiaveComune $chiavi,
    ) {}

    /**
     * Le schede da mostrare, le piu' pertinenti per prime.
     *
     * L'ordine e':
     *
     *   1. **sponsorizzati** — etichettati, mai mimetizzati (M5)
     *   2. **stesso comune** di chi guarda
     *   3. **per distanza** crescente
     *   4. gli altri, per nome
     *
     * @return Collection<int, ProfiloPubblico>
     */
    public function cerca(?User $chi, ?string $testo = null, int $limite = 20): Collection
    {
        $limite = max(1, min($limite, self::LIMITE_MASSIMO));

        /*
         * 🚨 `$chi` puo' essere `null`, e non e' un caso di scuola: il catalogo
         * e' aperto anche a chi non ha un account. ⚠️ Un servizio che desse per
         * scontato l'utente autenticato qui fallirebbe proprio sul pubblico che
         * il catalogo esiste per raggiungere.
         */
        $centro = $chi?->comune;

        $query = ProfiloPubblico::query()
            ->visibili()
            ->with(['comune', 'tenant', 'trainer']);

        if ($testo !== null && trim($testo) !== '') {
            $this->filtraPerTesto($query, $testo);
        }

        $vicini = $centro !== null ? $this->vicinanza->idVicini($centro) : [];

        return $this->ordina($query, $centro, $vicini)
            ->limit($limite)
            ->get()
            ->map(fn (ProfiloPubblico $p): ProfiloPubblico => $this->arricchisci($p, $centro));
    }

    /**
     * ⚠️ La ricerca testuale usa `LIKE '%x%'` su `titolo` e `descrizione`.
     *
     * 🚨 E' ammesso qui per lo stesso motivo dei comuni e per uno in piu': le
     * schede pubbliche sono al massimo quante sono le palestre e i trainer
     * iscritti — un numero che si conta, non che esplode. E la descrizione e'
     * testo libero, dove il prefisso non serve a niente: chi cerca «functional»
     * lo vuole trovare in mezzo alla frase.
     *
     * 💡 Il giorno che diventassero centinaia di migliaia, la risposta e' un
     * indice `FULLTEXT`, non rinunciare alla ricerca.
     *
     * @param  Builder<ProfiloPubblico>  $query
     */
    private function filtraPerTesto(Builder $query, string $testo): void
    {
        $like = addcslashes(trim($testo), '%_\\');

        $query->where(fn (Builder $w) => $w
            ->where('titolo', 'like', "%{$like}%")
            ->orWhere('descrizione', 'like', "%{$like}%")
            /*
             * 💡 Si cerca anche per **nome del comune**: chi scrive «Rimini» in
             * un campo di ricerca sta cercando le palestre a Rimini, non una
             * palestra che si chiama Rimini. Senza questa riga otterrebbe zero
             * risultati e concluderebbe che non ce ne sono.
             */
            ->orWhereHas('comune', fn (Builder $c) => $c
                ->where('chiave', 'like', $this->chiavi->perCercare($testo).'%')));
    }

    /**
     * @param  Builder<ProfiloPubblico>  $query
     * @param  array<int, int>  $vicini  Gli id dei comuni entro il raggio, gia' in ordine di distanza
     * @return Builder<ProfiloPubblico>
     */
    private function ordina(Builder $query, ?Comune $centro, array $vicini): Builder
    {
        /*
         * 🚨 **Gli sponsorizzati per primi, e con l'etichetta** (M5).
         *
         * ⚠️ `campagna_id` non basta: una campagna esaurita o spenta non deve
         * comparire in cima. Finche' `campagne` non esiste (M5) la colonna e'
         * sempre nulla e questo ordinamento non fa niente — ma sta gia' qui,
         * perche' aggiungerlo dopo vorrebbe dire ricontrollare ogni test
         * dell'ordinamento invece di scriverli una volta sola.
         */
        $query->orderByRaw('CASE WHEN campagna_id IS NULL THEN 1 ELSE 0 END');

        if ($centro === null) {
            /*
             * 💡 Chi non ha detto dove sta: si ordina per nome. ⚠️ **Non** a
             * caso e **non** per data: un ordine instabile farebbe cambiare i
             * risultati a ogni ricarica, e chi paga la pubblicita' avrebbe
             * ragione a chiedere perche'.
             */
            return $query->orderBy('titolo');
        }

        $query->orderByRaw('CASE WHEN comune_id = ? THEN 0 ELSE 1 END', [$centro->id]);

        if ($vicini !== []) {
            /*
             * 🚨 `FIELD()` conserva **l'ordine di distanza** gia' calcolato da
             * `Vicinanza`: la lista arriva ordinata dal piu' vicino al piu'
             * lontano, e questo la trasferisce ai profili senza ricalcolare
             * niente.
             *
             * ⚠️ Chi non e' nell'elenco riceve `FIELD() = 0`, che in `ASC`
             * verrebbe **per primo**: e' esattamente il contrario di quello che
             * serve, perche' sono i piu' lontani. Da qui il `= 0` in coda al
             * `CASE`.
             */
            $posti = implode(',', array_map('intval', $vicini));
            $query->orderByRaw("CASE WHEN FIELD(comune_id, {$posti}) = 0 THEN 1 ELSE 0 END");
            $query->orderByRaw("FIELD(comune_id, {$posti})");
        }

        return $query->orderBy('titolo');
    }

    /**
     * Attacca alla scheda quello che l'app deve poter mostrare senza chiedere
     * altro: la distanza e se e' sponsorizzata.
     */
    private function arricchisci(ProfiloPubblico $p, ?Comune $centro): ProfiloPubblico
    {
        $p->setAttribute(
            'distanza_km',
            $centro !== null && $p->comune !== null
                ? $this->arrotonda($this->vicinanza->distanzaKm($centro, $p->comune))
                : null,
        );

        /*
         * 🚨 **L'etichetta «Sponsorizzato» non e' facoltativa.**
         *
         * ⚠️ Presentare a pagamento qualcosa che sembra un risultato di ricerca
         * e' pubblicita' occulta. Basta la parola, ma deve esserci — e c'e' un
         * test in M5 che la pretende.
         */
        $p->setAttribute('sponsorizzato', $p->campagna_id !== null);

        return $p;
    }

    private function arrotonda(?float $km): ?float
    {
        return $km !== null ? round($km, 1) : null;
    }
}
