<?php

declare(strict_types=1);

namespace App\Services\Fatturazione;

use App\Models\Fattura;
use App\Support\Fatturazione\TestoLatino;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * 🧾 L'XML di una fattura, formato **FatturaPA FPR12** (fattura ordinaria fra
 * privati) — fase F8, 23/09/2026.
 *
 * ══ 📐 QUALE FORMATO, E COME SI SA CHE È GIUSTO ══════════════════════════
 *
 * 📌 Il committente: *«cerca su internet il formato corretto per l'sdi»*. Lo
 * schema in vigore dal 1° aprile 2025 è **`Schema_VFPR12_v1.2.3.xsd`** (specifiche
 * tecniche 1.9), da `fatturapa.gov.it`. Namespace e attributo `versione="FPR12"`
 * sono gli stessi della 1.2.2.
 *
 * 🧪 **Ogni fattura dei test si valida contro quello schema, scaricato
 * com'è** in `tests/fixtures/fatturapa/` (`LeFattureTest`). ⛔ Non ci si fida di
 * «sembra giusto»: lo SDI scarta il file intero per un elemento fuori ordine.
 *
 * ══ 🧮 IL REGIME FORFETTARIO, DENTRO L'XML ══════════════════════════════
 *
 * | Dove | Cosa | Perché |
 * |---|---|---|
 * | `RegimeFiscale` | `RF19` | forfettario |
 * | `AliquotaIVA` | `0.00` | niente IVA |
 * | `Natura` | `N2.2` | «non soggette – altri casi»: obbligatoria quando l'aliquota è zero |
 * | `RiferimentoNormativo` | art. 1 c. 54-89 L. 190/2014 | il perché dell'esenzione |
 * | `DatiBollo` | `BolloVirtuale = SI`, `2.00` | sopra 77,47 € |
 *
 * ⚠️ **Il bollo non entra in `ImportoTotaleDocumento`**: lo paghiamo noi (vedi
 * `config/fatture.php`), e il totale è quello che il cliente ha pagato.
 */
class FatturaXml
{
    private const NS = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';

    private DOMDocument $doc;

    /**
     * L'XML di una fattura **emessa**.
     *
     * @throws RuntimeException se la fattura non ha ancora numero o cliente
     */
    public function genera(Fattura $fattura): string
    {
        if ($fattura->numero === null || $fattura->data === null || $fattura->cessionario === null) {
            throw new RuntimeException('Una fattura senza numero, data o cliente non ha un XML.');
        }

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        $radice = $this->doc->createElementNS(self::NS, 'p:FatturaElettronica');
        $radice->setAttribute('versione', 'FPR12');
        $radice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $radice->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $this->doc->appendChild($radice);

        $this->intestazione($radice, $fattura);
        $this->corpo($radice, $fattura);

        return (string) $this->doc->saveXML();
    }

    // ───────────────────────── l'intestazione ─────────────────────────

