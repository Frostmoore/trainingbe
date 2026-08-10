<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * L'utente come lo vede l'app.
 *
 * Elenco esplicito dei campi, mai `$this->resource->toArray()`: aggiungere una
 * colonna al modello non deve pubblicarla per sbaglio nell'API. `is_super_admin`
 * e `tenant_id` restano fuori — al client non servono e sono informazioni sulla
 * struttura della piattaforma.
 *
 * @property-read \App\Models\User $resource
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,

            /*
             * C7.1 — il nome utente.
             *
             * ⚠️ Mancava, e la registrazione lo chiede come campo obbligatorio:
             * la persona lo sceglie, lo usa per accedere, e non lo rivede mai
             * più da nessuna parte. Chi lo dimentica non ha modo di
             * recuperarlo dall'app.
             */
            'username' => $this->resource->username,

            'phone' => $this->resource->phone,
            'avatar_url' => $this->resource->avatarUrl(),
            'locale' => $this->resource->locale,
            'roles' => $this->resource->getRoleNames()->values()->all(),
            /*
             * C7.2 — il profilo c'è **sempre**, anche vuoto.
             *
             * 🚨 Con `whenLoaded` la chiave spariva del tutto quando la
             * relazione non era caricata o non esisteva, e l'app non poteva
             * distinguere «profilo assente» da «profilo non chiesto»: due casi
             * che richiedono schermate diverse. Una chiave che a volte c'è e a
             * volte no costringe il client a un ramo in più per ogni campo.
             */
            'profile' => $this->resource->profile === null ? null : [
                'sex' => $this->resource->profile->sex,
                'birthdate' => $this->resource->profile->birthdate?->toDateString(),
                'age' => $this->resource->profile->age(),
                'height_cm' => $this->resource->profile->height_cm,
                'activity_level' => $this->resource->profile->activity_level,
                'goal' => $this->resource->profile->goal,
                'target_weight_kg' => $this->resource->profile->target_weight_kg,
                'meal_hours' => $this->resource->profile->meal_hours,
            ],
        ];
    }
}
