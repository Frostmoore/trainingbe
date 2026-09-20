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

    public function firma(): void
    {
        $persona = Auth::user();
        $tipo = app(LeLiberatorie::class)->serveA($persona);

        if ($tipo === null) {
            $this->redirect(filament()->getCurrentPanel()?->getPath() ?? '/admin');

            return;
        }

        app(LeLiberatorie::class)->firma($persona, $tipo, request());

        Notification::make()
            ->title('Firmato. Buon lavoro.')
            ->success()
            ->send();

        $this->redirect(filament()->getCurrentPanel()?->getPath() ?? '/admin');
    }
}
