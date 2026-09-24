{{--
    🎟️ La card che resta sotto la modale — 24/09/2026.

    🚨 Non è un doppione della modale: una modale chiusa per sbaglio porterebbe
    via l'unico modo di rispondere, e la domanda tornerebbe invisibile — che è
    esattamente il difetto che questo widget è nato per chiudere.

    ⛔ Niente classi di Tailwind: il CSS di Filament v5 è precompilato e non le
    contiene. Quelle `tc-` stanno in resources/css/pannello-palestra.css.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            @if ($voci->count() === 1)
                Un tuo allievo è entrato nella tua palestra
            @else
                {{ $voci->count() }} tuoi allievi sono entrati nella tua palestra
            @endif
        </x-slot>

        <p class="tc-nota">
            Continui a segui{{ $voci->count() === 1 ? 'rlo' : 'rli' }} come prima. Ora però
            {{ $voci->count() === 1 ? 'è anche iscritto' : 'sono anche iscritti' }} alla palestra,
            che copre il {{ $voci->count() === 1 ? 'suo posto' : 'loro posto' }}.
            Per ciascuno: se tieni il posto continui a pagarlo, e quando uscirà dalla palestra
            resterà tuo; se lo liberi non lo paghi più, e quando uscirà non tornerà da te.
        </p>

        @foreach ($voci as $voce)
            <div class="tc-riga-spazio">
                <div>
                    <p class="tc-nome-spazio">{{ $voce->nome }}</p>

                    <p class="tc-che-spazio">
                        È entrato in {{ $voce->palestra ?? 'una palestra dove alleni' }}
                        il {{ \Illuminate\Support\Carbon::parse($voce->sovrapposto_dal)->locale('it')->isoFormat('D MMMM YYYY') }}
                    </p>
                </div>

                <x-filament::button
                    wire:click="mountAction('decidiLoSlot', { member: {{ (int) $voce->member_id }} })"
                    wire:loading.attr="disabled"
                    icon="heroicon-m-ticket"
                >
                    Decidi
                </x-filament::button>
            </div>
        @endforeach
    </x-filament::section>

    {{-- 🚨 Senza questa riga la modale non si apre. --}}
    <x-filament-actions::modals />
</x-filament-widgets::widget>
