<?php

declare(strict_types=1);

namespace App\Support\Tempo;

use App\Models\User;
use Illuminate\Support\Carbon;
use Stringable;

/**
 * Un giorno **come lo chiama una persona** — il tipo che chiude A3.
 *
 * ── 🚨 Il difetto che questo tipo rende impossibile ────────────────────────
 *
 * Prima esisteva un solo tipo per due cose diverse: `Carbon $date`. Ma un
 * giorno e' **due cose insieme**, e confonderle e' esattamente il difetto:
 *
 * - un'**etichetta** (`2026-08-12`), che serve a scriverci sopra «12 agosto»,
 *   a raggruppare lo storico e a fare da chiave di cache;
 * - una **finestra di istanti** (`[2026-08-11T22:00Z, 2026-08-12T21:59:59Z]`),
 *   che serve a confrontare i timestamp del database.
 *
 * Con `Carbon` sola, `->startOfDay()` tagliava la giornata **in UTC**: a Roma
 * alle 00:30 il diario si apriva su ieri, e la cena registrata tardi finiva nel
 * giorno prima. E la trappola vera veniva dopo: `inizioDiOggi()->toDateString()`
 * restituisce **le 22:00 del giorno prima**, cioe' lo stesso difetto spostato di
 * un metodo. Qui non si puo' sbagliare, perche' l'etichetta e la finestra si
 * chiedono con due metodi diversi.
 *
 * ── 💡 Perche' lo stato canonico e' l'etichetta, non l'istante ─────────────
 *
 * L'oggetto conserva `(etichetta, fuso)` e **ricalcola** la finestra ogni volta.
 * Non e' uno spreco: e' cio' che lo rende corretto sull'ora legale.
 *
 * Il 26 ottobre 2026 a Roma dura **25 ore**. Un `piuGiorni(1)` fatto sommando
 * 86.400 secondi a un istante cadrebbe alle 23:00 del giorno prima; fatto
 * sull'etichetta e' sempre il giorno dopo, e la finestra viene ricostruita con
 * l'offset giusto per **quel** giorno.
 *
 * ── ⚠️ La regola d'uso ─────────────────────────────────────────────────────
 *
 * `inizio()` e `fine()` tornano istanti **in UTC**: si confrontano con le
 * colonne `datetime`, che restano in UTC e devono restarci. `etichetta` si
 * confronta con le colonne `date` (`daily_burns.date`, `ai_advices.date`), che
 * sono gia' etichette. **Non si mescolano.**
 */
final class GiornoLocale implements Stringable
{
    private function __construct(
        /** Il giorno come `Y-m-d`, nel fuso di chi guarda. */
        public readonly string $etichetta,
        /** L'identificativo IANA, es. `Europe/Rome`. */
        public readonly string $fuso,
    ) {}

    // ───────────────────────── costruttori ─────────────────────────

    /**
     * Oggi, per questa persona.
     *
     * @param  Carbon|null  $adesso  l'istante da cui guardare; `null` = ora.
     *                               Serve ai test per fermare il tempo alle 00:30.
     */
    public static function oggi(User $utente, ?Carbon $adesso = null): self
    {
        return self::daIstante($adesso ?? Carbon::now(), $utente->fusoOrario());
    }

    /**
     * Il giorno chiesto da questa persona.
     *
     * Accetta sia un'etichetta (`'2026-08-12'`, come arriva da una query
     * string) sia un istante, e li tratta per quello che sono: l'etichetta e'
     * gia' nel fuso di chi guarda e si prende cosi' com'e'; l'istante va
     * **prima convertito**, perche' un `Carbon` in UTC alle 22:30 e' gia'
     * domani a Roma.
     */
    public static function perUtente(User $utente, string|Carbon $giorno): self
    {
        $fuso = $utente->fusoOrario();

        return is_string($giorno)
            ? new self(Carbon::parse($giorno, $fuso)->toDateString(), $fuso)
            : self::daIstante($giorno, $fuso);
    }

    /** L'etichetta grezza, quando il fuso e' gia' noto. */
    public static function conFuso(string $etichetta, string $fuso): self
    {
        return new self($etichetta, $fuso);
    }

    /**
     * A quale giorno appartiene questo istante, per chi vive in questo fuso.
     *
     * 💡 Serve quando si ha in mano un timestamp del database — `started_at`,
     * `eaten_at` — e si vuole sapere in che giornata cade **per la persona**.
     */
    public static function perIstante(Carbon $istante, string $fuso): self
    {
        return self::daIstante($istante, $fuso);
    }

    /**
     * A quale giorno appartiene questo istante, per chi vive in questo fuso.
     *
     * 🚨 **E' il metodo che serve ai `groupBy` dello storico.** Raggruppare con
     * `$voce->eaten_at->toDateString()` significa raggruppare in UTC: una cena
     * delle 00:30 finisce nel giorno prima, e il grafico mostra una serata di
     * digiuno seguita da una colazione da 900 kcal.
     */
    public static function etichettaDi(Carbon $istante, string $fuso): string
    {
        return $istante->copy()->setTimezone($fuso)->toDateString();
    }

