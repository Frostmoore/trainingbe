<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatoDellaRichiesta;
use App\Enums\TipoDiRichiesta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ✉️ **Una richiesta arrivata dal modulo di contatto del sito** — 21/09/2026.
 *
 * 📌 Il committente: *«tanto c'è scrivici. Mettici un modulo di contatto così
 * mi scrivono»*.
 *
 * ⚠️ **NON usa `BelongsToTenant`**, come `Plan` e `Tenant`: chi scrive dal sito
 * non è cliente di nessuno — spesso non ha nemmeno un account. Se avesse
 * `tenant_id`, `TenantIsolationTest` pretenderebbe il trait, ed è giusto che lo
 * pretenda: quindi la colonna non c'è proprio.
 *
 * 🚨 **Si legge solo da `/god`.** Una palestra non deve vedere le richieste
 * delle altre palestre — ci stanno dentro numero di iscritti e trattative.
 */
class RichiestaDiContatto extends Model
{
    protected $table = 'richieste_di_contatto';

    protected $fillable = [
        'tipo', 'nome', 'email', 'telefono', 'organizzazione',
        'iscritti', 'trainer', 'messaggio',
    ];

    /**
     * ⚠️ `stato` e `note_interne` **non sono fillable**, ed è voluto: arrivano
     * dal pannello, non dal modulo pubblico. Un `mass assignment` che li
     * includesse permetterebbe a chi scrive dal sito di nascere già «chiusa».
     */
    protected $attributes = [
        'tipo' => 'altro',
        'stato' => 'nuova',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDiRichiesta::class,
            'stato' => StatoDellaRichiesta::class,
            'iscritti' => 'integer',
            'trainer' => 'integer',
            'presa_in_carico_il' => 'datetime',
        ];
    }

    /** Quelle che nessuno ha ancora guardato: il badge del pannello. */
    public function scopeNuove(Builder $query): Builder
    {
        return $query->where('stato', StatoDellaRichiesta::Nuova->value);
    }

    /**
     * Una riga sola che riassume la dimensione, per l'elenco.
     *
     * 💡 «180 iscritti · 6 trainer» dice in tre parole se vale la telefonata di
     * oggi o quella di domani, e sta in una colonna di tabella.
     */
    public function quantoEGrande(): string
    {
        $pezzi = [];

        if ($this->iscritti !== null) {
            $pezzi[] = $this->iscritti.' iscritti';
        }

        if ($this->trainer !== null) {
            $pezzi[] = $this->trainer.' trainer';
        }

        return $pezzi === [] ? '—' : implode(' · ', $pezzi);
    }
}
