{{--
    I posti e i conti della palestra — 23/09/2026, fasi F5.1 e F5.3.

    🚨 I due tasti d'invito stanno DENTRO le rispettive card, ed è la
    richiesta: si guarda «3 su 10» e si invita lì, senza attraversare il menù.

    ⚠️ Niente query qui dentro: tutto arriva da `getViewData()`.

    ⛔ Niente classi di Tailwind (`grid-cols-3`, `gap-4`…): il CSS di Filament
    v5 è precompilato e non le contiene. Le classi `tc-*` stanno in
    resources/css/pannello-palestra.css — il perché per esteso è in cima a
    quel foglio.
--}}
@php
    /** Scrive un importo in centesimi, o un trattino se non c'è. */
    $euro = static fn (?int $cent): string => $cent === null
        ? '—'
        : number_format($cent / 100, 2, ',', '.') . ' €';

    /** «3 su 10», oppure «3» quando il piano non mette limiti. */
    $suTotale = static fn (array $p): string => $p['totali'] === null
        ? (string) $p['occupati']
        : $p['occupati'] . ' su ' . $p['totali'];

    /** Rosso quando restano pochi posti: è la cosa su cui si agisce. */
    $tono = static function (array $p): string {
        if ($p['totali'] === null || $p['rimasti'] === null) {
            return '';
        }

        return match (true) {
            $p['rimasti'] === 0 => 'tc-pericolo',
            $p['rimasti'] <= 2 => 'tc-attenzione',
            default => '',
        };
    };
@endphp

<x-filament-widgets::widget>
    @if ($palestra === null)
        <x-filament::section>
            <p class="tc-nota">Nessuna palestra nel contesto.</p>
        </x-filament::section>
    @else
        <div class="tc-posti {{ $trainer === null ? 'tc-posti--due' : '' }}">

            {{-- ───────────────── Iscritti ───────────────── --}}
            <x-filament::section>
                <x-slot name="heading">{{ $palestra->ePersonale() ? 'Allievi' : 'Iscritti' }}</x-slot>

                <div class="tc-numero">
                    <span class="tc-numero-grande">{{ $iscritti['occupati'] }}</span>
                    @if ($iscritti['totali'] !== null)
                        <span class="tc-nota {{ $tono($iscritti) }}">su {{ $iscritti['totali'] }} posti</span>
                    @endif
                </div>

                <p class="tc-nota">
                    @if ($iscritti['totali'] === null)
                        {{ $palestra->ePersonale() ? 'Il tuo piano non mette limiti.' : 'Il vostro piano non mette limiti.' }}
                    @elseif ($iscritti['rimasti'] === 0)
                        Posti esauriti: per invitare qualcuno serve un piano più grande.
                    @else
                        Liberi: {{ $iscritti['rimasti'] }}. Contano anche gli inviti già mandati.
                    @endif
                </p>

                <div class="tc-azione">
                    {{ $this->invitaIscrittoAction }}
                </div>
            </x-filament::section>

            {{--
                ───────────────── Trainer ─────────────────

                🚨 Non c'è nello spazio di un trainer indipendente: non può
                assumere nessuno, e «0 su 0 posti» sarebbe un limite inventato
                su una cosa che non può fare. Vedi `getViewData()`.
            --}}
            @if ($trainer !== null)
            <x-filament::section>
                <x-slot name="heading">Trainer</x-slot>

                <div class="tc-numero">
                    <span class="tc-numero-grande">{{ $trainerAttivi }}</span>
                    <span class="tc-nota">attivi</span>
                </div>

                <p class="tc-nota {{ $tono($trainer) }}">
                    Posti: {{ $suTotale($trainer) }}.
                    @if ($trainerAttivi < $trainer['occupati'])
                        {{-- 💡 La differenza è la domanda: un posto pagato che non lavora. --}}
                        {{ $trainer['occupati'] - $trainerAttivi }} fra inviti in sospeso e account spenti.
                    @endif
                </p>

                <div class="tc-azione">
                    {{ $this->invitaTrainerAction }}
                </div>
            </x-filament::section>
            @endif

            {{-- ───────────────── I conti ───────────────── --}}
            <x-filament::section>
                <x-slot name="heading">{{ $palestra->ePersonale() ? 'Per ogni allievo' : 'Per ogni iscritto' }}</x-slot>

                <dl class="tc-conti">
                    <div>
                        <dt>Ti costa</dt>
                        <dd>{{ $euro($costo) }}</dd>
                    </div>
                    <div>
                        <dt>Fai pagare</dt>
                        <dd>{{ $euro($prezzo) }}</dd>
                    </div>
                    <div class="tc-totale">
                        <dt>Ti resta</dt>
                        <dd class="{{ ($margine ?? 0) < 0 ? 'tc-pericolo' : '' }}">{{ $euro($margine) }}</dd>
                    </div>
                </dl>

                <p class="tc-nota tc-azione">
                    @if ($prezzo === null)
                        {{-- ⚠️ D9: il prezzo lo scrive la palestra, noi non lo verifichiamo. --}}
                        Scrivi la tua quota mensile in <strong>Impostazioni</strong> e qui comparirà
                        quanto ti resta.
                    @elseif ($costo === null)
                        Il costo compare quando hai un abbonamento attivo e almeno un
                        {{ $palestra->ePersonale() ? 'allievo' : 'iscritto' }}.
                    @else
                        Il costo è {{ $palestra->ePersonale() ? 'il tuo' : 'il vostro' }} canone diviso
                        {{ $palestra->ePersonale() ? 'gli allievi' : 'gli iscritti' }}. La quota l'hai scritta tu:
                        noi non la vediamo e non la usiamo per niente.
                    @endif
                </p>
            </x-filament::section>

        </div>

        {{-- 🚨 Senza questa riga i due pulsanti non aprono nessuna finestra. --}}
        <x-filament-actions::modals />
    @endif
</x-filament-widgets::widget>
