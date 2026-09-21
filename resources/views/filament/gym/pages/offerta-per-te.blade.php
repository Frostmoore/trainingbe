{{--
    La pagina che spiega un'offerta — 22/09/2026, fase F2.

    Il committente: «il link deve portare a una pagina che spiega l'offerta, ma
    nel pannello non sul sito».

    Perché dice anche PERCHE' NO
    ----------------------------

    Perché «non valida» fa arrivare tutte le telefonate uguali. Scaduta,
    esaurita e «non è per te» sono tre situazioni diverse, e due su tre si
    risolvono con una risposta sola se si sa quale delle tre è.
--}}
<x-filament-panels::page>

    @php($offerta = $this->offerta())
    @php($motivo = $this->motivo())

    @if ($offerta !== null)
        <x-filament::section>
            <x-slot name="heading">{{ $offerta->nome }}</x-slot>

            <x-slot name="description">
                <strong>{{ ucfirst($offerta->scrittoBene()) }}</strong>
                @if ($offerta->scade_il)
                    · valido fino al {{ $offerta->scade_il->format('d/m/Y') }}
                @else
                    · senza scadenza
                @endif
            </x-slot>

            @if ($offerta->descrizione)
                <p class="fi-ta-text-item fi-size-md">{{ $offerta->descrizione }}</p>
            @endif

            {{-- 🆕 Le offerte col link si attivano da qui (regali subito, sconto al prossimo pagamento). --}}
            @if ($offerta->attivazione === \App\Enums\ComeSiAttiva::Link)
                <div style="margin-top: .75rem;">{{ $this->attivaAction }}</div>
            @endif

            @if ($offerta->haPremiDAcquisto())
            <p class="fi-ta-text-item fi-size-sm" style="margin-top: .75rem; opacity: .75;">
                @if ($offerta->attivazione === \App\Enums\ComeSiAttiva::Link)
                    Attivala: lo sconto si applica da solo al prossimo pagamento
                @else
                    Lo sconto si applica da solo al prossimo pagamento
                @endif
                ({{ mb_strtolower($offerta->genere->label()) }}).
                @if (! $offerta->cumulabile)
                    ⚠️ Non si somma ad altre offerte: se ne hai già una migliore, resta quella.
                @endif
            </p>
            @endif
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Questa offerta non si può usare</x-slot>

            <p class="fi-ta-text-item fi-size-md">{{ $motivo }}</p>

            <p class="fi-ta-text-item fi-size-sm" style="margin-top: .75rem; opacity: .75;">
                Se pensi che sia un errore, scrivici: il codice che hai aperto è
                <strong>{{ $this->codice ?: '—' }}</strong>.
            </p>
        </x-filament::section>
    @endif

    @php($altre = $this->altreAutomatiche())

    @if ($altre->isNotEmpty())
        <x-filament::section collapsible>
            <x-slot name="heading">Quello che hai già</x-slot>

            <x-slot name="description">
                Queste si applicano da sole, senza fare niente.
            </x-slot>

            <ul>
                @foreach ($altre as $promozione)
                    <li>
                        <strong>{{ $promozione->nome }}</strong> — {{ $promozione->scrittoBene() }}
                        @if ($promozione->scade_il)
                            (fino al {{ $promozione->scade_il->format('d/m/Y') }})
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

</x-filament-panels::page>
