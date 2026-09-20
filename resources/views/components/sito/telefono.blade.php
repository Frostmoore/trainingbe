{{--
    Una schermata dell'app dentro il telaio di un telefono — 20/09/2026.

    ── 🚨 Perché non è la figura e basta ──────────────────────────────────────

    Perché una cattura dell'app senza telaio, in mezzo a una pagina, si legge
    come **un'immagine qualunque**: non si capisce che è un telefono e non si
    capisce che quella è l'app. Tutti e tre i siti guardati (Hevy, Yazio,
    Trainerize) mostrano le schermate incorniciate, ed è il motivo.

    💡 **E serve soprattutto quando la schermata NON c'è.** Le catture vanno
    fatte dall'emulatore e non ci sono ancora: col telaio, il riempimento vuoto
    della figura si legge come un mockup; senza, come un rettangolo grigio in
    mezzo alla pagina.

    ⚠️ **Nei commenti Blade non si scrivono tag di componente** (né direttive):
    vengono compilati lo stesso, perché il compilatore li espande prima di
    togliere i commenti.

    ⚠️ Le misure restano quelle di `ImmaginiDelSito::ATTESE` (540 × 1200, cioè
    un 1080 × 2400 dimezzato): è il rapporto che riserva lo spazio prima che
    l'immagine arrivi, e sbagliarlo fa saltare la pagina mentre si carica.

    Parametri:
      nome     — la chiave in `ImmaginiDelSito::ATTESE`
      alt      — 🚨 obbligatorio: è quello che sente chi usa uno screen reader,
                 e va scritto **descrivendo la schermata**, non dicendo
                 «schermata dell'app»
      priorita — `true` solo per quella visibile senza scorrere
--}}
@props([
    'nome',
    'alt',
    'priorita' => false,
])

<div class="telefono">
    <x-sito.figura :nome="$nome" :alt="$alt" :priorita="$priorita" />
</div>
