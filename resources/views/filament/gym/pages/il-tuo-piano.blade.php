<x-filament-panels::page>
    @php($listino = $this->listino())
    @php($inCorso = $this->inCorso())
    @php($euro = fn (?int $cent) => $cent === null ? '—' : number_format($cent / 100, 2, ',', '.').' €')

    {{--
        🚨 Il pagamento non riuscito PER PRIMO, sopra tutto. ⛔ Prima non si vedeva da
        nessuna parte: si scopriva quando il servizio si spegneva. Stripe riprova per
        qualche giorno — questo è il momento di cambiare carta.
    --}}
    @if ($listino['pagamento_fallito_il'])
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
            <strong>L'ultimo pagamento non è andato a buon fine</strong>
            (dal {{ \Illuminate\Support\Carbon::parse($listino['pagamento_fallito_il'])->format('d/m/Y') }}).
            Stripe riproverà nei prossimi giorni: se la carta è scaduta, aggiornala
            dalla gestione del pagamento qui sotto.
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Adesso</x-slot>

        @if ($inCorso)
            <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $inCorso->plan?->name }}</p>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                @if ($inCorso->ends_at === null)
                    Non scade: si paga a mese chiuso, finché non lo cambi.
                @elseif ($inCorso->rinnova)
                    Si rinnova da solo il {{ $inCorso->ends_at->format('d/m/Y') }}.
                @else
                    Finisce il {{ $inCorso->ends_at->format('d/m/Y') }} e non si rinnova.
                @endif
            </p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Non hai ancora un piano. Scegline uno qui sotto.
            </p>
        @endif

        @if ($this->haUnClienteStripe())
            <div class="mt-4">
                <x-filament::button color="gray" wire:click="gestisci" wire:loading.attr="disabled">
                    Carta, ricevute e disdetta
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($listino['piani'] as $p)
            @php($suo = $p['codice'] === $listino['piano_attuale'])

            <x-filament::section>
                <div class="flex h-full flex-col gap-2">
                    <p class="font-semibold text-gray-950 dark:text-white">{{ $p['nome'] }}</p>

                    @if ($p['a_consumo'])
                        <p class="text-2xl font-bold">{{ $euro($p['prezzo_iscritto_cent']) }}
                            <span class="text-sm font-normal text-gray-500">a iscritto, al mese</span></p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Minimo {{ $p['minimo_iscritti'] }} iscritti. Salvi la carta adesso,
                            e paghi a mese chiuso per quelli che hai avuto.
                        </p>
                    @else
                        <p class="text-2xl font-bold">{{ $euro($p['prezzo_cent']) }}
                            <span class="text-sm font-normal text-gray-500">al mese</span></p>
                        {{--
                            ⚠️ Niente `@if` attaccato alle parole: `trainer@endif` non è
                            una direttiva per Blade, l'`@if` restava aperto e la pagina
                            rispondeva 500. L'ha trovato il test che la apre davvero.
                        --}}
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $p['posti'] === null ? 'Posti senza limite' : $p['posti'].' posti' }}{{ $p['posti_trainer'] !== null ? ', '.$p['posti_trainer'].' trainer' : '' }}
                        </p>
                    @endif

                    <div class="mt-auto pt-2">
                        @if ($suo)
                            <x-filament::badge color="success">Il tuo piano</x-filament::badge>
                        @else
                            {{--
                                ⚠️ Il testo del pulsante dice cosa succede: chi ha già un
                                canone PASSA a un altro (conguaglio), gli altri lo SCELGONO
                                (pagamento). La strada vera la decide comunque il server.
                            --}}
                            <x-filament::button wire:click="scegli('{{ $p['codice'] }}')" wire:loading.attr="disabled">
                                {{ $listino['puo_cambiare'] && ! $p['a_consumo'] ? 'Passa a questo' : 'Scegli' }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Salendo di piano paghi subito la differenza per i giorni che restano. Scendendo,
        la differenza diventa un credito sui mesi dopo — ma non si può scendere sotto i
        posti che stai già usando.
    </p>
</x-filament-panels::page>
