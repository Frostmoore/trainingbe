{{--
    🔀 «Cambia spazio» — AH.1-quater.

    ══ ⛔ NIENTE CLASSI DI TAILWIND — 24/09/2026 ════════════════════════════

    📌 Il committente: *«cambia spazio ha le cards senza margini»*.

    🚨 La vista usava `grid gap-4 sm:grid-cols-2`, `text-sm`, `font-semibold`,
    `flex items-center justify-between gap-4`: **nel CSS precompilato di
    Filament non ci sono**. Non danno nessun errore e non fanno niente — le
    schede finivano una sotto l'altra, appiccicate.

    ⚠️ È la stessa famiglia di difetti della liberatoria, lo stesso giorno. 💡
    Quello che serve sta in `resources/css/pannello-palestra.css` col prefisso
    `tc-`, e da lì esce con `php artisan filament:assets`.
--}}
<x-filament-panels::page>
    {{-- 💡 Una riga che dice cosa cambia, perché «spazio» da solo non lo dice. --}}
    <p class="tc-cappello">
        Lavori in più di un posto. Qui scegli quale stai gestendo adesso: iscritti,
        schede e piani sono quelli dello spazio scelto.
    </p>

    <div class="tc-griglia-due">
        @foreach ($this->spazi() as $spazio)
            @php($corrente = $spazio->getKey() === $this->spazioCorrente())

            <x-filament::section>
                <div class="tc-riga-spazio">
                    <div>
                        <p class="tc-nome-spazio">
                            {{ $spazio->name }}
                        </p>

                        <p class="tc-che-spazio">
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
