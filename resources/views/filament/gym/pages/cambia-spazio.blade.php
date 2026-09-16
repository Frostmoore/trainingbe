<x-filament-panels::page>
    {{-- 💡 Una riga che dice cosa cambia, perché «spazio» da solo non lo dice. --}}
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Lavori in più di un posto. Qui scegli quale stai gestendo adesso: iscritti,
        schede e piani sono quelli dello spazio scelto.
    </p>

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($this->spazi() as $spazio)
            @php($corrente = $spazio->getKey() === $this->spazioCorrente())

            <x-filament::section>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="font-semibold text-gray-950 dark:text-white">
                            {{ $spazio->name }}
                        </p>

                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $spazio->ePersonale() ? 'Il tuo spazio' : 'Palestra dove alleni' }}
                        </p>
                    </div>

                    @if ($corrente)
                        <x-filament::badge color="success">
                            Sei qui
                        </x-filament::badge>
                    @else
                        <x-filament::button
                            wire:click="scegli({{ $spazio->getKey() }})"
                            wire:loading.attr="disabled"
                        >
                            Vai
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
