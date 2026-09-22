<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 👀 L'anteprima delle mail — 22/09/2026
|--------------------------------------------------------------------------
|
| 📌 Il committente: *«crea il template e il testo di quelle email»*. Per
| guardarle serve vederle: qui ogni mail si apre nel browser, con dati finti.
|
| ⛔ **Solo in locale** (`APP_ENV=local`): le rotte si registrano in
| `bootstrap/app.php` dentro un `if`, quindi in produzione non esistono — non
| sono «protette», proprio non ci sono. 🚨 Fossero raggiungibili mostrerebbero
| a chiunque come sono fatte le nostre mail, che è mezzo lavoro di chi ne scrive
| una falsa.
|
| Uso:  http://127.0.0.1:8123/anteprima-mail            (l'elenco)
|       http://127.0.0.1:8123/anteprima-mail/benvenuto  (una sola)
*/

use App\Mail\BenvenutoMail;
use App\Mail\InvitoDelTrainerMail;
use App\Mail\InvitoInPalestraMail;
use App\Mail\LinkPerLaPassword;
use App\Mail\MessaggioDalPannello;
use App\Mail\PagamentoFallitoMail;
use App\Mail\PasswordCambiataMail;
use App\Mail\PromozionePerTeMail;
use App\Mail\RichiestaDalPannello;
use App\Mail\RichiestaDalSitoMail;
use App\Mail\RichiestaRicevutaMail;
use App\Mail\RispostaAllaRichiestaMail;
use App\Models\InvitoInPalestra;
use App\Models\Promozione;
use App\Models\RichiestaDiContatto;
use App\Models\RispostaAllaRichiesta;
use App\Models\Tenant;
use App\Models\TrainerInvite;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Route;

/** Dati finti, mai salvati: `make()` e non `create()`. */
$finti = function (): array {
    $persona = User::make(['name' => 'Rita Bianchi', 'email' => 'rita@esempio.it']);
    $trainer = User::make(['name' => 'Marco Rossi', 'email' => 'marco@esempio.it']);
    $palestra = Tenant::make(['name' => 'Palestra Olimpo']);

    $richiesta = RichiestaDiContatto::make([
        'tipo' => 'palestra',
        'nome' => 'Rita Bianchi',
        'email' => 'rita@esempio.it',
        'telefono' => '333 1234567',
        'organizzazione' => 'Palestra Olimpo',
        'iscritti' => 240,
        'trainer' => 6,
        'messaggio' => "Buongiorno,\nvorrei capire quanto costa per una palestra con 240 iscritti.\n\nGrazie",
    ]);

    $invitoPalestra = InvitoInPalestra::make([
        'token' => str_repeat('A', 32),
        'email' => 'rita@esempio.it',
        'ruolo' => 'member',
        'expires_at' => now()->addDays(7),
    ]);
    $invitoPalestra->setRelation('tenant', $palestra);

    $invitoTrainer = TrainerInvite::make([
        'token' => str_repeat('B', 32),
        'email' => 'rita@esempio.it',
        'expires_at' => now()->addDays(7),
    ]);

    $promozione = Promozione::make([
        'nome' => 'Un mese di Plus',
        'descrizione' => "Perché ci hai portato un amico.\nSi attiva da sola: non devi fare niente.",
    ]);

    $risposta = RispostaAllaRichiesta::make([
        'oggetto' => 'Il piano per 240 iscritti',
        'testo' => 'Buongiorno Rita,
grazie per averci scritto: per 240 iscritti il piano giusto è il gym_30.

Se vuole ne parliamo al telefono.',
    ]);
    $risposta->setRelation('richiesta', $richiesta);

    $testoLungo = "Ciao,\nabbiamo visto la tua segnalazione e l'abbiamo sistemata.\n\nGrazie di avercelo detto.";

    return compact('persona', 'trainer', 'palestra', 'richiesta', 'invitoPalestra', 'invitoTrainer', 'promozione', 'risposta', 'testoLungo');
};

Route::get('/anteprima-mail', function () use ($finti): string {
    $quali = array_keys(anteprimaMail($finti()));
    $righe = collect($quali)
        ->map(fn (string $q): string => '<li><a href="/anteprima-mail/'.$q.'">'.$q.'</a></li>')
        ->implode('');

    return '<h1>Anteprima delle mail</h1><ul>'.$righe.'</ul>';
});

Route::get('/anteprima-mail/{quale}', function (string $quale) use ($finti) {
    $mail = anteprimaMail($finti())[$quale] ?? abort(404);

    return $mail->render();
});

/**
 * @param  array<string, mixed>  $d
 * @return array<string, Mailable>
 */
function anteprimaMail(array $d): array
{
    return [
        'benvenuto-persona' => new BenvenutoMail($d['persona'], BenvenutoMail::PERSONA),
        'benvenuto-trainer' => new BenvenutoMail($d['trainer'], BenvenutoMail::TRAINER),
        'benvenuto-palestra' => new BenvenutoMail($d['persona'], BenvenutoMail::PALESTRA),
        'link-per-la-password' => new LinkPerLaPassword($d['persona'], 'https://trainingcompanion.it/password/ABC'),
        'password-cambiata' => new PasswordCambiataMail($d['persona']),
        'invito-del-trainer' => new InvitoDelTrainerMail($d['invitoTrainer'], $d['trainer']),
        'invito-in-palestra' => new InvitoInPalestraMail($d['invitoPalestra']),
        'messaggio-dal-pannello' => new MessaggioDalPannello(
            $d['persona'],
            'La tua segnalazione',
            $d['testoLungo'],
            'Marco',
        ),
        'promozione-per-te' => new PromozionePerTeMail($d['promozione'], $d['persona'], true),
        'richiesta-ricevuta' => new RichiestaRicevutaMail($d['richiesta']),
        'richiesta-dal-sito' => new RichiestaDalSitoMail($d['richiesta']),
        'richiesta-dal-pannello' => new RichiestaDalPannello($d['richiesta']),
        'risposta-alla-richiesta' => new RispostaAllaRichiestaMail($d['risposta']),
        'pagamento-fallito' => new PagamentoFallitoMail($d['palestra'], "l'abbonamento"),
    ];
}
