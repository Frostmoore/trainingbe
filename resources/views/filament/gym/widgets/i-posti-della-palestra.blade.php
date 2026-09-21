{{--
    I posti e i conti della palestra — 23/09/2026, fasi F5.1 e F5.3.

    🚨 I due tasti d'invito stanno DENTRO le rispettive card, ed è la
    richiesta: si guarda «3 su 10» e si invita lì, senza attraversare il menù.

    ⚠️ Niente query qui dentro: tutto arriva da `getViewData()`.
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
    $colore = static function (array $p): string {
        if ($p['totali'] === null || $p['rimasti'] === null) {
            return 'text-gray-500 dark:text-gray-400';
        }

        return match (true) {
            $p['rimasti'] === 0 => 'text-danger-600 dark:text-danger-400',
            $p['rimasti'] <= 2 => 'text-warning-600 dark:text-warning-400',
            default => 'text-gray-500 dark:text-gray-400',
        };
    };
@endphp

<x-filament-widgets::widget>
    @if ($palestra === null)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nessuna palestra nel contesto.
            </p>
        </x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-3">

            {{-- ───────────────── Iscritti ───────────────── --}}
            <x-filament::section>
                <x-slot name="heading">Iscritti</x-slot>

                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-bold tracking-tight">{{ $iscritti['occupati'] }}</span>
                    @if ($iscritti['totali'] !== null)
                        <span class="text-sm {{ $colore($iscritti) }}">
                            su {{ $iscritti['totali'] }} posti
                        </span>
                    @endif
                </div>

                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($iscritti['totali'] === null)
                        Il vostro piano non mette limiti.
                    @elseif ($iscritti['rimasti'] === 0)
                        Posti esauriti: per invitare qualcuno serve un piano più grande.
                    @else
                        Liberi: {{ $iscritti['rimasti'] }}. Contano anche gli inviti già mandati.
                    @endif
                </p>

                <div class="mt-4">
                    {{ $this->invitaIscrittoAction }}
                </div>
            </x-filament::section>

            {{-- ───────────────── Trainer ───────────────── --}}
            <x-filament::section>
                <x-slot name="heading">Trainer</x-slot>

                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-bold tracking-tight">{{ $trainerAttivi }}</span>
                    <span class="text-sm text-gray-500 dark:text-gray-400">attivi</span>
                </div>

                <p class="mt-1 text-xs {{ $colore($trainer) }}">
                    Posti: {{ $suTotale($trainer) }}.
                    @if ($trainerAttivi < $trainer['occupati'])
                        {{-- 💡 La differenza è la domanda: un posto pagato che non lavora. --}}
                        {{ $trainer['occupati'] - $trainerAttivi }} fra inviti in sospeso e account spenti.
                    @endif
                </p>

                <div class="mt-4">
                    {{ $this->invitaTrainerAction }}
                </div>
            </x-filament::section>

            {{-- ───────────────── I conti ───────────────── --}}
            <x-filament::section>
                <x-slot name="heading">Per ogni iscritto</x-slot>

                <dl class="space-y-2 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">Ti costa</dt>
                        <dd class="font-medium">{{ $euro($costo) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">Fai pagare</dt>
                        <dd class="font-medium">{{ $euro($prezzo) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-t border-gray-200 pt-2 dark:border-white/10">
                        <dt class="text-gray-500 dark:text-gray-400">Ti resta</dt>
                        <dd class="text-lg font-bold {{ ($margine ?? 0) < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">
                            {{ $euro($margine) }}
                        </dd>
                    </div>
                </dl>

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    @if ($prezzo === null)
                        {{-- ⚠️ D9: il prezzo lo scrive la palestra, noi non lo verifichiamo. --}}
                        Scrivi la tua quota mensile in <strong>Impostazioni</strong> e qui comparirà
                        quanto ti resta.
                    @elseif ($costo === null)
                        Il costo compare quando hai un abbonamento attivo e almeno un iscritto.
                    @else
                        Il costo è il vostro canone diviso gli iscritti. La quota l'hai scritta tu:
                        noi non la vediamo e non la usiamo per niente.
                    @endif
                </p>
            </x-filament::section>

        </div>

        {{-- 🚨 Senza questa riga i due pulsanti non aprono nessuna finestra. --}}
        <x-filament-actions::modals />
    @endif
</x-filament-widgets::widget>
