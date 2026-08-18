<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I comuni italiani, e la citta' sul profilo — 18/08/2026. **M1.1 e M1.2.**
 *
 * ── 🚨 Perche' una tabella di comuni invece del GPS ────────────────────────
 *
 * Perche' la posizione di una persona e' un dato sensibile, e la regola portante
 * di questo progetto e' che tutto cio' che e' anche lontanamente sensibile resta
 * sul telefono. ⚠️ Il GPS avrebbe voluto dire mandare **dove sta qualcuno** al
 * server: un consenso dedicato, una riga nel registro dei trattamenti, una voce
 * nell'informativa — per un guadagno di precisione che su «trovami una palestra»
 * e' quasi nullo. Nessuno sceglie quella a 800 metri invece di quella a 1,2 km.
 *
 * 💡 Qui invece si conserva **il comune scelto a mano**: un'informazione che la
 * persona decide di dare, grossolana quanto basta, e che funziona anche al
 * chiuso — dove le persone stanno quando usano l'app.
 *
 * ── 💡 E allora perche' ci sono le coordinate ──────────────────────────────
 *
 * Perche' sono le coordinate **del comune**, che sono un dato pubblico: che
 * Bologna disti 40 km da Modena non dice niente di nessuno. Servono a ordinare i
 * risultati per distanza vera invece di inventare una tabella di province
 * confinanti scritta a mano — 🚨 che sarebbe stata 107 righe compilate a
 * memoria, cioe' 107 occasioni di sbagliare senza che nessun test se ne accorga.
 *
 * ⚠️ E risolve un difetto che «stessa provincia» avrebbe avuto per sempre: chi
 * sta a Rimini ha Pesaro a 30 km e Ferrara a 100, ma Pesaro e' in un'altra
 * regione. Per provincia, Pesaro non sarebbe mai comparsa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comuni', function (Blueprint $tabella): void {
            $tabella->id();

            /*
             * Il codice ISTAT a sei cifre, **con gli zeri davanti** (`001001`).
             *
             * 🚨 E' la chiave con cui l'import riconosce una riga gia' scritta,
             * ed e' l'unico identificatore stabile che esiste: i comuni cambiano
             * nome (`Forli'` / `Forlì`), cambiano provincia quando ne nasce una
             * nuova, e si fondono. Il codice no.
             *
             * ⚠️ `string` e non `integer`: gli zeri davanti fanno parte del
             * codice, e un intero se li mangia.
             */
            $tabella->string('codice', 6)->unique();

            $tabella->string('nome', 80);

            /*
             * Il nome nell'altra lingua, dove c'e' (`Bozen`, `Aoste`).
             *
             * 💡 Sono duecento comuni fra Alto Adige e Valle d'Aosta. Costa una
             * colonna e un indice, e permette a chi vive li' di cercare la
             * propria citta' come la chiama.
             */
            $tabella->string('nome_altro', 80)->nullable();

            /*
             * 🚨 Le chiavi normalizzate: e' su queste che si cerca, **mai** sui
             * nomi cosi' come sono scritti.
             *
             * ⚠️ Chi digita `sant angelo` non trova `Sant'Angelo`, e chi digita
             * `agostino` non trova `Agliè` se l'accento non viene tolto da
             * entrambe le parti. La normalizzazione deve essere **la stessa** in
             * scrittura e in lettura, e sta in `ChiaveComune`.
             *
             * 💡 Deliberatamente **non** riusa `ChiaveAlimento`: quella toglie le
             * parole di riempimento (`di`, `la`, `al`), che per un alimento e'
             * giusto — «pasta al pomodoro» e «pasta con pomodoro» sono la stessa
             * cosa — e per un comune sarebbe un disastro: `La Spezia` diventerebbe
             * `spezia`, e chi digita «la sp» non troverebbe piu' niente.
             */
            $tabella->string('chiave', 100)->index();
            $tabella->string('chiave_altro', 100)->nullable()->index();

            /** La sigla automobilistica: `TO`, `RM`. */
            $tabella->string('provincia', 2)->index();

            /** Il nome per esteso, per mostrarlo: `Torino`. */
            $tabella->string('provincia_nome', 60);

            $tabella->string('regione', 40)->index();

            /**
             * 🚨 Il CAP **principale**, non l'unico: Roma ne ha oltre duecento.
             * Serve a compilare un indirizzo, non a identificare il comune.
             */
            $tabella->string('cap', 5)->nullable();

            /*
             * 🚨 La popolazione **non serve a mostrarla**: serve a ordinare la
             * ricerca.
             *
             * ⚠️ Senza, chi digita `mil` vede prima `Milanesi` o `Milena` — che
             * in ordine alfabetico vengono prima di `Milano` — e un selettore di
             * citta' in cui il capoluogo non compare fra i primi e' un selettore
             * che le persone smettono di usare.
             *
             * 💡 Non e' un dato che si mantiene aggiornato: serve solo a dire
             * «questa e' piu' grande di quella», e per quello un censimento vale
             * l'altro.
             */
            $tabella->unsignedInteger('popolazione')->nullable();

            /*
             * Le coordinate del centro. Nullable, e va accettato.
             *
             * ⚠️ La fonte delle coordinate e' del 2018: **57 comuni** nati o
             * modificati dopo non ne hanno. Per loro la vicinanza ripiega su
             * provincia e regione — vedi `Vicinanza`. 🚨 Un servizio che assume
             * che ci siano sempre si rompe su cinquantasette casi veri.
             *
             * `decimal(9,6)` e non `float`: sei decimali sono ~11 cm, piu' che
             * abbastanza per un centro abitato, e un decimale non ha gli errori
             * di arrotondamento che rendono due confronti diversi fra loro.
             */
            $tabella->decimal('lat', 9, 6)->nullable();
            $tabella->decimal('lng', 9, 6)->nullable();

            /*
             * 🚨 **I comuni non si cancellano mai, si spengono.**
             *
             * Quando due comuni si fondono, l'ISTAT toglie i vecchi dall'elenco.
             * ⚠️ Cancellarli qui vorrebbe dire azzerare la citta' sul profilo di
             * chiunque li avesse scelti — cioe' rompere il dato di una persona
             * per un riordino amministrativo che non la riguarda.
             *
             * 💡 Quindi l'import **non cancella niente**: mette `attivo = false`.
             * La ricerca li nasconde, i profili che ci puntano continuano a
             * funzionare, e chi apre il profilo vedra' ancora scritto il nome
             * giusto finche' non lo cambia.
             */
            $tabella->boolean('attivo')->default(true);

            $tabella->timestamps();

            /*
             * ⚠️ L'indice per il riquadro di ricerca della vicinanza.
             *
             * 🚨 La distanza vera (haversine) **non e' indicizzabile**: e' una
             * funzione, e MySQL la calcola riga per riga. Il modo per non
             * scansionare ottomila comuni a ogni ricerca e' restringere prima con
             * un rettangolo su `lat`/`lng` — che l'indice copre — e calcolare la
             * distanza solo su quello che resta.
             */
            $tabella->index(['lat', 'lng']);

            /**
             * 💡 `attivo` per primo: e' il filtro che si applica sempre, e
             * mettere per primo il campo piu' selettivo e' cio' che rende un
             * indice composto utile invece che decorativo.
             */
            $tabella->index(['attivo', 'chiave']);
        });

        Schema::table('users', function (Blueprint $tabella): void {
            /*
             * La citta' della persona.
             *
             * 🚨 `nullable` e resta nullable per sempre: **non e' obbligatoria**.
             * Chi non vuole dire dove sta continua a usare l'applicazione intera
             * — perde solo l'ordinamento per vicinanza nel catalogo, che e' un
             * servizio che gli si offre, non un pedaggio.
             *
             * ⚠️ `nullOnDelete` come rete di sicurezza. Non dovrebbe servire —
             * l'import non cancella (vedi `attivo`) — ma se un giorno qualcuno
             * cancellasse un comune a mano, meglio un profilo senza citta' che un
             * errore di chiave esterna su ogni login.
             */
            $tabella->foreignId('comune_id')->nullable()->after('timezone')
                ->constrained('comuni')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabella): void {
            $tabella->dropForeign(['comune_id']);
            $tabella->dropColumn('comune_id');
        });

        Schema::dropIfExists('comuni');
    }
};
