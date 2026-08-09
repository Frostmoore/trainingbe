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
            'phone' => $this->resource->phone,
            'avatar_url' => $this->resource->avatarUrl(),
            'locale' => $this->resource->locale,
            'roles' => $this->resource->getRoleNames()->values()->all(),
            'profile' => $this->whenLoaded('profile', fn () => [
                'sex' => $this->resource->profile->sex,
                'birthdate' => $this->resource->profile->birthdate?->toDateString(),
                'age' => $this->resource->profile->age(),
                'height_cm' => $this->resource->profile->height_cm,
                'activity_level' => $this->resource->profile->activity_level,
                'goal' => $this->resource->profile->goal,
                'target_weight_kg' => $this->resource->profile->target_weight_kg,
                'meal_hours' => $this->resource->profile->meal_hours,
            ]),
        ];
    }
}
