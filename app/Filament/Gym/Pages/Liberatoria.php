<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Services\Privacy\LeLiberatorie;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * ✍️ **La firma della liberatoria** — art. 28 GDPR, 20/09/2026.
 *
 * 📌 Il committente: *«dovrà firmare la liberatoria specifica per le palestre
 * (idem per i trainer)»*.
 *
 * 🚨 **Non compare nel menu**, e non è una dimenticanza: finché la firma manca
 * `PretendeLaLiberatoria` manda qui da qualunque pagina, e dopo la firma questa
 * pagina non serve più. Una voce di menu permanente inviterebbe a rileggere un
 * atto già compiuto come se si potesse rifare.
 */
class Liberatoria extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Prima di cominciare';

    protected string $view = 'filament.gym.pages.liberatoria';

    /** Il tipo che tocca a questa persona, o `null` se ha già firmato. */
    public function tipo(): ?string
    {
        return app(LeLiberatorie::class)->serveA(Auth::user());
    }

    /** Il testo, già in HTML. */
    public function testo(): string
    {
        $tipo = $this->tipo();

        if ($tipo === null) {
            return '';
        }

        /*
         * ⚠️ `html_input: escape`: il markdown lo scriviamo noi, ma questa è la
         * regola di casa per ogni testo che passa da un file a una pagina —
         * la stessa dell'informativa (`SitoController`).
         */
        return Str::markdown(app(LeLiberatorie::class)->testo($tipo), ['html_input' => 'escape']);
    }

    /**
     * Dove si va dopo aver firmato: **la casa del pannello**.
     *
     * ══ 🐛 `getPath()` MANDAVA SU `/admin/admin` — 24/09/2026 ═════════════════
     *
     * ⛔ Qui c'era `filament()->getCurrentPanel()?->getPath()`, che torna
     * `'admin'` — **senza la barra davanti**. 🚨 Un redirect relativo, partendo
     * da `/admin/liberatoria`, lo attacca al percorso corrente: `/admin/admin`,
     * cioè **404**.
     *
     * ⚠️ **E la firma era andata a buon fine.** Il difetto arrivava dopo il
     * salvataggio, quindi l'atto risultava firmato e la persona vedeva
     * *«Questo indirizzo non esiste»*: il momento peggiore possibile per un
     * 404 — chi ha appena accettato una responsabilità legale e si trova
     * davanti una pagina rotta pensa che non sia stato registrato niente.
     *
     * 💡 `getUrl()` torna un **URL assoluto** e tiene conto della tenancy, che
     * è esattamente la differenza fra i due metodi.
     *
     * ⛔ **E il ripiego passa da `url()`**, non è la stringa `/admin`: anche
     * quella è relativa, e avrebbe lo stesso difetto il giorno che il pannello
     * corrente non c'è. 🔍 Trovato dal test, che senza pannello corrente
     * cadeva proprio lì.
     */
    private function casaDelPannello(): string
    {
        return filament()->getCurrentPanel()?->getUrl() ?? url('/admin');
    }

    public function firma(): void
    {
        $persona = Auth::user();
        $tipo = app(LeLiberatorie::class)->serveA($persona);

        if ($tipo === null) {
            $this->redirect($this->casaDelPannello());

            return;
        }

        app(LeLiberatorie::class)->firma($persona, $tipo, request());

        Notification::make()
            ->title('Firmato. Buon lavoro.')
            ->success()
            ->send();

        $this->redirect($this->casaDelPannello());
    }
}
