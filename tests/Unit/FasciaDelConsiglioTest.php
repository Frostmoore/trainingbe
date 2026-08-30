<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Tempo\FasciaDelConsiglio;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le tre fasce del consiglio del giorno — 3b-AB.
 *
 * ══ 🎯 COSA PROVA, E PERCHE' SENZA DATABASE ═══════════════════════════════
 *
 * 📌 *«il consiglio del giorno si rigeneri in automatico solo 3 volte al giorno
 * (9:00, 14:00 e 22:00) … se ad esempio sono le 9:35 e io registro gli alimenti
 * alle 9:41, si usa lo slot delle 9 fino alle 14:01»*.
 *
 * 💡 **La regola e' aritmetica, e si prova come tale**: quale confine e' l'ultimo
 * passato. Montare un utente e un database per rispondere a questa domanda
 * vorrebbe dire far durare due minuti la prova di una condizione.
 *
 * 🚨 **I confini si provano al minuto prima e al minuto stesso.** Un errore di
 * un'ora in una fascia non da' nessun sintomo visibile: si traduce in una
 * chiamata al modello in piu' o in meno, e nessuno se ne accorge guardando
 * l'app.
 */
final class FasciaDelConsiglioTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function momenti(): array
    {
        return [
            // ── la notte appartiene alla sera prima ────────────────────────
            'mezzanotte e un minuto' => ['2026-08-30 00:01', '2026-08-29T22'],
            'le sei del mattino' => ['2026-08-30 06:00', '2026-08-29T22'],
            'un minuto prima delle nove' => ['2026-08-30 08:59', '2026-08-29T22'],

            // ── la fascia delle 9 ─────────────────────────────────────────
            'le nove in punto' => ['2026-08-30 09:00', '2026-08-30T09'],
            'le nove e trentacinque' => ['2026-08-30 09:35', '2026-08-30T09'],
            'le nove e quarantuno' => ['2026-08-30 09:41', '2026-08-30T09'],
            'un minuto prima delle due' => ['2026-08-30 13:59', '2026-08-30T09'],

            // ── la fascia delle 14 ────────────────────────────────────────
            'le due in punto' => ['2026-08-30 14:00', '2026-08-30T14'],
            'le due e un minuto' => ['2026-08-30 14:01', '2026-08-30T14'],
            'un minuto prima delle dieci' => ['2026-08-30 21:59', '2026-08-30T14'],

            // ── la fascia delle 22 ────────────────────────────────────────
            'le dieci di sera' => ['2026-08-30 22:00', '2026-08-30T22'],
            'un minuto a mezzanotte' => ['2026-08-30 23:59', '2026-08-30T22'],
        ];
    }

    #[Test]
    #[DataProvider('momenti')]
    public function ogni_momento_cade_nella_sua_fascia(string $locale, string $atteso): void
    {
        $fascia = FasciaDelConsiglio::perIstante(
            Carbon::parse($locale, 'Europe/Rome'),
            'Europe/Rome',
        );

        $this->assertSame($atteso, $fascia->etichetta());
    }

    /**
     * 🚨 **La fascia si legge nel fuso di CHI GUARDA, non in UTC.**
     *
     * ⚠️ E' lo stesso difetto A3 che `GiornoLocale` esiste per chiudere: le
     * 08:00 UTC del 30 agosto sono le **10:00** a Roma, cioe' gia' la fascia
     * delle 9. ⛔ Leggendole in UTC finirebbero nella fascia delle 22 del
     * giorno prima, e chi apre l'app a meta' mattina si troverebbe il
     * consiglio della sera precedente senza capire perche'.
     */
    #[Test]
    public function la_fascia_e_quella_di_chi_guarda(): void
    {
        $istante = Carbon::parse('2026-08-30 08:00', 'UTC');

        $this->assertSame(
            '2026-08-30T09',
            FasciaDelConsiglio::perIstante($istante, 'Europe/Rome')->etichetta(),
        );

        /*
         * ⛔ La stessa identica ora, per chi vive a New York, sono le **04:00**:
         * e' ancora notte, cioe' la fascia della sera prima.
         *
         * ⚠️ Qui c'era Londra, e non provava niente: d'estate e' UTC+1, quindi
         * le 08:00 UTC sono gia' le 09:00 — la stessa fascia di Roma. 💡 Un
         * test che confronta due fusi deve sceglierne uno **davvero** indietro,
         * altrimenti passa per caso.
         */
        $this->assertSame(
            '2026-08-29T22',
            FasciaDelConsiglio::perIstante($istante, 'America/New_York')->etichetta(),
        );
    }

    /**
     * 💡 In ventiquattr'ore le fasce distinte sono **tre**, non quattro.
     *
     * 🚨 E' il conto che rende vera la frase *«tre chiamate al giorno»*: se la
     * notte facesse fascia a se', sarebbero quattro, e il tetto sarebbe un
     * terzo piu' alto di quello promesso.
     */
    #[Test]
    public function in_un_giorno_intero_le_fasce_sono_tre(): void
    {
        $viste = [];

        for ($minuto = 0; $minuto < 24 * 60; $minuto++) {
            $viste[FasciaDelConsiglio::perIstante(
                Carbon::parse('2026-08-30 00:00', 'Europe/Rome')->addMinutes($minuto),
                'Europe/Rome',
            )->etichetta()] = true;
        }

        // ⚠️ Quattro etichette, ma una e' la coda della sera prima: le fasce
        // che *cominciano* dentro la giornata restano tre.
        $this->assertSame(
            ['2026-08-29T22', '2026-08-30T09', '2026-08-30T14', '2026-08-30T22'],
            array_keys($viste),
        );
    }

    /** L'etichetta si rilegge, e torna la stessa fascia. */
    #[Test]
    public function l_etichetta_si_rilegge(): void
    {
        $fascia = FasciaDelConsiglio::daEtichetta('2026-08-30T09', 'Europe/Rome');

        $this->assertSame(9, $fascia->ora);
        $this->assertSame('2026-08-30', $fascia->giorno->etichetta);
        $this->assertSame('2026-08-30T09', $fascia->etichetta());
    }

    /**
     * ⛔ **L'ora si scrive sempre a due cifre.**
     *
     * 🚨 `T9` e `T09` sarebbero due righe distinte per l'indice unico, cioe'
     * due chiamate al modello dove ne bastava una — e nessun errore da nessuna
     * parte.
     */
    #[Test]
    public function l_ora_ha_sempre_due_cifre(): void
    {
        $fascia = FasciaDelConsiglio::perIstante(
            Carbon::parse('2026-08-30 09:30', 'Europe/Rome'),
            'Europe/Rome',
        );

        $this->assertStringEndsWith('T09', $fascia->etichetta());
    }
}
