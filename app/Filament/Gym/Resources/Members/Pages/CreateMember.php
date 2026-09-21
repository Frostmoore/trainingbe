<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Pages;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Models\User;
use App\Services\Auth\LaReimpostazioneDellaPassword;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 🚨 Due cose vanno fatte insieme, o l'iscritto nasce a metà:
 *  1. l'utente;
 *  2. il **ruolo** `member`, senza il quale non comparirebbe più nell'elenco
 *     (che filtra per ruolo) — un utente creato e subito invisibile.
 *
 * ══ 🆕 ⛔ E LA PASSWORD NON LA SCEGLIE PIÙ LA PALESTRA — F5.7 ════════════
 *
 * 📌 Il committente: *«⛔ niente identità modificabile, ⛔ niente password»*.
 *
 * 🚨 Fino a ieri questo modulo chiedeva una password e la palestra doveva
 * comunicarla a voce. ⛔ Significava **conoscerla**: poter entrare nell'app al
 * posto di quella persona, leggerne le chat e gli allenamenti.
 *
 * ✅ Adesso l'account nasce con una password **casuale che non legge nessuno**,
 * marcata «mai scelta», e alla persona parte il link per sceglierne una sua.
 *
 * ⚠️ **La strada normale resta l'invito**: lì è la persona a creare il proprio
 * account, e la palestra non tocca niente. Questo modulo serve a chi ha
 * l'elenco su carta e non vuole mandare venti link uno per uno.
 */
class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /*
         * 🚨 **Una password che non conosce nessuno.** ⛔ `users.password` è
         * `NOT NULL`: senza questa riga la creazione morirebbe con un errore di
         * database invece che con un messaggio.
         */
        $data['password'] = Hash::make(Str::random(48));

        // 🆕 19/09/2026: il profilo non sta più sul server (via `profiles`).
        unset($data['profile'], $data['trainers']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $utente */
        $utente = $this->getRecord();

        $utente->assignRole(UserRole::Member->value);

        /*
         * 🚨 **`password_is_set` si scrive con `forceFill`, e non è un vezzo.**
         *
         * ⛔ Non è in `$fillable` — di proposito: è la colonna che dice se una
         * persona ha scelto la propria password, e una richiesta HTTP non deve
         * poterla dichiarare `true` da sola. ⚠️ Passata in `$data` verrebbe
         * **scartata in silenzio**, e l'account nascerebbe marcato «password
         * scelta» pur non avendone nessuna: il pannello direbbe che va tutto
         * bene su una persona che non riesce a entrare.
         */
        $utente->forceFill(['password_is_set' => false])->save();

        /*
         * 🔑 **Il link per scegliersi la password**, subito.
         *
         * ⛔ Senza, l'account nascerebbe inutilizzabile e nessuno se ne
         * accorgerebbe finché la persona non prova a entrare. ⚠️ Oggi la mail
         * finisce nel log (`MAIL_MAILER=log`): per questo il link si mostra
         * **anche a schermo**, da copiare a mano.
         */
        $link = app(LaReimpostazioneDellaPassword::class)->apriPer($utente, auth()->user());

        Notification::make()
            ->title($utente->name.' è iscritto')
            ->body(
                'Gli è partita la mail per scegliersi la password. ⚠️ La posta non è ancora '
                .'collegata: se non la riceve, mandagli tu questo link — '.$link
            )
            ->success()
            ->persistent()
            ->send();
    }
}
