<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\InvitiDeiTrainer\Pages;

use App\Filament\Gym\Resources\InvitiDeiTrainer\InvitoDelTrainerResource;
use App\Filament\Gym\Support\UnInvitoAllievo;
use App\Models\User;
use App\Services\Tenancy\InvitiDelTrainer;
use Filament\Resources\Pages\ListRecords;

class ListInvitiDeiTrainer extends ListRecords
{
    protected static string $resource = InvitoDelTrainerResource::class;

    public function getTitle(): string
    {
        return 'Inviti ai tuoi allievi';
    }

    public function getSubheading(): ?string
    {
        $utente = auth()->user();

        $rimasti = $utente instanceof User
            ? app(InvitiDelTrainer::class)->postiRimasti($utente)
            : null;

        /*
         * 🚨 **Il numero dei posti sta qui, e non è decorazione.**
         *
         * ⚠️ Un invito valido **tiene un posto** finché non scade
         * (`postiRimasti()` conta anche quelli non ancora usati). 💡 Chi apre
         * questa pagina di solito ci arriva proprio perché i posti sono finiti:
         * dirglielo qui, accanto al tasto «Revoca», è dirgli come rimediare.
         *
         * ⛔ `null` vuol dire **senza limite**, non zero: scrivere «0 posti» a
         * chi non ne ha di limite sarebbe l'errore peggiore possibile su questa
         * riga.
         */
        $posti = $rimasti === null
            ? 'Non hai un limite di allievi.'
            : "Ti restano {$rimasti} posti.";

        return "Un invito valido tiene un posto finché non scade: se non serve più, revocalo. {$posti}";
    }

    /**
     * 💡 **Si invita da qui**, come nella pagina delle palestre: aprire un
     * elenco di inviti e non poterne fare uno è il difetto che ha fatto nascere
     * quella pagina.
     *
     * ══ 🔴 E LA FINESTRA ERA SBAGLIATA — corretta il 24/09/2026 ═══════════════
     *
     * ⛔ Il 23/09 qui avevo scritto una finestra **mia**: chiedeva l'email
     * **prima** e il link non lo mostrava affatto.
     *
     * 📌 Il committente: *«lì dovrebbe apparire subito il link di invito e il
     * campo email se voglio mandare l'email. Deve funzionare come quelli delle
     * palestre. I due tasti di invito sono diversi, non ha senso»*.
     *
     * 🚨 **Aveva ragione due volte.** Quella forma — email prima, link dopo —
     * è esattamente quella che lui stesso aveva fatto cambiare il 23/09 per le
     * palestre (*«non deve essere obbligatorio che metto l'email. Deve subito
     * generare un link di invito»*): l'avevo rifatta uguale a fianco, il giorno
     * dopo, senza accorgermene.
     *
     * 💡 Adesso è `UnInvitoAllievo`, gemello di `UnInvito`: stessa forma, altro
     * servizio. ⚠️ Ed è **una classe condivisa** e non una copia locale, così
     * il tasto è lo stesso anche sull'elenco degli iscritti.
     */
    protected function getHeaderActions(): array
    {
        return [
            UnInvitoAllievo::make(),
        ];
    }
}
