<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * I default del database valgono anche sull'oggetto appena creato -- 14/09/2026.
 *
 * -- LA TRAPPOLA, DESCRITTA DA AGOSTO E CADUTACI LO STESSO --
 *
 * `User::$attributes` esiste apposta, e il suo docblock la spiega: i default
 * del database si applicano all'INSERT, non all'oggetto in memoria. Senza,
 * subito dopo `User::create()` la colonna vale `null`, e si raddrizza solo alla
 * prima rilettura.
 *
 * `password_is_set` era l'unica colonna con un default nel database a non stare
 * in quell'elenco. Risultato: `POST /auth/register` rispondeva
 * `password_is_set: false` a una persona che aveva appena scelto una password,
 * e `true` al login successivo.
 *
 * Trovato guardando l'app su un emulatore, non da un test.
 *
 * -- PERCHE' QUESTO TEST E' SULLO SCHEMA E NON SULLA COLONNA --
 *
 * Un test su `password_is_set` avrebbe chiuso questo caso e lasciato aperto il
 * prossimo. Questo enumera le colonne booleane di `users` che hanno un default
 * nel database, e pretende che ciascuna abbia lo stesso valore SUBITO dopo la
 * creazione: la sesta colonna che qualcuno aggiungera' domani e' gia' coperta.
 */
final class OgniDefaultDelDatabaseStaInMemoriaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function un_utente_appena_creato_ha_gia_i_default_del_database(): void
    {
        $appena = new User([
            'name' => 'Appena Nato',
            'email' => 'appena@esempio.test',
            'password' => TestCase::FAKE_PASSWORD,
        ]);

        foreach ($this->colonneConDefault() as $colonna => $atteso) {
            self::assertSame(
                $atteso,
                $appena->{$colonna},
                "{$colonna}: il default del database non e' in User::\$attributes, "
                .'quindi vale null finche\' qualcuno non rilegge la riga',
            );
        }
    }

    /**
     * 🚨 **E anche dopo `create()`**, che e' il caso vero: `register` risponde
     * con l'oggetto che ha appena creato, non con uno riletto.
     */
    #[Test]
    public function e_anche_subito_dopo_averlo_scritto(): void
    {
        $creato = User::withoutGlobalScopes()->create([
            'name' => 'Appena Scritto',
            'email' => 'scritto@esempio.test',
            'password' => TestCase::FAKE_PASSWORD,
        ]);

        foreach ($this->colonneConDefault() as $colonna => $atteso) {
            self::assertSame($atteso, $creato->{$colonna}, "{$colonna} dopo create()");
        }
    }

    /**
     * Le colonne booleane di `users` che hanno un default nel database.
     *
     * ⚠️ Scritto a mano e non letto dallo schema: leggere il default da SQLite
     * e da MySQL vuol dire due dialetti e due modi di sbagliare. 💡 Questo
     * elenco e' corto, e se qualcuno aggiunge una colonna senza metterla qui il
     * test non se ne accorge — ma il docblock di `User::$attributes` lo dice, e
     * questo test e' il posto in cui ci si ricorda di guardarlo.
     *
     * @return array<string, bool|string>
     */
    private function colonneConDefault(): array
    {
        self::assertTrue(Schema::hasColumn('users', 'password_is_set'));

        return [
            'is_active' => true,
            'is_super_admin' => false,
            'password_is_set' => true,
            'consiglio_automatico' => true,
            'locale' => 'it',
        ];
    }
}
