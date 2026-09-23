<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Pages;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Filament\Gym\Support\UnInvito;
use App\Filament\Gym\Support\UnInvitoAllievo;
use App\Models\User;
use App\Services\Tenancy\InvitiDelTrainer;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * L'elenco degli iscritti — 23/09/2026, fase F5.5.
 *
 * ══ ⛔ IL «CODICE DELLA PALESTRA» NON C'È PIÙ ═══════════════════════════
 *
 * 📌 Il committente: *«il tasto codice palestra non ha senso, non esiste più
 * il codice palestra»* · *«Togliamo proprio tutto il discorso dei codici delle
 * palestre»*.
 *
 * 🚨 **Era una chiave che entrava infinite volte.** Chiunque lo conoscesse
 * poteva iscriversi, quante volte voleva: finito in una chat di gruppo o su un
 * volantino, non c'era modo di togliere una persona sola — si poteva solo
 * rigenerarlo, chiudendo fuori anche tutti quelli a cui era stato dato
 * apposta.
 *
 * ✅ Resta **il link d'invito**: monouso, con scadenza, revocabile uno per uno.
 *
 * ✅ Con F11 (24/09/2026) `tenants.join_code` non esiste più: né in tabella,
 * né nell'API, né nell'app.
 */
class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * 🔗 **L'invito prima di «crea»**, ed è l'ordine che dice qual è la
             * strada buona: con l'invito è la persona a fare il proprio
             * account, e la palestra non tocca né la sua email né la sua
             * password.
             *
             * ══ 🐛 E IL TASTO GIUSTO, NON UNO SOLO — 24/09/2026 ══════════════
             *
             * 📌 Il committente, dal pannello di una trainer indipendente:
             * *«Se clicco su "Invita un iscritto" mi dice che solo la palestra
             * può invitare»*.
             *
             * ⛔ **Il tasto si mostrava a tutti** e parlava con
             * `InvitiInPalestra`, che a uno spazio personale risponde *«un
             * tenant personale non è una palestra»*. 🚨 Giustissimo lato
             * servizio — ma il rifiuto arrivava **dopo** aver aperto la
             * finestra, cioè dopo aver fatto credere che si potesse.
             *
             * 💡 Adesso il tasto lo decide **chi sei**: chi amministra una
             * palestra invita nella palestra, chi allena per conto proprio
             * invita un **allievo suo**. ⚠️ Chi è tutti e due li vede tutti e
             * due, e non è un caso da evitare: sono due inviti diversi, verso
             * due posti diversi.
             */
            UnInvito::make(UserRole::Member)
                ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true),

            UnInvitoAllievo::make()
                ->visible(function (): bool {
                    $utente = auth()->user();

                    return $utente instanceof User
                        && app(InvitiDelTrainer::class)->puoAvereAllieviSuoi($utente);
                }),

            /*
             * ⚠️ Solo il gestore crea iscritti a mano. 💡 Serve a chi ha
             * l'elenco su carta e non vuole mandare venti link uno per uno:
             * l'account nasce **senza password**, e alla persona parte il link
             * per sceglierla (vedi `CreateMember`).
             */
            CreateAction::make()
                ->label('Crea a mano')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true),
        ];
    }
}
