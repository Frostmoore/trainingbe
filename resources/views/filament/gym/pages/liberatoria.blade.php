{{--
    ✍️ La firma della liberatoria — art. 28 GDPR, 20/09/2026.

    🚨 Il pulsante sta IN FONDO, dopo il testo: un «Accetto» in cima
    lascerebbe firmare senza scorrere, e questo documento dice a una palestra
    cosa può e cosa non può fare con i dati di altre persone.
--}}
<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Per usare il pannello serve accettare questo documento. Riguarda i dati delle persone
            che seguirai: cosa puoi farci, cosa no, e cosa fare se qualcosa va storto.
        </p>
    </div>

    <article class="prose prose-sm dark:prose-invert max-w-none">
        {!! $this->testo() !!}
    </article>

    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <x-filament::button wire:click="firma" size="lg" wire:loading.attr="disabled">
            Ho letto e accetto
        </x-filament::button>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Restano registrati il tuo nome, la data e l'ora, e la versione del testo che stai accettando.
        </p>
    </div>
</x-filament-panels::page>
