<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TrainerInvite;
use App\Services\Billing\Listino;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Il sito pubblico — F9 della Parte B.
 *
 * ⚠️ **È l'unica cosa di tutto il piano che non è né app né pannello**: è
 * marketing, prezzi e conformità. Tre pubblici, tre percorsi (F9.1).
 *
 * 🚨 **Nessun prezzo è scritto dentro una vista**: i numeri arrivano da
 * `Listino` (`config/listino.php`). Un prezzo in un template è un prezzo che un
 * giorno dirà una cosa diversa da quella che il sistema fattura — ed è la classe
 * di errore che si scopre da un cliente arrabbiato, non da un test.
 */
class SitoController extends Controller
{
    /**
     * La home.
     *
     * ⚠️ **Non elenca più i piani dal database.** Dal 16/08 il listino sta su
     * `/prezzi` e segue il modello a posti (`config/listino.php`): la home
     * racconta cosa fa il prodotto, e chi vuole i numeri clicca.
     *
     * 💡 Le tre platee restano — palestre, trainer indipendenti, chi si allena
     * da solo — ma come **sezioni**, non come tre listini affiancati: erano tre
     * colonne di prezzi prima ancora che qualcuno avesse capito cosa fa l'app.
     */
    public function home(Listino $listino): View
    {
        return view('sito.home', [
            // 🚨 Anche la home ha un prezzo da mostrare (il passo 3 di «come
            // funziona»), e anche quello viene da qui: era l'unico numero
            // rimasto scritto a mano dentro un template.
            'formatta' => $this->formattatore(),
            'primoScaglione' => $listino->primoScaglione(),
            'rivenditaSuggerita' => (int) config('listino.rivendita_suggerita_cent'),
        ]);
    }

    /**
     * Da centesimi a «4,99 €».
     *
     * 💡 Una funzione sola per le due pagine: due formattatori diversi
     * scriverebbero lo stesso prezzo in due modi, e la differenza si nota.
     */
    private function formattatore(): \Closure
    {
        return static fn (int $centesimi): string => number_format($centesimi / 100, 2, ',', '.').' €';
    }

    /**
     * La pagina dei prezzi — 16/08/2026.
     *
     * 🚨 **Ogni numero arriva da `Listino`, nessuno e' scritto nella vista.** Un
     * prezzo in un template e' un prezzo che un giorno dira' una cosa diversa
     * da quella che il sistema fattura, e lo si scopre da un cliente arrabbiato
     * invece che da un test.
     */
    public function prezzi(Listino $listino): View
    {
        return view('sito.prezzi', [
            'formatta' => $this->formattatore(),
            'primoScaglione' => $listino->primoScaglione(),
            'prezzoSingolo' => (int) config('listino.singolo_cent'),
            'gettoniMensili' => (int) config('listino.gettoni_mensili'),
            'scaglioni' => $listino->scaglioniLeggibili(),
            'pacchetti' => $listino->pacchetti(),
            'esempio' => $listino->esempio(),
        ]);
    }

    /**
     * Un documento legale, reso dal suo sorgente in Markdown.
     *
     * ── 🚨 Perche' il sorgente sta in `resources/legal/` ──────────────────
     *
     * Perche' **il sito e' la pubblicazione**: il testo che una persona legge
     * deve stare nel repository che viene deployato, non in un repo di
     * documentazione che potrebbe divergere senza che nessuno se ne accorga.
     *
     * ⚠️ Reso a ogni richiesta e non precompilato: sono due file di poche
     * decine di kilobyte, e una cache in piu' e' una cosa in piu' che puo'
     * mostrare la versione vecchia di un documento legale.
     *
     * 🚨 `abort(404)` su un nome sconosciuto, e l'elenco e' **chiuso**: senza,
     * `/documento/../../.env` sarebbe una lettura di file arbitraria.
     */
    public function documento(string $quale): View
    {
        $ammessi = [
            'privacy' => 'informativa-privacy',
            'condizioni' => 'condizioni-uso',
        ];

        abort_unless(isset($ammessi[$quale]), 404);

        $percorso = resource_path("legal/{$ammessi[$quale]}.md");

        abort_unless(is_file($percorso), 404);

        $testo = file_get_contents($percorso) ?: '';

        return view('sito.documento', [
            'titolo' => $this->primoTitolo($testo),
            'corpo' => Str::markdown($testo, ['html_input' => 'escape']),
        ]);
    }

    /**
     * Il titolo del documento, preso dalla sua prima riga.
     *
     * 💡 Cosi' il titolo della pagina e quello del testo non possono divergere:
     * chi cambia l'intestazione del documento cambia anche la scheda del
     * browser, senza doverselo ricordare.
     */
    private function primoTitolo(string $markdown): string
    {
        foreach (preg_split('~\R~', $markdown) ?: [] as $riga) {
            if (str_starts_with($riga, '# ')) {
                return trim(substr($riga, 2));
            }
        }

        return 'Documento';
    }

    /**
     * La pagina d'atterraggio di un invito — F6.2.
     *
     * 🚨 **Non riscatta niente.** Il riscatto ha bisogno di nome, email e
     * password, cioè di un modulo: questa pagina dice soltanto se il link è
     * ancora buono e manda all'app. ⚠️ Un `GET` che consumasse l'invito lo
     * brucerebbe al primo servizio che fa l'anteprima del link — quelli di
     * messaggistica lo aprono da soli per mostrare il riquadro.
     *
     * 💡 E il token **non si mostra** nella pagina: viaggia già nell'URL, e
     * ripeterlo nel corpo lo farebbe finire negli screenshot.
     */
    public function invito(string $token): View
    {
        $invito = TrainerInvite::withoutGlobalScopes()->where('token', $token)->first();

        return view('sito.invito', [
            'valido' => $invito !== null && $invito->eValido(),
            'trainer' => $invito?->trainer?->name,
        ]);
    }
}
