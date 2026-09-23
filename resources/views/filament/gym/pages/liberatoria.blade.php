{{--
    ✍️ La firma della liberatoria — art. 28 GDPR, 20/09/2026.

    🚨 Il pulsante sta IN FONDO, dopo il testo: un «Accetto» in cima
    lascerebbe firmare senza scorrere, e questo documento dice a una palestra
    cosa può e cosa non può fare con i dati di altre persone.

    ══ ⛔ NIENTE CLASSI DI TAILWIND IN QUESTA VISTA — 24/09/2026 ══════════════

    🚨 **Il CSS di Filament v5 arriva precompilato e non le contiene.** Scritte
    qui non danno **nessun errore** e non fanno **niente** — e questa pagina le
    ha pagate due volte nello stesso giorno:

    | Classe | Cosa doveva fare | Cosa faceva |
    |---|---|---|
    | `prose prose-sm` sul documento | vestire il markdown | niente: tabelle senza bordi, titoli come il testo |
    | `p-6` sui due riquadri | dare il margine interno | niente: `padding: 0px`, testo attaccato ai bordi e fuori dal riquadro |

    📌 Il committente, la prima volta: *«guarda che monnezza la liberatoria, ma
    sei impazzito?? Falla bene»*. E dopo la correzione, guardando **gli altri
    due riquadri**: *«non hanno padding, sono a piena larghezza e il testo
    overflowa»*.

    💡 **La lezione non è «controllare una classe alla volta»**: è che qui non
    se ne scrive nessuna. Tutto quello che serve sta in
    `resources/css/pannello-palestra.css` col prefisso `tc-`, e da lì esce in
    `public/` con `php artisan filament:assets`.

    ⚠️ E i tre blocchi hanno la **stessa larghezza** (760px): il documento stava
    in 760 e i due riquadri a 881. Tre blocchi della stessa pagina larghi in due
    modi diversi si leggono come un errore anche quando nessuno sa dire quale.
--}}
<x-filament-panels::page>
    <div class="tc-riquadro">
        <p>
            Per usare il pannello serve accettare questo documento. Riguarda i dati delle persone
            che seguirai: cosa puoi farci, cosa no, e cosa fare se qualcosa va storto.
        </p>
    </div>

    {{--
        🚨 **`tc-testo-legale`, e il nome conta** — 24/09/2026.

        ⛔ Alla prima scrittura l'avevo chiamata `tc-documento`, **e quel nome
        c'era già**: è il riquadro del PDF importato, che ha `height: 75vh`.
        📏 Su una finestra da 1000 px fa **esattamente 750 px** — il documento,
        alto **2831**, usciva dal proprio riquadro e il pulsante «Ho letto e
        accetto» finiva **sopra le righe da leggere**.

        ⚠️ Su una pagina che chiede di accettare un atto, un pulsante che copre
        il testo è il difetto peggiore che ci possa stare: fa firmare quello che
        nasconde.

        💡 Trovato **misurando** la pagina nel browser, non guardandola: la
        prima diagnosi — `fi-section` dentro la griglia di `.fi-page-content` —
        era sbagliata, e sembrava giustissima. Lo strumento è
        `memory/scripts/browser/misura-liberatoria.mjs`.

        ⛔ Un nome di classe riusato per sbaglio **non dà nessun errore**: prima
        di aggiungerne uno al foglio si cerca se c'è già.

        💡 Le regole ricalcano quelle del **sito** (`.documento` in
        `sito/layout.blade.php`): lo stesso documento esce anche lì, e due
        vestiti diversi fanno dubitare che sia lo stesso documento.
    --}}
    <article class="tc-testo-legale">
        {!! $this->testo() !!}
    </article>

    <div class="tc-riquadro">
        <x-filament::button wire:click="firma" size="lg" wire:loading.attr="disabled">
            Ho letto e accetto
        </x-filament::button>

        <p class="tc-riquadro-nota">
            Restano registrati il tuo nome, la data e l'ora, e la versione del testo che stai accettando.
        </p>
    </div>
</x-filament-panels::page>
