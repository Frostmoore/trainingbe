<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoDiCondizione;
use App\Models\Concerns\AppartieneAUnPubblico;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔎 **Una condizione sui destinatari di una promozione** — 24/09/2026, P5 di
 * `plan_promozioni.md`.
 *
 * ⚠️ In **AND** con le altre e con i bersagli: la valuta
 * `App\Services\Promozioni\ChiLaRiceve`, non questo modello.
 */
class PromozioneCondizione extends Model
{
    use AppartieneAUnPubblico;

    protected $table = 'promozione_condizioni';

    protected $fillable = ['promozione_id', 'pubblico_type', 'pubblico_id', 'tipo', 'parametri'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDiCondizione::class,
            'parametri' => 'array',
        ];
    }

    public function promozione(): BelongsTo
    {
        return $this->belongsTo(Promozione::class);
    }

    /** Un parametro, o `null`. */
    public function parametro(string $chiave): mixed
    {
        return ((array) $this->parametri)[$chiave] ?? null;
    }

    /** «Nuovi iscritti (da meno di 30 giorni)», «Per abbonamento: ex abbonati»… */
    public function descrizione(): string
    {
        return match ($this->tipo) {
            TipoDiCondizione::Abbonamento => mb_strtolower(TipoDiCondizione::STATI_ABBONAMENTO[(string) $this->parametro('stato')] ?? '?'),
            TipoDiCondizione::RegistratoDaMenoDi => 'iscritti da meno di '.$this->parametro('giorni').' giorni',
            TipoDiCondizione::RegistratoDopo => 'iscritti dopo il '.$this->parametro('data'),
            TipoDiCondizione::ClienteDaPiuDi => 'clienti da più di '.$this->parametro('mesi').' mesi',
            TipoDiCondizione::PianoAttuale => 'con il piano '.Plan::query()->whereIn('id', (array) $this->parametro('plan_ids'))->pluck('name')->join(', ', ' o '),
            TipoDiCondizione::InattivoDa => 'inattivi da '.$this->parametro('giorni').' giorni',
            TipoDiCondizione::MaiCompratoGettoni => 'mai comprato gettoni',
            TipoDiCondizione::GettoniFiniti => 'con i gettoni finiti',
            TipoDiCondizione::GettoniComprati => 'che hanno comprato almeno '.$this->parametro('gettoni').' gettoni',
        };
    }
}
