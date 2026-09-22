<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⛔ **Il codice palestra non torna** — F11 di `plan_UX_pannello.md`, 24/09/2026.
 *
 * 📌 Il committente: *«Il codice palestra non ha più senso di esistere, gli
 * inviti si fanno con il link di invito. Togliamo proprio tutto il discorso dei
 * codici delle palestre»*; e poi *«è da rimuovere da tutto, non esiste più»*.
 *
 * 🚨 Il codice era una chiave che entrava infinite volte: finito in una chat di
 * gruppo faceva entrare venti persone. L'invito è monouso e scade. Questi test
 * esistono perché il codice **era già stato tolto a metà** (dal pannello, il
 * 21/09) ed era rimasto vivo in registrazione, accesso, accesso social e app
 * senza che nessuno se ne accorgesse.
 */
final class IlCodicePalestraNonTornaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * I soli file che possono nominare `join_code` fuori dai commenti: le due
     * richieste che lo **rifiutano** (`prohibited`, con un messaggio che dice
     * dove si entra), e il registro, che deve saper leggere le righe vecchie.
     *
     * @var list<string>
     */
    private const AMMESSI = [
        'app/Enums/AuditAction.php',
        'app/Http/Requests/Api/V1/RegisterRequest.php',
        'app/Http/Requests/Api/V1/SocialLoginRequest.php',
    ];

    #[Test]
    public function la_colonna_non_c_e(): void
    {
        $this->assertFalse(Schema::hasColumn('tenants', 'join_code'));
        $this->assertNotContains('join_code', (new Tenant)->getFillable());
        $this->assertFalse(method_exists(Tenant::class, 'generateJoinCode'));
    }

    #[Test]
    public function la_ricerca_della_palestra_dal_codice_non_c_e(): void
    {
        $uri = collect(Route::getRoutes()->getRoutes())->map(fn ($r): string => $r->uri());

        $this->assertNotContains('api/v1/branding/lookup', $uri->all());
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Api/V1/BrandingController.php'));
    }

    /**
     * 🚨 Nessun codice dell'applicazione legge o scrive più il codice palestra.
     * ⚠️ I commenti non contano: raccontano perché non c'è più.
     */
    #[Test]
    public function nessuno_lo_nomina_fuori_dai_commenti(): void
    {
        $colpevoli = [];

        foreach ([app_path(), base_path('routes'), database_path('seeders')] as $cartella) {
            foreach (File::allFiles($cartella) as $file) {
                $relativo = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                if ($file->getExtension() !== 'php' || in_array($relativo, self::AMMESSI, true)) {
                    continue;
                }

                // ⚠️ `/u`: senza, `\R` spezza la riga sul byte 0x85 che sta dentro ✅ (E2 9C 85).
                foreach (preg_split('/\R/u', $file->getContents()) as $n => $riga) {
                    $pulita = ltrim($riga);

                    // ⚠️ Anche `|`: è lo stile dei blocchi di commento grandi (`/* | … */`).
                    if (str_starts_with($pulita, '*') || str_starts_with($pulita, '/*') || str_starts_with($pulita, '//') || str_starts_with($pulita, '|')) {
                        continue;
                    }

                    if (preg_match('/join_?code/i', $riga) === 1) {
                        $colpevoli[] = $relativo.':'.($n + 1).'  '.trim($riga);
                    }
                }
            }
        }

        $this->assertSame([], $colpevoli, "Il codice palestra è tornato:\n".implode("\n", $colpevoli));
    }
}
