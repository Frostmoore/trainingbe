<x-filament-panels::page>
    @php($piano = $this::pianoCorrente())
    @php($iscritti = $this->iscrittiOra())
    @php($stima = $this->stimaDelMese())
    @php($minimo = $this->minimo())

    {{--
        💡 La cifra grande per prima, e subito sotto da dove viene. ⛔ Mettere
        prima la spiegazione vorrebbe dire far cercare il numero che tutti sono
        venuti a vedere.
    --}}
    <x-filament::section>
        <x-slot name="heading">Se il mese finisse adesso</x-slot>

        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <span class="text-4xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ $stima === null ? '—' : number_format($stima / 100, 2, ',', '.') }} €
            </span>

            <span class="text-sm text-gray-500 dark:text-gray-400">
                per {{ $this->periodoCorrente() }}
            </span>
        </div>

        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
            @if ($minimo !== null)
                {{-- 🚨 La domanda che arriva sempre, risposta prima che la facciano. --}}
                Hai <strong>{{ $iscritti }}</strong>
                {{ $iscritti === 1 ? 'iscritto' : 'iscritti' }}, ma il tuo piano ha un
                minimo di <strong>{{ $minimo }}</strong>: paghi
                {{ $minimo }} × {{ number_format(($piano?->prezzo_iscritto_cent ?? 0) / 100, 2, ',', '.') }} €.
                Dal {{ $minimo + 1 }}° iscritto in poi paghi solo quelli che hai.
            @else
                <strong>{{ $iscritti }}</strong>
                {{ $iscritti === 1 ? 'iscritto' : 'iscritti' }}
                × {{ number_format(($piano?->prezzo_iscritto_cent ?? 0) / 100, 2, ',', '.') }} €.
            @endif
        </p>

        {{--
            ⚠️ Si dice che puo' ancora cambiare. Una cifra data per definitiva
            che poi cambia e' peggio di nessuna cifra.
        --}}
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Il conto si chiude il primo del mese prossimo: fino ad allora questa cifra
            cambia insieme agli iscritti. Chi è stato disattivato o rimosso non si conta.
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">I mesi già chiusi</x-slot>

        @php($storico = $this->storico())

        @if ($storico->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Non c'è ancora nessun mese chiuso.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Mese</th>
                            <th class="py-2 pr-4 font-medium">Iscritti</th>
                            <th class="py-2 pr-4 font-medium">Fatturati</th>
                            <th class="py-2 pr-4 font-medium">Importo</th>
                            <th class="py-2 font-medium">Stato</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($storico as $riga)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-950 dark:text-white">
                                    {{ $riga->periodo }}
                                </td>

                                <td class="py-2 pr-4">{{ $riga->iscritti_contati }}</td>

                                <td class="py-2 pr-4">
                                    {{ $riga->iscritti_fatturati }}
                                    @if ($riga->haPagatoIlMinimo())
                                        <span class="text-xs text-gray-500 dark:text-gray-400">(minimo)</span>
                                    @endif
                                </td>

                                <td class="py-2 pr-4">
                                    {{ number_format($riga->importoEuro(), 2, ',', '.') }} €
                                </td>

                                <td class="py-2">
                                    {{--
                                        🆕 `emesso` — §9.4: la fattura è su Stripe ma non ancora
                                        pagata. ⛔ Prima finiva nel ramo di serie e si leggeva
                                        «Da emettere», su una fattura già emessa.
                                    --}}
                                    @php($colore = match ($riga->stato) {
                                        \App\Models\AddebitoMensile::INCASSATO => 'success',
                                        \App\Models\AddebitoMensile::EMESSO => 'warning',
                                        \App\Models\AddebitoMensile::FALLITO => 'danger',
                                        default => 'gray',
                                    })

                                    <x-filament::badge :color="$colore">
                                        {{ match ($riga->stato) {
                                            \App\Models\AddebitoMensile::INCASSATO => 'Pagato',
                                            \App\Models\AddebitoMensile::EMESSO => 'In pagamento',
                                            \App\Models\AddebitoMensile::FALLITO => 'Non riuscito',
                                            \App\Models\AddebitoMensile::SENZA_CLIENTE => 'In attesa',
                                            default => 'Da emettere',
                                        } }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
