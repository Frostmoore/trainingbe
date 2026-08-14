<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Pages;

use App\Enums\AuditAction;
use App\Filament\God\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var bool Se questo salvataggio ha disattivato l'utente. */
    private bool $disattivato = false;

    /**
     * I due tetti com'erano **prima** di questo salvataggio.
     *
     * Servono a scrivere nel registro «da 400 a illimitato» invece del solo
     * valore nuovo: un registro che dice dove si e' arrivati senza dire da dove
     * si veniva non permette di capire se sia stata una concessione o un
     * ritocco.
     *
     * @var array{chiamate: ?int, foto: ?int}|null
     */
    private ?array $quotaPrima = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->after(function (User $record): void {
                    app(AuditLogger::class)->log(
                        AuditAction::UserDeleted,
                        $record,
                        ['email' => $record->email],
                        tenant: $record->tenant_id,
                    );
                }),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * La disattivazione va tracciata, ma si riconosce solo confrontando prima e
     * dopo: `is_active` e' una casella come le altre nel modulo, e a salvataggio
     * avvenuto non c'e' piu' modo di sapere se e' cambiata in questo giro.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        $this->disattivato = ($record->is_active === true)
            && (($data['is_active'] ?? true) === false);

        $this->quotaPrima = [
            'chiamate' => $record->ai_monthly_call_cap,
            'foto' => $record->ai_monthly_photo_call_cap,
        ];

        return $data;
    }

    /**
     * 🚨 **I due tetti si scrivono a mano, e non e' una scomodita' da togliere.**
     *
     * `ai_monthly_call_cap` e `ai_monthly_photo_call_cap` sono **fuori da
     * `$fillable`** di proposito (vedi `User`): una concessione non si assegna
     * in massa da una richiesta HTTP. ⚠️ Il prezzo e' che `EditRecord`, che
     * salva con `fill()`, li **scarterebbe in silenzio** — il modulo direbbe
     * «salvato» e il tetto resterebbe quello di prima.
     *
     * 💡 Percio' passano da `forceFill()` qui, dove si vede chi li scrive.
     * Metterli in `$fillable` per far funzionare un modulo vorrebbe dire aprire
     * la porta a **ogni** `update()` della piattaforma.
     *
     * @param  User  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Le chiavi si tolgono da `$data`: quello che resta e' roba fillable, e
        // il salvataggio normale se ne occupa come sempre.
        $tetti = [
            'ai_monthly_call_cap' => $this->interoONiente($data['ai_monthly_call_cap'] ?? null),
            'ai_monthly_photo_call_cap' => $this->interoONiente($data['ai_monthly_photo_call_cap'] ?? null),
        ];

        unset($data['ai_monthly_call_cap'], $data['ai_monthly_photo_call_cap']);

        $record->fill($data);
        $record->forceFill($tetti);
        $record->save();

        return $record;
    }

    /**
     * Il valore di un campo numerico che puo' restare vuoto.
     *
     * 🚨 **Vuoto e zero sono due cose opposte**, e questa e' la riga in cui si
     * rischia di confonderle: `null` vuol dire «non decide questo livello,
     * scendi al successivo», `0` vuol dire **illimitato**.
     *
     * ⚠️ Un `(int)` sul campo vuoto darebbe `0`, cioe' trasformerebbe «lascia
     * come le altre» in «illimitato» — e nessuno se ne accorgerebbe finche' non
     * arriva la fattura del modello.
     */
    private function interoONiente(mixed $valore): ?int
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        return (int) $valore;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($this->disattivato) {
            app(AuditLogger::class)->log(
                AuditAction::UserDeactivated,
                $record,
                ['email' => $record->email],
                tenant: $record->tenant_id,
            );
        }

        $this->tracciaLaQuota($record);
    }

    /**
     * La concessione finisce nel registro — **solo se e' cambiata davvero**.
     *
     * 🚨 Da qui si puo' mettere una persona a **illimitato**: chi guarda il
     * costo del mese dopo deve poter risalire a chi gliel'ha dato e quando,
     * senza doverlo dedurre dai consumi.
     *
     * 💡 Scrivere una riga a **ogni** salvataggio dell'utente riempirebbe il
     * registro di righe che dicono «non e' cambiato niente», e un registro
     * rumoroso non lo legge nessuno — che e' come non averlo.
     */
    private function tracciaLaQuota(User $record): void
    {
        $prima = $this->quotaPrima;

        if ($prima === null) {
            return;
        }

        $dopo = [
            'chiamate' => $record->ai_monthly_call_cap,
            'foto' => $record->ai_monthly_photo_call_cap,
        ];

        if ($prima === $dopo) {
            return;
        }

        app(AuditLogger::class)->log(
            AuditAction::AiQuotaChanged,
            $record,
            [
                'email' => $record->email,
                'prima' => array_map($this->leggibile(...), $prima),
                'dopo' => array_map($this->leggibile(...), $dopo),
            ],
            tenant: $record->tenant_id,
        );
    }

    /** ⚠️ `0` nel registro non si legge: va scritto per quello che significa. */
    private function leggibile(?int $valore): string
    {
        return match (true) {
            $valore === null => 'come le altre',
            $valore === 0 => 'ILLIMITATO',
            default => (string) $valore,
        };
    }
}
