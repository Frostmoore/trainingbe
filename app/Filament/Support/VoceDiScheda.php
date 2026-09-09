<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Str;

/**
 * Una riga di scheda: l'etichetta a sinistra, il valore a destra.
 *
 * == PERCHE' ESISTE ==
 *
 * Il committente, guardando le schede: "accanto a ogni valore ci dovrebbe
 * essere il nome della ex colonna, sennò non si capisce cos'è il valore".
 *
 * Aveva ragione, e il difetto era mio: sapevo che dentro uno `Stack` le
 * intestazioni di colonna spariscono, e avevo messo un prefisso SOLO sui valori
 * che "non si spiegano da sé". Ma "si spiega da sé" era un giudizio mio, preso
 * conoscendo già i dati: chi apre la pagina per la prima volta non ha quel
 * contesto, e legge una targhetta grigia senza sapere se sia un piano, uno
 * stato o un'origine.
 *
 * == PERCHE' UN AIUTANTE E NON UN PREFISSO SU OGNI COLONNA ==
 *
 * Il prefisso entra DENTRO la targhetta dei `badge`: "Stato: pubblicata" in un
 * pallino colorato e' lungo e brutto, e su una colonna con piu' valori
 * (`separator`) il prefisso si ripete su ognuno.
 *
 * Qui l'etichetta e' un elemento suo, fuori dal valore: resta grigia e piccola
 * qualunque cosa il valore sia, e le quattro tabelle si allineano fra loro
 * perche' passano tutte da questa funzione.
 *
 * == LA COLONNA FINTA, E PERCHE' E' INNOCUA ==
 *
 * L'etichetta e' una `TextColumn` con uno stato fisso, quindi finisce in
 * `Table::getColumns()` insieme a quelle vere. Non da' fastidio perche' non e'
 * ne' `sortable()` ne' `toggleable()`: la tendina "ordina" elenca solo le
 * ordinabili e il menu "colonne" solo le commutabili.
 *
 * ATTENZIONE: il nome della colonna finta viene dall'etichetta, quindi due
 * etichette uguali nella stessa tabella si calpesterebbero. Non succede oggi --
 * due righe con lo stesso nome non avrebbero senso -- ma se un giorno servisse,
 * si passa un nome esplicito.
 */
final class VoceDiScheda
{
    public static function make(string $etichetta, Column $valore): Split
    {
        return Split::make([
            TextColumn::make('etichetta_'.Str::slug($etichetta, '_'))
                ->state($etichetta)
                ->size('xs')
                ->color('gray')
                // L'etichetta prende la larghezza che le serve e non una di
                // piu': senza, si mangerebbe meta' riga e il valore andrebbe
                // a capo su schede strette.
                ->grow(false),

            $valore,
        ])
            // Sotto i 640px l'etichetta va SOPRA il valore invece che accanto:
            // su uno schermo stretto due colonne dentro una scheda gia' stretta
            // spezzano le parole a meta'.
            ->from('sm');
    }
}
