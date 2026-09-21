<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Tenancy\ChiSiVede;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\TogliUnTrainerDallaPalestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🚪 Congedare un trainer che ha un giro suo — 3b-AJ.6, 16/09/2026.
 *
 * ══ 📌 LA REGOLA, DETTA DAL COMMITTENTE ═══════════════════════════════════
 *
 * > *«Le schede restano sia dell'allievo che del trainer. Il trainer le ha
 * > scritte, mi pare ridicolo togliergliele; l'allievo le ha pagate, mi pare un
 * > furto togliergliele.»*
 *
 * ══ ⛔ COSA C'ERA PRIMA ═══════════════════════════════════════════════════
 *
 * **Niente.** La palestra aveva solo *spegni l'account* e *cancella l'account*,
 * che stanno sull'account e non sul rapporto con quella palestra. 🚨
 * `UserPolicy` glielo impediva già — e quindi la palestra non poteva **nemmeno
 * mandarlo via**.
 */
class CongedareUnTrainerTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private function congeda(User $trainer, Tenant $palestra): void
    {
        app(TogliUnTrainerDallaPalestra::class)($trainer, $palestra);
    }

    private function allenaAnchePresso(User $chi, Tenant $palestra): void
    {
        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => DB::table('roles')->where('name', UserRole::Trainer->value)->value('id'),
            'model_type' => User::class,
            'model_id' => $chi->getKey(),
            'tenant_id' => $palestra->getKey(),
        ], []);
    }

    private function unTrainerIndipendente(): User
    {
        return app(CreaTenantPersonale::class)(
            'Coach',
            'coach@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );
    }

    /** Un allievo personale, col rapporto uno-a-uno nello spazio del trainer. */
    private function allievoDi(User $trainer, string $email): User
    {
        $allievo = app(CreaTenantPersonale::class)('Allievo', $email, ['password' => self::FAKE_PASSWORD]);

        DB::table('users')->where('id', $allievo->getKey())->update(['tenant_id' => $trainer->tenant_id]);

        DB::table('trainer_member')->insert([
            'trainer_id' => $trainer->getKey(),
            'member_id' => $allievo->getKey(),
            'tenant_id' => $trainer->tenant_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($allievo->getKey());
    }

    // ─────────────── 🚪 Il congedo ───────────────

    /** Non allena più lì, e gli iscritti della palestra non li vede più. */
    #[Test]
    public function dopo_il_congedo_non_allena_piu_in_quella_palestra(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');
        $this->allenaAnchePresso($trainer, $palestra);

        $this->congeda($trainer->fresh(), $palestra);

        $fresco = User::withoutGlobalScopes()->findOrFail($trainer->getKey());

        $this->assertSame(
            [$trainer->tenant_id],
            app(ChiSiVede::class)->spaziDi($fresco)->all(),
            'Dopo il congedo la palestra e ancora uno dei suoi spazi.',
        );

        $this->assertSame(
            [],
            app(ChiSiVede::class)->allieviDi($fresco)->pluck('id')->all(),
            'Dopo il congedo vede ancora gli iscritti della palestra.',
        );
    }

    /**
     * 🚨 **Ma i suoi allievi personali restano suoi.**
     *
     * 📌 Era la prima garanzia chiesta il 15/09, e qui passa da una porta nuova:
     * un congedo che glieli chiudesse sarebbe lo stesso difetto
     * dell'osservatore, rifatto altrove.
     */
    #[Test]
    public function i_suoi_allievi_personali_non_si_toccano(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $suo = $this->allievoDi($trainer, 'suo@esempio.test');
        $this->allenaAnchePresso($trainer, $palestra);

        $this->congeda($trainer->fresh(), $palestra);

        $rapporto = DB::table('trainer_member')
            ->where('trainer_id', $trainer->getKey())
            ->where('member_id', $suo->getKey())
            ->first();

        $this->assertNotNull($rapporto);
        $this->assertNull($rapporto->disattivato_il, 'Il congedo gli ha chiuso anche gli allievi suoi.');

        $this->assertSame(
            [$suo->getKey()],
            app(ChiSiVede::class)->allieviDi($trainer->fresh())->pluck('id')->all(),
            'Dopo il congedo non vede piu i suoi allievi.',
        );
    }

    // ─────────────── 📄 Le schede ───────────────

    /**
     * 📌 *«Il trainer le ha scritte, mi pare ridicolo togliergliele.»*
     *
     * 🚨 **Il difetto vero**: una scheda scritta dal pannello mentre si lavora in
     * palestra nasce col `tenant_id` **della palestra** — `BelongsToTenant` lo
     * riempie dal contesto. ⛔ Senza questo passaggio, il congedo gliela toglieva.
     */
    #[Test]
    public function si_porta_a_casa_le_schede_che_ha_scritto(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();
        $this->allenaAnchePresso($trainer, $palestra);

        // ✍️ La scrive stando nella palestra: nasce lì.
        $sua = $this->ctx()->runAs($palestra, fn (): WorkoutPlan => WorkoutPlan::create([
            'created_by' => $trainer->getKey(),
            'name' => 'Forza base',
        ]));

        $this->assertSame(
            $palestra->getKey(),
            $sua->tenant_id,
            'La scheda non nasceva nella palestra: il test non proverebbe niente.',
        );

        $this->congeda($trainer->fresh(), $palestra);

        $this->assertSame(
            $trainer->tenant_id,
            WorkoutPlan::withoutGlobalScopes()->findOrFail($sua->getKey())->tenant_id,
            'Congedandolo, la palestra si e tenuta la scheda che ha scritto lui.',
        );
    }

    /**
     * ⚖️ **E non si porta via quelle degli altri.**
     *
     * 🚨 Due condizioni, e servono tutte e due: la scheda di un **collega** resta
     * dov'è, e quella che un **iscritto si è scritto da solo** — `member_id`
     * valorizzato — pure. 📌 *«L'allievo le ha pagate, mi pare un furto
     * togliergliele.»*
     */
    #[Test]
    public function ma_non_quelle_degli_altri(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();
        $collega = $this->creaUtente($palestra, UserRole::Trainer, 'collega@olimpo.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $this->allenaAnchePresso($trainer, $palestra);

        [$delCollega, $dellIscritto] = $this->ctx()->runAs($palestra, fn (): array => [
            WorkoutPlan::create(['created_by' => $collega->getKey(), 'name' => 'Scheda del collega']),
            WorkoutPlan::create([
                'created_by' => $iscritto->getKey(),
                'member_id' => $iscritto->getKey(),
                'name' => 'Se la scrive da solo',
            ]),
        ]);

        $this->congeda($trainer->fresh(), $palestra);

        $this->assertSame(
            $palestra->getKey(),
            WorkoutPlan::withoutGlobalScopes()->findOrFail($delCollega->getKey())->tenant_id,
            'Si e portato via la scheda di un collega.',
        );

        $this->assertSame(
            $palestra->getKey(),
            WorkoutPlan::withoutGlobalScopes()->findOrFail($dellIscritto->getKey())->tenant_id,
            'Si e portato via la scheda che un iscritto si era scritto da solo.',
        );
    }

    /**
     * ⚖️ **E le schede che si porta via restano leggibili.**
     *
     * 🚨 Parlano di esercizi **della palestra**, che alla palestra restano. ⛔
     * Senza il permesso di leggerli si ritroverebbe righe mute: non cancellate,
     * **peggio**.
     */
    #[Test]
    public function la_libreria_esercizi_resta_leggibile(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();
        $this->allenaAnchePresso($trainer, $palestra);

        $this->congeda($trainer->fresh(), $palestra);

        $this->assertContains(
            $palestra->getKey(),
            User::withoutGlobalScopes()->findOrFail($trainer->getKey())->librerie_viste ?? [],
            'Le schede che si e portato via parlano di esercizi che non puo piu leggere.',
        );
    }

    // ─────────────── ⛔ Chi non si congeda così ───────────────

    /**
     * ⛔ **Un trainer che vive dentro la palestra non ha un posto dove tornare.**
     *
     * ⚠️ Congedarlo così lo lascerebbe con un account senza ruoli, dentro uno
     * spazio che non è suo. 💡 Il messaggio dice cosa fare invece, perché a
     * leggerlo è una persona davanti a un pannello.
     */
    #[Test]
    public function chi_non_ha_uno_spazio_suo_non_si_congeda_cosi(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $interno = $this->creaUtente($palestra, UserRole::Trainer, 'interno@olimpo.test');

        $this->expectException(ValidationException::class);

        $this->congeda($interno, $palestra);
    }

    /** ⛔ E nemmeno chi in quella palestra non ha mai allenato. */
    #[Test]
    public function chi_non_allena_li_non_si_congeda(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $estraneo = $this->unTrainerIndipendente();

        $this->expectException(ValidationException::class);

        $this->congeda($estraneo, $palestra);
    }

    /**
     * 🚨 **Tutti i ruoli in quel tenant, non solo `trainer`.**
     *
     * 💡 Se gliene fosse stato dato un secondo e qui si togliesse solo il primo,
     * resterebbe dentro con l'altro: un congedo che non congeda.
     */
    #[Test]
    public function togliere_significa_tutti_i_ruoli_di_quella_palestra(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $this->allenaAnchePresso($trainer, $palestra);

        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => DB::table('roles')->where('name', UserRole::GymAdmin->value)->value('id'),
            'model_type' => User::class,
            'model_id' => $trainer->getKey(),
            'tenant_id' => $palestra->getKey(),
        ], []);

        $this->congeda($trainer->fresh(), $palestra);

        $this->assertSame(
            0,
            DB::table('model_has_roles')
                ->where('model_id', $trainer->getKey())
                ->where('tenant_id', $palestra->getKey())
                ->count(),
            'Gli e rimasto un ruolo dentro la palestra che l ha congedato.',
        );
    }
}
