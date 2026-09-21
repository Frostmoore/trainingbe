<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Pages;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Filament\Gym\Support\UnInvito;
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
 * ⚠️ La colonna `tenants.join_code` esiste ancora e l'API la accetta ancora:
 * toglierla del tutto è la fase **F11**, che tocca anche l'app — e l'ordine di
 * rilascio conta.
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
             */
            UnInvito::make(UserRole::Member),

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
