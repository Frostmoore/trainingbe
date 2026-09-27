{{--
    💳 «Devi pagare, ed ecco come» — 27/09/2026.

    ⚠️ Il tono è **di servizio, non di castigo**: chi legge questo è un cliente
    che vogliamo indietro, non qualcuno da rimproverare. ⛔ Un messaggio che
    accusa fa chiudere la scheda; uno che spiega e offre il tasto fa pagare.

    🔑 Dice tre cose, in quest'ordine: **cosa succede adesso**, **cosa NON si
    perde**, e **come si rimedia**. La seconda è quella che toglie la paura, ed
    è la ragione per cui questo riquadro non è rosso.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div class="flex items-start gap-3">
                <x-filament::icon
                    icon="heroicon-o-credit-card"
                    class="mt-0.5 h-6 w-6 flex-shrink-0 text-amber-500"
                />

                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ $this->getNome() }}: il servizio è sospeso
                    </h2>

                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{-- 🔑 Cosa NON si perde, subito: è quello che serve sapere. --}}
                        I tuoi dati e quelli dei tuoi iscritti sono al sicuro e restano dove sono.
                        Finché il pagamento non riprende, però, le funzioni che dipendono
                        dall'abbonamento — l'AI, le schede condivise e i contenuti della palestra —
                        restano spente.
                    </p>
                </div>
            </div>

            <x-filament::button
                tag="a"
                :href="\App\Filament\Gym\Pages\IlTuoPiano::getUrl()"
                icon="heroicon-m-arrow-right-circle"
                class="flex-shrink-0"
            >
                Riattiva il servizio
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