    private function intestazione(DOMElement $radice, Fattura $f): void
    {
        $cedente = (array) config('fatture.cedente');
        $cliente = (array) $f->cessionario;
        $estero = ($cliente['nazione'] ?? 'IT') !== 'IT';

        $h = $this->figlio($radice, 'FatturaElettronicaHeader');

        // ── DatiTrasmissione ──
        $t = $this->figlio($h, 'DatiTrasmissione');
        $id = $this->figlio($t, 'IdTrasmittente');
        $this->testo($id, 'IdPaese', 'IT');
        // 💡 Il trasmittente è il titolare stesso: il suo codice fiscale.
        $this->testo($id, 'IdCodice', TestoLatino::codice($cedente['codice_fiscale']));
        $this->testo($t, 'ProgressivoInvio', (string) $f->progressivo_invio);
        $this->testo($t, 'FormatoTrasmissione', 'FPR12');

        /*
         * 🚨 Il codice destinatario, nei tre casi:
         * - un cliente **estero** → `XXXXXXX` (regola SDI);
         * - un codice SDI vero → quello;
         * - altrimenti `0000000`, e la fattura va alla PEC.
         */
        $sdi = TestoLatino::codice($cliente['codice_sdi'] ?? '');
        $destinatario = $estero ? 'XXXXXXX' : (strlen($sdi) === 7 ? $sdi : '0000000');
        $this->testo($t, 'CodiceDestinatario', $destinatario);

        if (! $estero && $destinatario === '0000000' && filled($cliente['pec'] ?? null)) {
            $this->testo($t, 'PECDestinatario', trim((string) $cliente['pec']));
        }

        // ── CedentePrestatore: chi emette ──
        $cp = $this->figlio($h, 'CedentePrestatore');
        $da = $this->figlio($cp, 'DatiAnagrafici');
        $iva = $this->figlio($da, 'IdFiscaleIVA');
        $this->testo($iva, 'IdPaese', 'IT');
        $this->testo($iva, 'IdCodice', TestoLatino::codice($cedente['partita_iva']));
        $this->testo($da, 'CodiceFiscale', TestoLatino::codice($cedente['codice_fiscale']));
        $ana = $this->figlio($da, 'Anagrafica');
        $this->testo($ana, 'Denominazione', TestoLatino::di($cedente['denominazione'], 80));
        $this->testo($da, 'RegimeFiscale', (string) $cedente['regime']);

        $this->sede($cp, [
            'indirizzo' => $cedente['indirizzo'],
            'numero_civico' => $cedente['numero_civico'] ?? null,
            'cap' => $cedente['cap'],
            'comune' => $cedente['comune'],
            'provincia' => $cedente['provincia'],
            'nazione' => 'IT',
        ]);

        if (filled($cedente['email'] ?? null)) {
            $contatti = $this->figlio($cp, 'Contatti');
            $this->testo($contatti, 'Email', (string) $cedente['email']);
        }

        // ── CessionarioCommittente: la palestra ──
        $cc = $this->figlio($h, 'CessionarioCommittente');
        $dc = $this->figlio($cc, 'DatiAnagrafici');

        $piva = TestoLatino::codice($cliente['partita_iva'] ?? '');
        $cf = TestoLatino::codice($cliente['codice_fiscale'] ?? '');

        if ($piva !== '') {
            $ivaCliente = $this->figlio($dc, 'IdFiscaleIVA');
            $this->testo($ivaCliente, 'IdPaese', (string) ($cliente['nazione'] ?? 'IT'));
            $this->testo($ivaCliente, 'IdCodice', $piva);
        }

        // ⚠️ Il codice fiscale solo se è nella forma italiana (11 o 16).
        if (preg_match('/^[A-Z0-9]{11,16}$/', $cf) === 1 && ! $estero) {
            $this->testo($dc, 'CodiceFiscale', $cf);
        }

        $anaCliente = $this->figlio($dc, 'Anagrafica');
        $this->testo($anaCliente, 'Denominazione', TestoLatino::di($cliente['ragione_sociale'] ?? '', 80));

        $this->sede($cc, $cliente);
    }

    /**
     * La sede, per cedente e cliente.
     *
     * ⚠️ Per un indirizzo estero lo SDI vuole `CAP = 00000` e niente provincia:
     * lo schema accetta solo cinque cifre, e le province sono italiane.
     *
     * @param  array<string, mixed>  $s
     */
    private function sede(DOMElement $padre, array $s): void
    {
        $nazione = (string) ($s['nazione'] ?? 'IT');
        $estero = $nazione !== 'IT';

        $sede = $this->figlio($padre, 'Sede');
        $this->testo($sede, 'Indirizzo', TestoLatino::di($s['indirizzo'] ?? '', 60));

        $civico = TestoLatino::di($s['numero_civico'] ?? null, 8);

        if ($civico !== '' && preg_match('/^[\x20-\x7E]{1,8}$/', $civico) === 1) {
            $this->testo($sede, 'NumeroCivico', $civico);
        }

        $cap = preg_replace('/\D/', '', (string) ($s['cap'] ?? ''));
        $this->testo($sede, 'CAP', ! $estero && strlen((string) $cap) === 5 ? (string) $cap : '00000');
        $this->testo($sede, 'Comune', TestoLatino::di($s['comune'] ?? '', 60));

        $provincia = TestoLatino::codice($s['provincia'] ?? '');

        if (! $estero && preg_match('/^[A-Z]{2}$/', $provincia) === 1) {
            $this->testo($sede, 'Provincia', $provincia);
        }

        $this->testo($sede, 'Nazione', $nazione);
    }

