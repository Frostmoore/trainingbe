<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantOrGlobal;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * Il media di medialibrary, con la palestra addosso.
 *
 * 🚨 **Perche' una sottoclasse invece di lasciare quella del pacchetto.**
 * `TenantIsolationTest` enumera i modelli in `app/Models`: una classe in
 * `vendor/` non verrebbe mai controllata, e la tabella `media` — che contiene
 * le foto dei progressi degli iscritti, cioe' il dato piu' personale che questo
 * sistema conserva — resterebbe l'unica con `tenant_id` fuori dal gate.
 *
 * Usa `BelongsToTenantOrGlobal` perche' la colonna e' nullable: i loghi delle
 * palestre vengono caricati dal pannello di piattaforma, dove non c'e' contesto,
 * e sono legittimamente globali.
 *
 * ⚠️ Va dichiarata in `config/media-library.php` alla chiave `media_model`,
 * altrimenti il pacchetto continua a istanziare la sua e questa non serve a
 * niente.
 */
class Media extends BaseMedia
{
    use BelongsToTenantOrGlobal;
}
