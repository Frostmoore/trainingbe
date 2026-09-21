<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un comune italiano — 18/08/2026. **M1.1.** 🆕 **E dal 23/09/2026 anche una
 * città di qualunque altro paese** (fase F6.1).
 *
 * ══ 🌍 DUE FONTI, UNA TABELLA ═══════════════════════════════════════════
 *
 * 📌 Il committente: *«Facciamo stessa tabella ma mondiale. E deve funzionare.»*
 *
 * | | Da dove | Cosa sa in più |
 * |---|---|---|
 * | 🇮🇹 **Italia** (`paese = IT`) | ISTAT, `comuni:importa` | CAP, sigla di provincia, i comuni sotto i 500 abitanti |
 * | 🌍 **Il resto** | GeoNames, `comuni:importa-mondo` | `geonameid`, le divisioni `admin1`/`admin2` |
 *
 * 🚨 **Non si sovrappongono mai**: il dump del mondo l'Italia non ce l'ha. Con
 * le due fonti mescolate Milano comparirebbe due volte nel selettore, con due
 * id — cioè due profili che puntano a due righe che sono lo stesso posto.
 *
 * ⚠️ Il nome della classe resta `Comune` e la tabella `comuni`: rinominarle
 * vorrebbe dire toccare le chiavi esterne di `users` e `profili_pubblici`, e
 * l'API che l'app già installata legge. 💡 Il nome sbagliato costa meno di una
 * migrazione di chiavi esterne su un database vivo.
 *
 * 🚨 **Non ha `BelongsToTenant`, ed e' voluto.** Che Bologna stia in Emilia-
 * Romagna non e' un dato di una palestra: e' un fatto pubblico, uguale per
 * tutte. Un filtro per tenant renderebbe la tabella invisibile a chiunque.
 *
 * ⚠️ **E non ha `SoftDeletes`.** I comuni non si cancellano: si spengono con
 * `attivo = false` — vedi la migrazione. Cancellare un comune fuso vorrebbe dire
 * azzerare la citta' sul profilo di chi lo aveva scelto, cioe' rompere il dato
 * di una persona per un riordino amministrativo che non la riguarda.
 */
class Comune extends Model
{
    /**
     * ⚠️ Esplicita, e serve: Laravel pluralizzerebbe «Comune» in `comunes`.
     * La migrazione crea `comuni`, e senza questa riga il modello non trova
     * niente — con un errore che parla di una tabella che nessuno ha mai scritto.
     * Stessa trappola gia' pagata su `Food` → `foods`.
     */
    protected $table = 'comuni';

    /** 🇮🇹 Il paese delle righe che vengono dall'ISTAT. */
    public const ITALIA = 'IT';

    protected $fillable = [
        'codice', 'nome', 'nome_altro', 'chiave', 'chiave_altro',
        'provincia', 'provincia_nome', 'regione', 'cap', 'popolazione',
        'lat', 'lng', 'attivo',

        // 🆕 🌍 F6.1 — il mondo.
        'paese', 'geonameid', 'admin1', 'admin2', 'nome_locale', 'chiave_locale',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'popolazione' => 'integer',
            'attivo' => 'boolean',
            'geonameid' => 'integer',
        ];
    }

    /**
     * Come si scrive per esteso: «Bologna (BO)» — o, fuori dall'Italia,
     * «Springfield, Illinois (Stati Uniti)».
     *
     * 💡 La provincia ci va **sempre**, anche per i capoluoghi noti. Ci sono otto
     * comuni che si chiamano `Castro`, quattro `Samone`, e senza la sigla un
     * elenco di risultati sarebbe una fila di righe identiche fra cui non si puo'
     * scegliere.
     *
     * ══ 🆕 🌍 FUORI DALL'ITALIA LA FRASE CAMBIA — F6.1 ══════════════════════
     *
     * 🚨 **La sigla fra parentesi non esiste**: GeoNames conosce la divisione
     * amministrativa per **codice** (`02` per la Baviera), e «Monaco (02)» non
     * dice niente a nessuno.
     *
     * ✅ Quindi: il **nome** della regione e il **paese**. ⚠️ E la regione ci
     * va, non è un vezzo: gli Stati Uniti hanno trentaquattro `Springfield`, e
     * «Springfield (Stati Uniti)» ripetuto trentaquattro volte è un selettore in
     * cui non si può scegliere.
     */
    public function esteso(): string
    {
        if ($this->eItaliano()) {
            return "{$this->nome} ({$this->provincia})";
        }

        /*
         * ⚠️ La regione si omette quando **coincide col nome** — Singapore,
         * Monaco, le città-stato: «Singapore, Singapore (Singapore)» è una
         * balbuzie, non un'informazione.
         */
        /*
         * 🗣️ **Il nome italiano, quando c'è** — «Monaco di Baviera» e non
         * «Munich». ⚠️ GeoNames chiama le città col nome **inglese**, e un
         * selettore italiano che scrive «Cologne, North Rhine-Westphalia»
         * sembra rotto anche quando funziona.
         *
         * ⏳ Il giorno che l'app sarà davvero internazionale, qui si sceglierà
         * la lingua di chi guarda: il nome locale è già in `nome_locale`.
         */
        $nome = $this->nome_altro ?? $this->nome;

        /*
         * ⚠️ **La regione si tace se è la città stessa**, in qualunque lingua.
         *
         * ⛔ Misurato: «Mosca, Moscow (Russia)». La regione amministrativa di
         * Mosca si chiama Mosca, e GeoNames la dà in inglese: confrontarla solo
         * col nome italiano la lasciava passare. 💡 Si confronta con **tutti e
         * tre** i nomi — ed è lo stesso caso delle città-stato, Singapore,
         * Monaco, Vienna.
         */
        $regione = $this->provincia_nome;
        $conRegione = $regione !== ''
            && $regione !== $this->paese
            && ! in_array($regione, array_filter([$nome, $this->nome, $this->nome_locale]), true);

        return $nome.($conRegione ? ", {$regione}" : '')." ({$this->regione})";
    }

    /** 🇮🇹 Se viene dall'ISTAT, e quindi ha CAP e sigla di provincia. */
    public function eItaliano(): bool
    {
        return ($this->paese ?? self::ITALIA) === self::ITALIA;
    }

    /**
     * Se ha le coordinate. ⚠️ 57 comuni italiani su 7.896 non ce le hanno:
     * vedi `Vicinanza`. 💡 Le città del mondo ce le hanno tutte — GeoNames è
     * un database di **punti**, e una riga senza coordinate non ci entra.
     */
    public function haCoordinate(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** @param  Builder<Comune>  $query */
    public function scopeAttivi(Builder $query): void
    {
        $query->where('attivo', true);
    }

    /**
     * 🆕 🌍 Solo le città di questo paese — F6.1.
     *
     * 💡 È anche la cosa che tiene veloce la ricerca: filtrata su un paese
     * l'Italia torna a essere ottomila righe, e il «contiene» costa quanto
     * costava prima di diventare mondiali.
     *
     * @param  Builder<Comune>  $query
     */
    public function scopeDelPaese(Builder $query, string $paese): void
    {
        $query->where('paese', strtoupper($paese));
    }
}
