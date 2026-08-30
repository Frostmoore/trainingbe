<?php

declare(strict_types=1);

namespace App\Support\Tempo;

use App\Models\User;
use Carbon\Carbon;
use Stringable;

/**
 * La fascia oraria del consiglio del giorno — 3b-AB, 30/08/2026.
 *
 * ══ 📌 LA REGOLA, IN UNA RIGA ═════════════════════════════════════════════
 *
 * 📌 Il committente: *«bisogna fare in modo che il consiglio del giorno si
 * rigeneri in automatico solo 3 volte al giorno (9:00, 14:00 e 22:00) … se ad
 * esempio sono le 9:35 e io registro gli alimenti alle 9:41, si usa lo slot
 * delle 9 fino alle 14:01»*.
 *
 * | Fascia | Copre |
 * |---|---|
 * | `09` | 09:00 → 13:59 |
 * | `14` | 14:00 → 21:59 |
 * | `22` | 22:00 → 08:59 **del giorno dopo** |
 *
 * ══ 🚨 PERCHE' ESISTE: IL TETTO ERA SCRITTO E NON C'ERA ═══════════════════
 *
 * `AiController` diceva in un commento *«al massimo una volta per fascia — e
 * cosi' il tetto di tre al giorno resta»*. ⛔ **Le fasce non esistevano.** La
 * cache era su `(giorno, hash del contesto)`, e nell'hash ci sono `totals`,
 * `burned` e `targets`: **ogni pasto registrato era un consiglio nuovo,
 * pagato**. Colazione, spuntino, pranzo, merenda, cena e allenamento facevano
 * sei chiamate, non tre.
 *
 * ⚠️ E' la classe di difetto che questo progetto insegue da settimane: un
 * commento che descrive un comportamento che nessuna riga produce. Non dava
 * errori — dava una bolletta.
 *
 * ══ 🌙 PERCHE' LA FASCIA DELLE 22 SCAVALCA LA MEZZANOTTE ══════════════════
 *
 * Perche' le fasce sono **tre**, e prima delle 09:00 l'ultimo confine passato
 * e' quello delle 22:00 di ieri. 💡 Chi apre l'app alle 07:00 sta ancora dentro
 * la fascia della sera prima: se un consiglio di quella fascia c'e' gia', non
 * se ne genera un altro.
 *
 * ⛔ **L'alternativa sarebbe una quarta fascia notturna**, cioe' quattro
 * chiamate in ventiquattr'ore invece di tre. Il committente ne ha dette tre.
 *
 * 🚨 Da qui una conseguenza che va tenuta a mente leggendo `AiAdvice`: la riga
 * di un consiglio generato alle 07:00 porta la data di **ieri**, perche' e' il
 * giorno a cui appartiene la sua fascia. E' voluto, ed e' il motivo per cui
 * `AiAdvice::pota()` prende una fascia e non un giorno.
 *
 * ══ 💡 PERCHE' UN OGGETTO E NON DUE FUNZIONI ══════════════════════════════
 *
 * Perche' l'etichetta (`2026-08-30T09`) e il giorno della riga (`2026-08-30`)
 * devono nascere dallo **stesso** calcolo. ⚠️ Calcolarli in due punti vuol dire
 * che un giorno divergeranno, e una cache che cerca con una chiave e scrive con
 * un'altra **non sbaglia mai in modo visibile**: genera e basta.
 */
final class FasciaDelConsiglio implements Stringable
{
    /**
     * I confini, in ore locali, **dal piu' tardi al piu' presto**.
     *
     * 🚨 L'ordine non e' estetico: [daIstante] scorre questa lista e si ferma
     * al primo confine gia' passato. Riordinarla dal basso farebbe scattare
     * sempre il primo, cioe' darebbe la fascia delle 9 anche a mezzanotte.
     *
     * @var list<int>
     */
    public const CONFINI = [22, 14, 9];

    private function __construct(
        /** Il giorno a cui appartiene la fascia, nel fuso di chi guarda. */
        public readonly GiornoLocale $giorno,
        /** L'ora del confine: 9, 14 o 22. */
        public readonly int $ora,
    ) {}

    // ───────────────────────── costruttori ─────────────────────────

    /**
     * La fascia in cui si trova adesso questa persona.
     *
     * @param  Carbon|null  $adesso  l'istante da cui guardare; `null` = ora.
     *                               ⚠️ Serve ai test, che devono poter fermare
     *                               il tempo alle 08:59 e alle 09:00.
     */
    public static function adesso(User $utente, ?Carbon $adesso = null): self
    {
        return self::perIstante($adesso ?? Carbon::now(), $utente->fusoOrario());
    }

    /** A quale fascia appartiene questo istante, per chi vive in questo fuso. */
    public static function perIstante(Carbon $istante, string $fuso): self
    {
        $locale = $istante->copy()->setTimezone($fuso);

        foreach (self::CONFINI as $ora) {
            if ($locale->hour >= $ora) {
                return new self(GiornoLocale::conFuso($locale->toDateString(), $fuso), $ora);
            }
        }

        /*
         * 🌙 **Prima delle 09:00 si appartiene alla sera di ieri.** Vedi la
         * nota in testa: e' quello che tiene il tetto a tre e non a quattro.
         */
        return new self(
            GiornoLocale::conFuso($locale->copy()->subDay()->toDateString(), $fuso),
            self::CONFINI[0],
        );
    }

    /** Da un'etichetta gia' scritta (`2026-08-30T09`), quando il fuso e' noto. */
    public static function daEtichetta(string $etichetta, string $fuso): self
    {
        [$giorno, $ora] = explode('T', $etichetta, 2);

        return new self(GiornoLocale::conFuso($giorno, $fuso), (int) $ora);
    }

    // ───────────────────────── come si scrive ─────────────────────────

    /**
     * L'etichetta che finisce in colonna: `2026-08-30T09`.
     *
     * 💡 L'ora e' **sempre a due cifre** (`09`, non `9`): due formati per la
     * stessa fascia sarebbero due righe distinte per l'indice unico, cioe' due
     * chiamate al modello dove ne serviva una.
     */
    public function etichetta(): string
    {
        return $this->giorno->etichetta.'T'.str_pad((string) $this->ora, 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->etichetta();
    }

    public function eUgualeA(self $altra): bool
    {
        return $this->etichetta() === $altra->etichetta();
    }
}
