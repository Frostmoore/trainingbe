{{--
    La chat dello staff.

    `wire:poll` e non un socket: nel pannello qualche secondo di ritardo non
    cambia niente, e legare una pagina Filament a Reverb significherebbe che la
    chat dello staff smette di funzionare quando il processo WebSocket ha un
    problema. Il socket serve all'app, dove conta.
--}}
<x-filament-panels::page>
    <div wire:poll.10s class="grid gap-4 md:grid-cols-3">

        {{-- Elenco delle conversazioni --}}
        <div class="md:col-span-1">
            <x-filament::section heading="Conversazioni">
                @forelse ($this->conversations as $conversazione)
                    @php
                        $altro = $conversazione->otherParty(auth()->user());
                        $nonLetti = $conversazione->unreadFor(auth()->user());
                    @endphp

                    <button type="button"
                            wire:click="apri({{ $conversazione->id }})"
                            @class([
                                'flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm',
                                'bg-primary-50 dark:bg-primary-950' => $conversationId === $conversazione->id,
                                'hover:bg-gray-50 dark:hover:bg-gray-800' => $conversationId !== $conversazione->id,
                            ])>
                        <span class="truncate font-medium">{{ $altro?->name ?? '—' }}</span>

                        @if ($nonLetti > 0)
                            <x-filament::badge color="danger">{{ $nonLetti }}</x-filament::badge>
                        @endif
                    </button>
                @empty
                    {{-- Al titolare che non partecipa a nessun filo si dice *perché*
                         non vede niente: altrimenti sembra un guasto, e il primo
                         pensiero è chiedere di «sistemarlo». --}}
                    <p class="text-sm text-gray-500">
                        {{ $this->spiegazioneVuoto }}
                    </p>
                @endforelse
            </x-filament::section>
        </div>

        {{-- Thread --}}
        <div class="md:col-span-2">
            @if ($this->active)
                <x-filament::section :heading="$this->active->otherParty(auth()->user())?->name ?? 'Conversazione'">
                    <div class="mb-4 flex max-h-[28rem] flex-col gap-2 overflow-y-auto">
                        @foreach ($this->active->messages as $messaggio)
                            @php $mio = $messaggio->sender_id === auth()->id(); @endphp

                            <div @class(['flex', 'justify-end' => $mio])>
                                <div @class([
                                    'max-w-[80%] rounded-2xl px-3 py-2 text-sm',
                                    'bg-primary-600 text-white' => $mio,
                                    'bg-gray-100 dark:bg-gray-800' => ! $mio,
                                ])>
                                    <div>{{ $messaggio->body }}</div>
                                    <div class="mt-1 text-[11px] opacity-70">
                                        {{ $messaggio->created_at?->format('d/m H:i') }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <form wire:submit="invia" class="flex items-end gap-2">
                        <div class="flex-1">
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="text"
                                    wire:model="body"
                                    placeholder="Scrivi un messaggio…"
                                    maxlength="4000"
                                />
                            </x-filament::input.wrapper>
                        </div>

                        <x-filament::button type="submit">Invia</x-filament::button>
                    </form>
                </x-filament::section>
            @else
                <x-filament::section>
                    <p class="text-sm text-gray-500">Scegli una conversazione per leggerla.</p>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
