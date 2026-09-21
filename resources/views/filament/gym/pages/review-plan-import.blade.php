{{--
    La revisione di un import — B7.4, 🆕 col documento accanto (F7.2, 23/09/2026).

    📌 Il committente: «deve avere la scheda vicino (visualizzatore pdf o
    immagine) per poterla confrontare con il risultato dell'import».

    💡 Il documento a sinistra resta fermo mentre si scorre il modulo: chi
    confronta guarda una riga del PDF e la cerca a destra. Vedi
    `.tc-confronto` in resources/css/pannello-palestra.css.
--}}
<x-filament-panels::page>
    <div class="tc-confronto">
        @include('filament.gym.componenti.documento-importato', ['import' => $this->import()])

        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </div>
</x-filament-panels::page>
