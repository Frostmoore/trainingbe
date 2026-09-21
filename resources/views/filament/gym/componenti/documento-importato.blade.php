{{--
    Il documento da cui è nata una scheda — 23/09/2026, fase F7.2.

    📌 Il committente: «deve avere la scheda vicino (visualizzatore pdf o
    immagine) per poterla confrontare con il risultato dell'import».

    💡 Un PDF va in un <iframe>: il lettore è quello del browser, che sa già
    zoomare, cercare e scorrere le pagine — rifarlo sarebbe una libreria in
    più per fare peggio la stessa cosa. Una foto va in un <img>.

    🚨 L'indirizzo è la rotta del pannello, non il disco: il documento sta sul
    disco privato (F7), e si legge solo passando dall'autenticazione.

    ⚠️ Classi `tc-*`, non di Tailwind: vedi resources/css/pannello-palestra.css.
--}}
@php
    /** @var \App\Models\WorkoutPlanImport $import */
    $indirizzo = route('filament.admin.documento-import', ['import' => $import->getKey()]);
    $documento = $import->documento();
@endphp

<div class="tc-confronto-documento">
    <p class="tc-documento-titolo">
        Il documento originale
        @if ($documento !== null)
            <span class="tc-nota">· {{ $documento->file_name }}</span>
        @endif
    </p>

    @if ($documento === null)
        <p class="tc-nota">Il documento non c'è più: è stato cancellato insieme all'import.</p>
    @elseif ($import->eUnaImmagine())
        <img src="{{ $indirizzo }}" alt="Il documento importato" class="tc-documento">
    @else
        <iframe src="{{ $indirizzo }}" title="Il documento importato" class="tc-documento"></iframe>
    @endif

    @if ($documento !== null)
        <p class="tc-nota">
            {{-- 💡 Su uno schermo piccolo il riquadro è stretto: aprirlo da solo aiuta. --}}
            <a href="{{ $indirizzo }}" target="_blank" rel="noopener">Aprilo in una scheda nuova</a>
        </p>
    @endif
</div>