    // ───────────────────────── il corpo ─────────────────────────

    private function corpo(DOMElement $radice, Fattura $f): void
    {
        $b = $this->figlio($radice, 'FatturaElettronicaBody');

        $g = $this->figlio($b, 'DatiGenerali');
        $d = $this->figlio($g, 'DatiGeneraliDocumento');
        $this->testo($d, 'TipoDocumento', 'TD01');
        $this->testo($d, 'Divisa', 'EUR');
        $this->testo($d, 'Data', $f->data->toDateString());
        $this->testo($d, 'Numero', (string) $f->numeroPerEsteso());

        if ($f->bollo_cent > 0) {
            $bollo = $this->figlio($d, 'DatiBollo');
            $this->testo($bollo, 'BolloVirtuale', 'SI');
            $this->testo($bollo, 'ImportoBollo', self::euro($f->bollo_cent));
        }

        $this->testo($d, 'ImportoTotaleDocumento', self::euro($f->totale_cent));

        // ── Una riga sola: un incasso è una cosa sola ──
        $bs = $this->figlio($b, 'DatiBeniServizi');
        $riga = $this->figlio($bs, 'DettaglioLinee');
        $this->testo($riga, 'NumeroLinea', '1');
        $this->testo($riga, 'Descrizione', TestoLatino::di($f->descrizione, 1000));
        $this->testo($riga, 'Quantita', '1.00');
        $this->testo($riga, 'PrezzoUnitario', self::euro($f->imponibile_cent));
        $this->testo($riga, 'PrezzoTotale', self::euro($f->imponibile_cent));
        $this->testo($riga, 'AliquotaIVA', '0.00');
        $this->testo($riga, 'Natura', (string) config('fatture.natura'));

        $r = $this->figlio($bs, 'DatiRiepilogo');
        $this->testo($r, 'AliquotaIVA', '0.00');
        $this->testo($r, 'Natura', (string) config('fatture.natura'));
        $this->testo($r, 'ImponibileImporto', self::euro($f->imponibile_cent));
        $this->testo($r, 'Imposta', '0.00');
        $this->testo($r, 'RiferimentoNormativo', TestoLatino::di((string) config('fatture.riferimento_normativo'), 100));

        // ── Già pagata, con carta, su Stripe ──
        $p = $this->figlio($b, 'DatiPagamento');
        $this->testo($p, 'CondizioniPagamento', 'TP02'); // pagamento completo
        $dp = $this->figlio($p, 'DettaglioPagamento');
        $this->testo($dp, 'ModalitaPagamento', 'MP08'); // carta di pagamento
        $this->testo($dp, 'ImportoPagamento', self::euro($f->totale_cent));
    }

    // ───────────────────────── aiuti ─────────────────────────

    /**
     * ⚠️ Gli elementi figli **senza namespace**: nella FatturaPA solo la radice
     * ha il prefisso `p:`, tutto il resto è «non qualificato» (come negli esempi
     * ufficiali). Con il namespace sui figli lo schema li rifiuta.
     */
    private function figlio(DOMElement $padre, string $nome): DOMElement
    {
        $el = $this->doc->createElement($nome);
        $padre->appendChild($el);

        return $el;
    }

    private function testo(DOMElement $padre, string $nome, string $valore): void
    {
        $el = $this->doc->createElement($nome);
        $el->appendChild($this->doc->createTextNode($valore));
        $padre->appendChild($el);
    }

    /** Centesimi → `123.45`, col punto e due decimali, come vuole lo schema. */
    public static function euro(int $cent): string
    {
        return number_format($cent / 100, 2, '.', '');
    }
}
