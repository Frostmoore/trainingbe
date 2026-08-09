<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le fasi del sonno, nella nostra numerazione.
 *
 * 🚨 **NON e' la numerazione di Health Connect**, ed e' voluto: la loro ha sette
 * codici, tre dei quali significano «sveglio» in modi leggermente diversi
 * (`1` sconosciuto, `7` fuori dal letto, `3` sveglio). Tenerli tutti
 * significherebbe scrivere la stessa condizione in tre posti ogni volta che si
 * calcola qualcosa.
 *
 * La traduzione avviene una volta sola, all'ingest, in `fromHealthConnect()`.
 */
enum SleepStage: int
{
    case Awake = 1;
    case Light = 2;
    case Deep = 3;
    case Rem = 4;

    public function label(): string
    {
        return match ($this) {
            self::Awake => 'Sveglio',
            self::Light => 'Leggero',
            self::Deep => 'Profondo',
            self::Rem => 'REM',
        };
    }

    /** Il campione conta come sonno effettivo? */
    public function isAsleep(): bool
    {
        return $this !== self::Awake;
    }

    /**
     * I codici di Health Connect: `1/7/3 = sveglio, 4/2 = leggero, 5 = profondo,
     * 6 = REM`.
     *
     * Un codice sconosciuto vale «sveglio» e non `null`: un campione scartato
     * accorcerebbe la notte in silenzio, mentre contarlo come veglia al massimo
     * la peggiora — che e' l'errore innocuo dei due.
     */
    public static function fromHealthConnect(int $codice): self
    {
        return match ($codice) {
            4, 2 => self::Light,
            5 => self::Deep,
            6 => self::Rem,
            default => self::Awake,
        };
    }
}