    private static function daIstante(Carbon $istante, string $fuso): self
    {
        return new self($istante->copy()->setTimezone($fuso)->toDateString(), $fuso);
    }

    // ───────────────────────── la finestra ─────────────────────────

    /**
     * L'istante in cui comincia questo giorno, **in UTC**.
     *
     * Per Roma d'estate sono le 22:00 del giorno prima. ⚠️ Chiedergli
     * `->toDateString()` da' l'etichetta sbagliata: per quella c'e' `$etichetta`.
     */
    public function inizio(): Carbon
    {
        return $this->locale()->setTimezone('UTC');
    }

    /** L'ultimo istante di questo giorno, **in UTC**. */
    public function fine(): Carbon
    {
        return $this->locale()->endOfDay()->setTimezone('UTC');
    }

    /**
     * La finestra pronta per un `whereBetween`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function finestra(): array
    {
        return [$this->inizio(), $this->fine()];
    }

    /**
     * La finestra che va da questo giorno **fino a** un altro, estremi inclusi.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function finestraFinoA(self $ultimo): array
    {
        return [$this->inizio(), $ultimo->fine()];
    }

    /**
     * Un `Carbon` **nel fuso locale**, a mezzanotte di questo giorno.
     *
     * 💡 Serve solo per formattare (`translatedFormat('l d F Y')`, `->day`,
     * `->month`). ⚠️ Non va confrontato con le colonne del database: per quello
     * ci sono `inizio()` e `fine()`.
     */
    public function locale(): Carbon
    {
        return Carbon::parse($this->etichetta, $this->fuso)->startOfDay();
    }

    // ───────────────────────── aritmetica ─────────────────────────

    /** @param int $giorni anche negativo */
    public function piuGiorni(int $giorni): self
    {
        return new self($this->locale()->addDays($giorni)->toDateString(), $this->fuso);
    }

    public function menoGiorni(int $giorni): self
    {
        return $this->piuGiorni(-$giorni);
    }

    public function menoAnni(int $anni): self
    {
        return new self($this->locale()->subYears($anni)->toDateString(), $this->fuso);
    }

    /**
     * ⚠️ **Da usare solo su un primo del mese** — cioe' dopo `inizioMese()`.
     *
     * Su altri giorni `addMonths()` trabocca: il 31 gennaio piu' un mese da' il
     * 3 marzo, non il 28 febbraio. E' la stessa classe di trappola dell'ora
     * legale, e la si evita partendo sempre dal primo.
     */
    public function piuMesi(int $mesi): self
    {
        return new self($this->locale()->addMonths($mesi)->toDateString(), $this->fuso);
    }

    /** ⚠️ Lunedi', non domenica: la griglia del calendario ha lunedi' in prima colonna. */
    public function inizioSettimana(): self
    {
        return new self($this->locale()->startOfWeek()->toDateString(), $this->fuso);
    }

    public function fineSettimana(): self
    {
        return new self($this->locale()->endOfWeek()->toDateString(), $this->fuso);
    }

    public function inizioMese(): self
    {
        return new self($this->locale()->startOfMonth()->toDateString(), $this->fuso);
    }

    public function fineMese(): self
    {
        return new self($this->locale()->endOfMonth()->toDateString(), $this->fuso);
    }

    // ───────────────────────── confronti ─────────────────────────

    public function eUgualeA(self $altro): bool
    {
        return $this->etichetta === $altro->etichetta;
    }

    public function primaDi(self $altro): bool
    {
        return $this->etichetta < $altro->etichetta;
    }

    public function nonDopoDi(self $altro): bool
    {
        return $this->etichetta <= $altro->etichetta;
    }

    /**
     * Quanti giorni separano questo giorno da un altro, in valore assoluto.
     *
     * ⚠️ Si conta sulle **etichette**, non sugli istanti: fra il 25 e il 26
     * ottobre a Roma passano 25 ore, e una divisione per 86.400 direbbe «1,04
     * giorni» — che troncato e' 1 per fortuna, non per costruzione.
     */
    public function giorniDa(self $altro): int
    {
        return (int) abs($this->locale()->diffInDays($altro->locale()));
    }

    /**
     * I giorni da questo a `$ultimo`, estremi inclusi.
     *
     * 💡 Sostituisce i `for ($g = $da->copy(); $g <= $a; $g->addDay())`, che
     * con un oggetto immutabile non funzionerebbero.
     *
     * @return list<self>
     */
    public function finoA(self $ultimo): array
    {
        $giorni = [];

        for ($g = $this; $g->nonDopoDi($ultimo); $g = $g->piuGiorni(1)) {
            $giorni[] = $g;

            // Rete di sicurezza: una finestra al contrario o un fuso assurdo non
            // devono poter appendere il processo su un ciclo infinito.
            if (count($giorni) > 4000) {
                break;
            }
        }

        return $giorni;
    }

    public function __toString(): string
    {
        return $this->etichetta;
    }
}
