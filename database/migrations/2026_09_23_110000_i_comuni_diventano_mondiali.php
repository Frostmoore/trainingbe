<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🌍 **I comuni diventano mondiali** — 23/09/2026, fase F6.1.
 *
 * 📌 Il committente: *«Beh no, perché poi tra un po' faremo che l'app sarà
 * internazionale, quindi non ha senso importare un dataset italiano»*, e alla
 * scelta fra «stessa tabella, ma mondiale» e «un servizio esterno»:
 * *«Facciamo stessa tabella ma mondiale. E deve funzionare.»*
 *
 * ══ 🚨 PERCHÉ NON SI BUTTA VIA NIENTE ════════════════════════════════════
 *
 * ⛔ La strada più ovvia sarebbe stata: svuotare `comuni` e ricaricarla con
 * GeoNames. **Non si può.** `users.comune_id` e `profili_pubblici.comune_id`
 * sono **chiavi esterne che puntano agli id di queste righe**: cancellarle
 * vorrebbe dire azzerare la città sul profilo di chiunque l'abbia scelta — o
 * farla puntare a un posto diverso, che è peggio.
 *
 * ✅ Quindi le righe italiane **restano quelle**, con lo stesso id. Si
 * aggiungono delle colonne, e il mondo si accoda.
 *
 * 💡 **E questo cancella la "rimappatura" prevista dal piano** (§6.7, passo
 * «i comuni italiani già scelti vanno rimappati»): non serve più, perché
 * nessun id cambia. La rimappatura serviva solo se `codice` fosse diventato il
 * geonameid **sulle stesse righe**.
 *
 * ══ 🇮🇹 L'ITALIA RESTA ISTAT, IL RESTO DEL MONDO È GEONAMES ═══════════════
 *
 * ⛔ GeoNames conosce Milano, ma sa **meno**: niente CAP, niente sigla di
 * provincia, e si ferma ai centri sopra i 500 abitanti — l'Italia ha 7.896
 * comuni, `cities500` ne conosce ~7.000.
 *
 * 🚨 E i due insiemi **non si sovrappongono**: `costruisci-mondo.php` salta
 * l'Italia. Senza, Milano comparirebbe due volte nel selettore, con due id
 * diversi — cioè due profili che puntano a due righe che sono lo stesso posto.
 *
 * ══ ⚠️ L'UNICITÀ CAMBIA FORMA ════════════════════════════════════════════
 *
 * Prima: `codice` unico. ⛔ Non regge più — il codice ISTAT `001001` e un
 * geonameid sono numeri di due mondi diversi, e nulla vieta che coincidano.
 * ✅ Adesso l'unicità è **`(paese, codice)`**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comuni', function (Blueprint $tabella): void {
            /*
             * 🌍 **Il paese, in ISO 3166-1 alpha-2.**
             *
             * ⚠️ `default('IT')` non è pigrizia: quando questa migrazione gira,
             * in tabella ci sono **solo** comuni italiani. Il default li marca
             * tutti in un colpo, senza un `UPDATE` che tocchi ottomila righe.
             */
            $tabella->char('paese', 2)->default('IT')->after('codice');

            /*
             * 🔑 **L'identificatore di GeoNames**, per le righe che vengono da
             * lì. ⚠️ `null` sulle italiane, e resta `null`: il loro
             * identificatore stabile è il codice ISTAT, e appaiarle a GeoNames
             * per nome sarebbe indovinare.
             */
            $tabella->unsignedBigInteger('geonameid')->nullable()->after('paese');

            /*
             * 🗺️ Le divisioni amministrative di GeoNames.
             *
             * 💡 `admin1` è lo stato/regione (`BY` per la Baviera, `CA` per la
             * California), `admin2` la provincia/contea. ⚠️ Sono **codici**, e
             * il nome leggibile finisce in `provincia_nome`: servono a
             * raggruppare, non a mostrare.
             *
             * 🚨 **64, e non 20 come sembrerebbe ragionevole.** GeoNames questi
             * campi li tiene sporchi: il più lungo del dump è
             * `undefined = Kalininskiy Rayon`, 29 caratteri. ⛔ Con `varchar(20)`
             * l'importazione si ferma con *«Data too long for column admin2»*
             * dopo due minuti e ottocento righe — misurato, non immaginato.
             *
             * 💡 Largo e senza indice: non ci si cerca sopra, ci si raggruppa.
             */
            $tabella->string('admin1', 64)->nullable()->after('provincia_nome');
            $tabella->string('admin2', 64)->nullable()->after('admin1');

            /*
             * 🗣️ **Il nome nella lingua del posto** — `München`, `Warszawa`.
             *
             * ⚠️ Il nome GeoNames è quasi sempre quello **inglese** (`Munich`),
             * e il nome italiano finisce in `nome_altro` (`Monaco di Baviera`):
             * serve un terzo posto per chi abita lì e scrive la sua città come
             * la scrive lui. ⛔ Senza, un tedesco che digita «münchen» non
             * trova casa sua — e l'app, ha detto il committente, sarà
             * internazionale.
             */
            $tabella->string('nome_locale', 120)->nullable()->after('nome_altro');
            $tabella->string('chiave_locale', 140)->nullable()->after('chiave_altro');
            $tabella->index('chiave_locale', 'comuni_chiave_locale_index');

            $tabella->index(['paese', 'attivo', 'chiave'], 'comuni_paese_ricerca_index');
            $tabella->unique('geonameid', 'comuni_geonameid_unique');
        });

        /*
         * 🚨 **Le due colonne si allargano, e non è cosmesi.**
         *
         * ⛔ `codice` è `varchar(6)`: ci sta il codice ISTAT e **non** un
         * geonameid, che arriva a otto cifre. ⛔ `provincia` è `varchar(2)`:
         * ci sta `TO` e non `MIS`, e ci sono paesi con codici admin1 più
         * lunghi.
         *
         * ⚠️ Senza queste due righe l'importazione del mondo **troncherebbe in
         * silenzio** (o esploderebbe, a seconda dello `sql_mode`): due città
         * diverse finirebbero con lo stesso codice troncato, e una delle due
         * sovrascriverebbe l'altra.
         */
        DB::statement('ALTER TABLE comuni MODIFY codice VARCHAR(20) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY provincia VARCHAR(20) NOT NULL');

        /*
         * 📏 **E anche i nomi, che in Italia stavano larghi.**
         *
         * 🚨 Misurato sul dump vero, non a occhio:
         *
         * | Colonna | Prima | Il più lungo nel mondo |
         * |---|---|---|
         * | `nome` | 80 | **97** — *United Townships of Dysart, Dudley, Harcourt…* (Canada) |
         * | `regione` | 40 | **41** — *Territorio Britannico dell'Oceano Indiano* |
         *
         * ⚠️ Mancava **un carattere** su `regione`: il tipo di sbaglio che si
         * scopre a metà di un'importazione da duecentomila righe, e solo per
         * quel paese.
         *
         * 💡 `chiave` segue `nome`: è la sua versione normalizzata, e se
         * il nome ci sta e la chiave no la ricerca smette di trovare proprio
         * le righe dai nomi lunghi.
         */
        DB::statement('ALTER TABLE comuni MODIFY nome VARCHAR(120) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY nome_altro VARCHAR(120) NULL');
        DB::statement('ALTER TABLE comuni MODIFY regione VARCHAR(80) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY provincia_nome VARCHAR(80) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY chiave VARCHAR(140) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY chiave_altro VARCHAR(140) NULL');

        /*
         * ⚠️ L'unico su `codice` va tolto **dopo** aver allargato la colonna e
         * **prima** di aggiungere quello nuovo: farlo prima lascerebbe la
         * tabella senza nessuna difesa contro i doppioni per il tempo di due
         * istruzioni, e questa migrazione può girare su un database vivo.
         */
        Schema::table('comuni', function (Blueprint $tabella): void {
            $tabella->unique(['paese', 'codice'], 'comuni_paese_codice_unique');
            $tabella->dropUnique('comuni_codice_unique');
        });
    }

    public function down(): void
    {
        /*
         * ══ 🚨 UN `down()` CHE RIPARTE DA UNO STATO QUALUNQUE ══════════════
         *
         * ⛔ **MySQL non annulla le modifiche di schema.** Una migrazione che si
         * ferma a metà — per un indice che non c'è, per un timeout — lascia la
         * tabella **a metà**: qualche colonna tolta, qualche indice no. Ed è
         * successo davvero, in locale, il 23/09: il primo `down()` cercava di
         * togliere un indice che la versione precedente della migrazione non
         * aveva creato, si è fermato, e la tabella è rimasta **senza nessun
         * vincolo di unicità**.
         *
         * ✅ Quindi ogni passo **guarda prima**: toglie un indice solo se c'è,
         * una colonna solo se c'è. 💡 Rilanciato su una tabella a metà, finisce
         * il lavoro invece di esplodere di nuovo sullo stesso punto.
         *
         * ── ⚠️ E cancella le righe non italiane ──────────────────────────────
         *
         * È l'unica cosa che può fare: senza `paese` non ci sarebbe più modo di
         * distinguerle, e l'unico su `codice` non tornerebbe su una tabella con
         * due `12345` di due paesi diversi. 🚨 Chi lo lancia perde la città dei
         * profili non italiani — scritto qui perché si sappia prima.
         */
        if (Schema::hasColumn('comuni', 'paese')) {
            DB::table('comuni')->where('paese', '!=', 'IT')->delete();
        }

        foreach ([
            'comuni_paese_codice_unique',
            'comuni_geonameid_unique',
        ] as $unico) {
            if (Schema::hasIndex('comuni', $unico)) {
                Schema::table('comuni', fn (Blueprint $t) => $t->dropUnique($unico));
            }
        }

        foreach ([
            'comuni_paese_ricerca_index',
            'comuni_chiave_locale_index',
        ] as $indice) {
            if (Schema::hasIndex('comuni', $indice)) {
                Schema::table('comuni', fn (Blueprint $t) => $t->dropIndex($indice));
            }
        }

        foreach (['paese', 'geonameid', 'admin1', 'admin2', 'nome_locale', 'chiave_locale'] as $colonna) {
            if (Schema::hasColumn('comuni', $colonna)) {
                Schema::table('comuni', fn (Blueprint $t) => $t->dropColumn($colonna));
            }
        }

        DB::statement('ALTER TABLE comuni MODIFY codice VARCHAR(6) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY provincia VARCHAR(2) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY nome VARCHAR(80) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY nome_altro VARCHAR(80) NULL');
        DB::statement('ALTER TABLE comuni MODIFY regione VARCHAR(40) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY provincia_nome VARCHAR(60) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY chiave VARCHAR(100) NOT NULL');
        DB::statement('ALTER TABLE comuni MODIFY chiave_altro VARCHAR(100) NULL');

        if (! Schema::hasIndex('comuni', 'comuni_codice_unique')) {
            Schema::table('comuni', fn (Blueprint $t) => $t->unique('codice', 'comuni_codice_unique'));
        }
    }
};
