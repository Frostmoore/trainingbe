<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Enums\UserRole;
use App\Models\LiberatoriaFirmata;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ✍️ **Chi deve firmare cosa, e se l'ha già firmato** — art. 28 GDPR, 20/09/2026.
 *
 * 📌 Il committente, 20/09: *«Ruolo della palestra responsabile, dovrà firmare
 * la liberatoria specifica per le palestre (idem per i trainer)»*.
 *
 * ── 🚨 Perché serve un atto, e non una riga nelle condizioni d'uso ─────────
 *
 * Palestre e trainer **vedono i dati di altre persone**. L'art. 28(3) vuole che
 * quel rapporto sia regolato per iscritto **prima** che cominci: senza, chi
 * guarda l'elenco degli iscritti lo guarda senza titolo. ⛔ E non è un dettaglio
 * formale: è la differenza fra un trattamento lecito e uno che non lo è.
 *
 * ── 💡 La versione fa parte della firma ───────────────────────────────────
 *
 * Il testo cambierà — il legale lo rivedrà, e questa è ancora una bozza. Si
 * conserva **quale versione** è stata accettata: quando il testo cambia, chi
 * l'aveva firmato lo rifirma. ⚠️ Un'accettazione «una volta per sempre» di un
 * documento che cambia non dimostrerebbe niente.
 */
final class LeLiberatorie
{
    public const PALESTRA = 'palestra';

    public const TRAINER = 'trainer';

    /**
     * La versione **in vigore** di ogni testo.
     *
     * 🚨 Alzarla obbliga tutti a rifirmare: si fa quando il testo cambia in
     * modo sostanziale, non per una virgola.
     */
    public const VERSIONI = [
        self::PALESTRA => 'palestra-v1',
        self::TRAINER => 'trainer-v1',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Quale liberatoria manca a questa persona, o `null` se è a posto.
     *
     * ⚠️ **L'ordine conta**: chi amministra una palestra **ed è anche** trainer
     * firma prima quella della palestra, che è la più larga.
     */
    public function serveA(User $persona): ?string
    {
        return $this->context->runWithoutTenant(function () use ($persona): ?string {
            foreach ([self::PALESTRA, self::TRAINER] as $tipo) {
                if ($this->riguarda($persona, $tipo) && ! $this->haFirmato($persona, $tipo)) {
                    return $tipo;
                }
            }

            return null;
        });
    }

    /**
     * Se una persona con questo ruolo debba firmare quel testo.
     *
     * 🚨 **I ruoli si leggono dalla pivot, non dal modello.** `isGymAdmin()` e
     * compagni passano da spatie, che tiene in cache i ruoli **del tenant
     * corrente**: chiamarli qui — fuori dal tenant, come serve a noi — lascia
     * quella cache sporca, e il codice che viene dopo vede una persona **senza
     * ruoli**. ⚠️ È successo davvero: ha fatto cadere 17 test che non parlavano
     * di liberatorie.
     */
    public function riguarda(User $persona, string $tipo): bool
    {
        $this->controllaIlTipo($tipo);

        $ruoli = $tipo === self::PALESTRA
            ? [UserRole::GymAdmin->value]
            : [UserRole::Trainer->value, UserRole::FreeTrainer->value];

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $persona->getKey())
            ->where('model_has_roles.model_type', $persona->getMorphClass())
            ->whereIn('roles.name', $ruoli)
            ->exists();
    }

    /** Se ha già firmato **la versione in vigore** di quel testo. */
    public function haFirmato(User $persona, string $tipo): bool
    {
        $this->controllaIlTipo($tipo);

        return LiberatoriaFirmata::query()
            ->where('user_id', $persona->getKey())
            ->where('tipo', $tipo)
            ->where('versione', self::VERSIONI[$tipo])
            ->exists();
    }

    /**
     * Registra la firma. Ripremere il pulsante non crea un secondo atto.
     */
    public function firma(User $persona, string $tipo, ?Request $richiesta = null): LiberatoriaFirmata
    {
        $this->controllaIlTipo($tipo);

        return LiberatoriaFirmata::query()->firstOrCreate(
            [
                'user_id' => $persona->getKey(),
                'tipo' => $tipo,
                'versione' => self::VERSIONI[$tipo],
            ],
            [
                'tenant_id' => $persona->tenant_id,
                'accettata_il' => now(),
                'ip' => $richiesta?->ip(),

                // ⚠️ Tagliato: la colonna è 255, e certi browser scrivono di più.
                'user_agent' => mb_substr((string) $richiesta?->userAgent(), 0, 255) ?: null,
            ],
        );
    }

    /**
     * Il testo da mostrare, in markdown.
     *
     * 💡 Da `resources/legal`, come l'informativa: il testo che la persona legge
     * sta in un file, non dentro una schermata — così lo si cambia senza
     * ripubblicare l'app, e c'è **una** copia sola da far leggere al legale.
     */
    public function testo(string $tipo): string
    {
        $this->controllaIlTipo($tipo);

        $file = resource_path("legal/liberatoria-{$tipo}.md");

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    private function controllaIlTipo(string $tipo): void
    {
        if (! array_key_exists($tipo, self::VERSIONI)) {
            throw new InvalidArgumentException("Liberatoria sconosciuta: {$tipo}.");
        }
    }
}
