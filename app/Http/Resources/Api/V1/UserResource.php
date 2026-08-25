<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\Billing\PianoAttivo;
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
 * @property-read User $resource
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

            /*
             * G8 — cosa puo' cambiare da solo.
             *
             * ⚠️ `password_is_set` e' falso per chi e' entrato con Google o
             * Apple: l'app non deve mostrargli «cambia password», perche' il
             * modulo chiederebbe quella attuale e lui non l'ha mai avuta.
             */
            'password_is_set' => (bool) $this->resource->password_is_set,
            'social' => $this->resource->socialIdentities
                ->map(fn ($i): string => $i->provider->value)->values()->all(),
            'roles' => $this->resource->getRoleNames()->values()->all(),

            /*
             * 🚨 **Cosa comprende il piano** — F4, e il difetto riferito il
             * 13/08/2026.
             *
             * *«La stima da testo mi dice correttamente che non ho le funzioni
             * AI, ma me lo dovrebbe proprio mostrare come disattivato: quando
             * cerco di inserire un alimento mi deve dire "non hai accesso alle
             * funzioni AI, inserisci a mano" e mandarmi all'inserimento
             * manuale.»*
             *
             * ⚠️ **Aveva ragione, e la causa era qui**: l'app non aveva **nessun
             * modo** di sapere in anticipo cosa comprende il piano. Poteva solo
             * provare e ricevere `403 plan_without_ai` — cioè far compilare un
             * modulo e poi dire di no.
             *
             * 💡 Un `bool` e non l'intero piano: all'app serve **decidere se
             * disegnare un pulsante**, non conoscere il listino. Il nome del
             * piano e il prezzo stanno sul sito, dove servono a scegliere.
             *
             * 🚨 E resta un dato **informativo**: il cancello vero è
             * `RequirePlanWithAi`, che risponde `403` anche a un'app che
             * ignorasse questo campo. Un client non decide mai i permessi.
             */
            /*
             * 🚨 `aiUtilizzabile()` e non `haLaAi()` — 15/08/2026.
             *
             * ⚠️ Deve dire la **stessa cosa del cancello**, o le due risposte
             * divergono: con `haLaAi()` chi aveva comprato gettoni su un piano
             * senza AI si vedeva l'app **senza pulsanti**, mentre il server
             * avrebbe accettato la chiamata. Aveva pagato e non trovava dove
             * usare quello che aveva comprato.
             *
             * 💡 La regola generale: questa bandierina e `RequirePlanWithAi`
             * rispondono alla stessa domanda e devono chiamare lo **stesso**
             * metodo. Due implementazioni della stessa regola divergono sempre.
             */
            'ai_enabled' => app(PianoAttivo::class)->aiUtilizzabile($this->resource),

            /*
             * 🚨 **`abbonato` e `tier`, e sono due cose diverse da `ai_enabled`**
             * — 3b-C.8, 25/08/2026.
             *
             * 📌 *«aggiusta anche il server in modo che gli utenti (free users o
             * iscritti in una palestra o con un trainer) abbiano il flag
             * abbonato e il flag tier»*.
             *
             * ⛔ **L'app le aveva confuse**, appoggiandosi al fatto che oggi
             * l'abbonamento concede la quota illimitata: un'osservazione sul
             * presente, non una definizione. Il giorno in cui si vendesse un
             * pacchetto AI senza abbonamento quella riga avrebbe sbagliato **in
             * silenzio**.
             *
             * 💡 `ai_enabled` risponde *«puoi usare l'AI adesso»* — e comprende
             * i gettoni comprati. `abbonato` risponde *«hai un contratto»*.
             * ⚠️ Chi compra gettoni su un piano gratuito ha il primo e non il
             * secondo, ed e' un caso vero, non teorico.
             *
             * 🚨 E restano **informativi**: i cancelli veri stanno sul server.
             * Un client non decide mai i permessi — e' la stessa nota di
             * `ai_enabled` qui sopra.
             */
            'abbonato' => app(PianoAttivo::class)->eAbbonato($this->resource),
            'tier' => app(PianoAttivo::class)->livello($this->resource),
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
