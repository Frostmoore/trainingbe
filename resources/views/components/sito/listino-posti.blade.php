{{--
    Il listino a posti di una platea — palestre o trainer, 18/08/2026.

    ── 🚨 Perché un componente e non due blocchi copiati ──────────────────────

    Perché palestre e trainer comprano **la stessa cosa** con numeri diversi:
    pacchetti di posti a prezzo fisso, più un piano a consumo. ⚠️ Due blocchi
    copiati divergono al primo ritocco, e la divergenza si vede solo mettendo le
    due schede una accanto all'altra — cioè mai, perché stanno in due ante
    diverse del carosello.

    ── 📌 Cosa ha sostituito ──────────────────────────────────────────────────

    Una **scala progressiva** sul numero di posti, come le aliquote. Era corretta
    e illeggibile: per sapere quanto si spende bisognava fare una somma a pezzi.
    Un listino che richiede un calcolo per essere capito è un listino che nessuno
    confronta.
--}}
@props([
    'occhiello',
    'titolo',
    'sottotitolo',
    'unita',
    'unitaSingolare',
    'piani',
    'formatta',

    /*
     * 🆕 13/09/2026 — `null` per chi non ha un piano a consumo.
     *
     * ⚠️ **Solo le palestre ce l'hanno.** Un valore di ripiego avrebbe
     * scritto «0,00 a allievo» sotto il listino dei trainer.
     */
    'aConsumo' => null,
])

<div class="intestazione-sezione" style="text-align: left;">
    <div class="occhiello">{{ $occhiello }}</div>
    <h2>{{ $titolo }}</h2>
    <p class="guida" style="margin-left: 0;">{{ $sottotitolo }}</p>
</div>

<div class="griglia-pacchetti">
    @foreach ($piani as $indice => $p)
        {{-- 💡 Il secondo è quello evidenziato: è il taglio che sceglie la
             maggior parte, e un listino senza una scelta suggerita costringe
             chi legge a decidere da zero. --}}
        <div class="scheda scheda-prezzo{{ $indice === 1 ? ' scelta' : '' }}">
            @if ($indice === 1)
                <span class="etichetta">{{ $p['nota'] }}</span>
            @endif

            <h3>{{ $p['slot'] }} {{ $unita }}</h3>

            {{-- 🆕 **Il secondo tetto** — 13/09/2026. Un piano da palestra ne
                 ha due, e valgono tutti e due: scriverne uno solo sarebbe la
                 metà del contratto. ⚠️ I piani da trainer non ce l'hanno. --}}
            @if ($p['trainer'] !== null)
                <p class="nota">e {{ $p['trainer'] }} trainer</p>
            @endif

            <div class="prezzo">{{ $formatta($p['prezzo']) }} <small>/mese</small></div>

            {{-- 🚨 Il prezzo per slot è il numero che vende, ed è quello che
                 nessuno ha voglia di calcolarsi: «199,90 per 50» non dice
                 niente, «4,00 a {{ $unitaSingolare }}» dice tutto. --}}
            <p class="per-posto">{{ $formatta($p['perSlot']) }} a {{ $unitaSingolare }}</p>

            @if ($indice !== 1)
                <p class="nota">{{ $p['nota'] }}</p>
            @endif

            <a href="/registrati" class="bottone {{ $indice === 1 ? 'bottone-pieno' : 'bottone-vuoto' }}" style="margin-top: auto;">
                Iscriviti ora
            </a>
        </div>
    @endforeach
</div>

{{-- ⚠️ Il piano a consumo costa **più** del pacchetto più piccolo, ed è
     giusto: è il prezzo della flessibilità. Senza quella differenza il
     pacchetto non converrebbe a nessuno e sarebbe lì per finta. --}}
@if ($aConsumo !== null)
    <div class="scheda tenue" style="margin-top: var(--sp-3);">
        <div style="display: flex; gap: var(--sp-3); flex-wrap: wrap; align-items: center;">
            <div style="flex: 1; min-width: 220px;">
                <h3>Oppure a consumo</h3>
                <p class="nota">
                    Nessun piano e nessun tetto: paghi per ogni {{ $unitaSingolare }} a cui
                    regali l'abbonamento, minimo {{ $aConsumo['minimo_iscritti'] }}.
                    I trainer sono <strong>illimitati</strong>.
                </p>
            </div>
            <div style="text-align: right;">
                <div class="prezzo" style="font-size: 30px;">{{ $formatta($aConsumo['prezzo_iscritto_cent']) }}</div>
                <div class="nota">a {{ $unitaSingolare }} al mese</div>
            </div>
        </div>
    </div>
@endif
