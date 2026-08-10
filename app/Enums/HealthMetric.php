<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le misure che l'orologio manda **oltre** al sonno — D3.
 *
 * 🚨 **Perché una tabella nuova e non `health_samples`.** Quella tabella è fatta
 * per il sonno: ha `night` e `stage`, e ogni riga è un *intervallo* di una fase.
 * Una misura di HRV è un *punto* nel tempo con un valore. Piegare la tabella del
 * sonno a contenerla vorrebbe dire due colonne sempre nulle su ogni riga e uno
 * `stage` che non significa niente per metà dei dati — cioè uno schema che non
 * si può più leggere senza sapere quale tipo di riga si sta guardando.
 *
 * ⚠️ **Nessuno manda ancora questi dati.** L'app storica dal watch prende *solo*
 * il sonno, per scelta esplicita. Qui l'ingest li accetta, ma finché il ponte
 * sul telefono non abilita anche queste categorie la dashboard li mostrerà
 * assenti — e deve dirlo, non mostrare zeri.
 */
enum HealthMetric: string
{
    /**
     * Variabilità della frequenza cardiaca, in millisecondi (RMSSD).
     *
     * È l'indicatore di recupero più usato: **scende** quando il corpo è sotto
     * stress. Una lettura sola non dice niente — conta lo scostamento dalla
     * media della persona, ed è il motivo per cui la dashboard mostra sempre
     * anche la media a sette giorni.
     */
    case Hrv = 'hrv';

    /** Frequenza cardiaca a riposo, in battiti al minuto. */
    case RestingHeartRate = 'resting_hr';

    /** Frequenza cardiaca media della giornata, in battiti al minuto. */
    case HeartRate = 'hr';

    public function label(): string
    {
        return match ($this) {
            self::Hrv => 'Variabilità cardiaca',
            self::RestingHeartRate => 'Battito a riposo',
            self::HeartRate => 'Battito medio',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Hrv => 'ms',
            self::RestingHeartRate, self::HeartRate => 'bpm',
        };
    }

    /**
     * Il valore oltre il quale la misura è certamente un errore di lettura.
     *
     * ⚠️ Serve a scartare i campioni assurdi **all'ingresso**: un HRV di 900 ms
     * o un battito di 300 non sono dati, sono un sensore che ha sbagliato. Se
     * entrassero, sposterebbero la media e da quel momento tutti gli
     * scostamenti calcolati su di essa sarebbero falsi — senza che niente lo
     * segnali.
     *
     * @return array{0: float, 1: float} minimo e massimo plausibili
     */
    public function plausibleRange(): array
    {
        return match ($this) {
            self::Hrv => [5.0, 300.0],
            self::RestingHeartRate => [25.0, 120.0],
            self::HeartRate => [30.0, 220.0],
        };
    }

    public function isPlausible(float $valore): bool
    {
        [$min, $max] = $this->plausibleRange();

        return $valore >= $min && $valore <= $max;
    }
}
