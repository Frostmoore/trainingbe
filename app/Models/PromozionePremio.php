<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DurataDelloSconto;
use App\Enums\GenereDiPromozione;
use App\Enums\TipoDiPremio;
use App\Enums\TipoDiSconto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🎀 **Un premio di una promozione** — 24/09/2026, fase P1 di `plan_promozioni.md`.
 *
 * Il `tipo` dice quali colonne contano (`TipoDiPremio::campi()`); le altre
 * restano `null`. ⚠️ `valore` è una percentuale **o** dei centesimi, secondo
 * `tipo_sconto`, come in F2: non si deduce dal numero.
 */
class PromozionePremio extends Model
{
    protected $table = 'promozione_premi';

    protected $fillable = [
        'promozione_id', 'tipo', 'a_chi', 'gettoni', 'tipo_sconto', 'valore', 'prezzo_cent',
        'pacchetto_id', 'plan_id', 'da_plan_id', 'durata', 'volte', 'giorni', 'posti', 'sblocca_genere',
    ];

    protected $attributes = [
        'a_chi' => 'destinatario',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDiPremio::class,
            'tipo_sconto' => TipoDiSconto::class,
            'durata' => DurataDelloSconto::class,
            'sblocca_genere' => GenereDiPromozione::class,
            'gettoni' => 'integer',
            'valore' => 'integer',
            'prezzo_cent' => 'integer',
            'volte' => 'integer',
            'giorni' => 'integer',
            'posti' => 'integer',
        ];
    }

    public function promozione(): BelongsTo
    {
        return $this->belongsTo(Promozione::class);
    }

    public function pacchetto(): BelongsTo
    {
        return $this->belongsTo(PacchettoGettoni::class, 'pacchetto_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function daPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'da_plan_id');
    }

    // ───────────────────────── il conto ─────────────────────────

    /**
     * 💰 Quanto toglie da questo importo, in centesimi.
     *
     * 🚨 **Mai sotto zero**, come in F2: uno sconto più grande del prezzo non
     * regala la differenza. Il prezzo speciale di un pacchetto toglie la
     * differenza col listino, e se il prezzo speciale è **più alto** non
     * toglie niente — ⛔ non alza il prezzo.
     */
    public function scontoSu(int $importoCent): int
    {
        if ($importoCent <= 0 || ! $this->tipo->eUnoSconto()) {
            return 0;
        }

        $sconto = match (true) {
            $this->tipo === TipoDiPremio::PrezzoPacchetto => $importoCent - (int) $this->prezzo_cent,
            $this->tipo_sconto === TipoDiSconto::Percentuale => (int) round($importoCent * (int) $this->valore / 100),
            default => (int) $this->valore,
        };

        return max(0, min($sconto, $importoCent));
    }

    /** Vale su questo pacchetto? `pacchetto_id` vuoto = su tutti. */
    public function valeSulPacchetto(?int $pacchettoId): bool
    {
        return $this->pacchetto_id === null || $this->pacchetto_id === $pacchettoId;
    }

    /** Vale su questo piano? `plan_id` vuoto = su tutti. */
    public function valeSulPiano(?int $planId): bool
    {
        return $this->plan_id === null || $this->plan_id === $planId;
    }

    // ───────────────────────── come si legge ─────────────────────────

    /** «20% sui gettoni», «+100 gettoni», «3 mesi di Advanced gratis»… */
    public function scrittoBene(): string
    {
        $sconto = fn (): string => $this->tipo_sconto?->scrivi((int) $this->valore) ?? '?';
        $pacchetto = fn (): string => $this->pacchetto?->nome ?? 'tutti i pacchetti';
        $piano = fn (): string => $this->plan?->name ?? 'tutti i piani';

        return match ($this->tipo) {
            TipoDiPremio::GettoniRegalo => $this->gettoni.' gettoni in regalo'.$this->scadenza(),
            TipoDiPremio::GettoniBonus => 'bonus di '.$this->bonus().' su '.$pacchetto(),
            TipoDiPremio::ScontoGettoni => $sconto().' su '.$pacchetto(),
            TipoDiPremio::PrezzoPacchetto => $pacchetto().' a '.number_format((int) $this->prezzo_cent / 100, 2, ',', '.').' €',
            TipoDiPremio::GettoniRicorrenti => $this->gettoni.' gettoni al mese per '.$this->volte.' mesi'.$this->scadenza(),
            TipoDiPremio::ScontoAbbonamento => $sconto().' su '.$piano().' ('.mb_strtolower($this->durataScritta()).')',
            TipoDiPremio::ProvaGratuita => $this->giorni.' giorni di prova gratuita su '.$piano(),
            TipoDiPremio::AbbonamentoRegalato => $this->volte.' periodi di '.$piano().' in regalo',
            TipoDiPremio::Upgrade => $piano().' gratis per '.$this->volte.' mesi',
            TipoDiPremio::PrezzoBloccato => 'prezzo bloccato su '.$piano(),
            TipoDiPremio::PostiExtra => '+'.$this->posti.' posti per '.$this->volte.' mesi',
            TipoDiPremio::ScontoConsumo => $sconto().' sul piano a consumo',
            TipoDiPremio::GettoniAOgniRinnovo => $this->gettoni.' gettoni a ogni rinnovo',
            TipoDiPremio::SbloccaSconto => 'sblocca '.$sconto().' su '.mb_strtolower($this->sblocca_genere?->label() ?? '?'),
        };
    }

    private function durataScritta(): string
    {
        return match ($this->durata) {
            DurataDelloSconto::NVolte => 'per '.$this->volte.' rinnovi',
            null => '?',
            default => $this->durata->label(),
        };
    }

    private function bonus(): string
    {
        $parti = array_filter([
            $this->gettoni ? '+'.$this->gettoni.' gettoni' : null,
            $this->valore ? '+'.$this->valore.'%' : null,
        ]);

        return implode(' e ', $parti) ?: '?';
    }

    private function scadenza(): string
    {
        return $this->giorni ? ' (scadono dopo '.$this->giorni.' giorni)' : '';
    }
}
