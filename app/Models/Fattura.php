<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🧾 Una fattura — fase F8, 23/09/2026.
 *
 * Nasce da un **incasso** (`PagamentoIncassato`) quando a pagare è una palestra
 * (vedi `EmissioneDelleFatture::laPagaLaPalestra()`), e vive in due stati:
 *
 * | Stato | Numero | Cosa vuol dire |
 * |---|---|---|
 * | `in_attesa` | nessuno | il pagamento c'è, ma alla palestra mancano i dati per fatturare |
 * | `emessa` | `12/TC` | ha numero, data, cliente fotografato e XML: **non cambia più** |
 *
 * ⛔ **Una fattura emessa non si modifica e non si cancella.** Se è sbagliata si
 * fa una nota di credito (debito dichiarato: non esiste ancora, vedi l'atlante).
 * Il modello non lo impedisce a livello di Eloquent, ma nessuna pagina offre
 * «modifica» o «elimina».
 *
 * @property array<string, string|null>|null $cessionario
 */
class Fattura extends Model
{
    use BelongsToTenant;

    protected $table = 'fatture';

    public const IN_ATTESA = 'in_attesa';

    public const EMESSA = 'emessa';

    protected $fillable = [
        'tenant_id', 'pagamento_incassato_id', 'stato',
        'anno', 'numero', 'data', 'sezionale',
        'descrizione', 'imponibile_cent', 'bollo_cent', 'totale_cent',
        'cessionario', 'xml', 'progressivo_invio', 'emessa_il',
    ];

    /**
     * ⚠️ L'XML non va in giro serializzato con la riga: è il documento fiscale,
     * e il committente lo vuole **solo** per sé (D2). Si legge esplicitamente.
     *
     * @var list<string>
     */
    protected $hidden = ['xml'];

    protected function casts(): array
    {
        return [
            'anno' => 'integer',
            'numero' => 'integer',
            'data' => 'date',
            'imponibile_cent' => 'integer',
            'bollo_cent' => 'integer',
            'totale_cent' => 'integer',
            'cessionario' => 'array',
            'emessa_il' => 'datetime',
        ];
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(PagamentoIncassato::class, 'pagamento_incassato_id')->withoutGlobalScopes();
    }

    /**
     * 💡 Nessuna palestra nel contesto non vuol dire «nessuna fattura»: una
     * fattura sopravvive alla sua palestra (`tenant_id` → null), e il pannello
     * della piattaforma deve vederle tutte. Lo scope della palestra resta per il
     * suo pannello.
     */
    public function tenantObbligatorio(): bool
    {
        return false;
    }

    public function eEmessa(): bool
    {
        return $this->stato === self::EMESSA;
    }

    /** Il numero come si scrive sulla fattura: `12/TC`, o `12` senza sezionale. */
    public function numeroPerEsteso(): ?string
    {
        if ($this->numero === null) {
            return null;
        }

        return filled($this->sezionale) ? $this->numero.'/'.$this->sezionale : (string) $this->numero;
    }

    /**
     * Il nome del file XML, come lo vuole lo SDI: `IT` + partita IVA del
     * trasmittente + `_` + progressivo.
     */
    public function nomeDelFileXml(): string
    {
        return 'IT'.config('fatture.cedente.partita_iva').'_'.$this->progressivo_invio.'.xml';
    }

    /** Il nome del PDF, leggibile da chi lo scarica. */
    public function nomeDelFilePdf(): string
    {
        return 'fattura-'.str_replace('/', '-', (string) ($this->numeroPerEsteso() ?? 'in-attesa-'.$this->getKey()))
            .'-'.($this->anno ?? now()->year).'.pdf';
    }

    public function scopeEmesse(Builder $query): Builder
    {
        return $query->where('stato', self::EMESSA);
    }

    public function scopeInAttesa(Builder $query): Builder
    {
        return $query->where('stato', self::IN_ATTESA);
    }
}
