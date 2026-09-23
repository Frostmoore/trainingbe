<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members;

use App\Enums\UserRole;
use App\Filament\Gym\Concerns\ScopedToTrainer;
use App\Filament\Gym\Resources\Members\Pages\CreateMember;
use App\Filament\Gym\Resources\Members\Pages\ListMembers;
use App\Filament\Gym\Resources\Members\Pages\ViewMember;
use App\Filament\Gym\Resources\Members\Schemas\MemberForm;
use App\Filament\Gym\Resources\Members\Schemas\SchedaDellIscritto;
use App\Filament\Gym\Resources\Members\Tables\MembersTable;
use App\Filament\Gym\Support\ChiPossoSeguire;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * Gli iscritti della palestra — B3.4.
 *
 * 🚨 **Due filtri sovrapposti, e servono entrambi:**
 *  - il global scope della tenancy limita alla palestra;
 *  - 🆕 `limitaAgliAssegnati()` chiude fuori chi non è né gestore né trainer.
 *
 * 🆕 ⚠️ **Dal 16/09/2026 il secondo non limita più un trainer ai propri
 * assegnati**: vede tutta la palestra, come già la vede nell'app. ⚖️ La
 * protezione che B3.5 cercava — *un trainer che se ne va non porta via
 * l'elenco clienti* — non era nella **vista** ma nel **poterci fare qualcosa**,
 * e quella sta in `UserPolicy` (crea/modifica/cancella: solo il gestore) e in
 * D4 (dal pannello non si assegna nessuna scheda a nessuno).
 *
 * C'e' un terzo filtro implicito: solo chi ha il ruolo `member`. Senza,
 * l'elenco degli iscritti conterrebbe anche i trainer e l'amministratore.
 */
class MemberResource extends Resource
{
    use ScopedToTrainer;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'iscritto';

    protected static ?string $pluralModelLabel = 'iscritti';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $utente = auth()->user();

        /*
         * ══ 🏋️ IL TRAINER INDIPENDENTE VEDE I **SUOI**, OVUNQUE ABITINO ═══
         *
         * 📌 F10, 23/09/2026 — il committente: *«un free trainer potrà agire
         * sostanzialmente come una palestra»*.
         *
         * 🚨 **Non si può passare da `idConRuolo(Member)`**, che è la strada di
         * una palestra: i suoi allievi non stanno tutti nel suo spazio, e chi
         * aveva già un account resta a casa propria.
         *
         * 💡 **La regola per esteso sta in `ChiPossoSeguire`**, e non più qui:
         * il 24/09 il committente ha chiesto *«sei sicuro che l'import da pdf
         * funzioni qui?»* e la risposta era no —
         * `PlanImportResource::iscritti()` aveva **una seconda copia** di questa
         * stessa regola, quella vecchia, e a una trainer con due allievi ne
         * offriva uno. ⛔ Una regola che serve in due posti va scritta in
         * nessuno dei due.
         */
        if ($utente instanceof User && $utente->isFreeTrainer()) {
            return ChiPossoSeguire::query()
                ->withoutGlobalScopes([SoftDeletingScope::class, TenantScope::class]);
        }

        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereIn('id', static::idConRuolo(UserRole::Member));

        return static::limitaAgliAssegnati($query);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        // Anche il binding di rotta passa dagli stessi filtri: senza, un id
        // scritto a mano nell'URL aprirebbe la scheda di un iscritto che non e'
        // di questo trainer.
        return static::getEloquentQuery();
    }

    /**
     * Gli id degli utenti con un dato ruolo **nella palestra corrente**.
     *
     * Non `->role()` di spatie: quella relazione filtra sul team corrente e
     * funzionerebbe, ma genera una sottoquery diversa a ogni versione del
     * pacchetto. Una `whereIn` esplicita e' leggibile e non cambia sotto i piedi.
     */
    protected static function idConRuolo(UserRole $ruolo): \Illuminate\Database\Query\Builder
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', $ruolo->value)
            ->where('model_has_roles.tenant_id', app(TenantContext::class)->id())
            ->select('model_has_roles.model_id');
    }

    public static function form(Schema $schema): Schema
    {
        return MemberForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    /**
     * ⛔ **Niente schede dentro la pagina dell'iscritto** — 22/09/2026, F5.14.
     *
     * 📌 R2: *«Le schede vivono sui telefoni. Non ci sono destinatari.»*
     *
     * 🚨 Questo riquadro faceva esattamente la cosa che R2 vieta: mostrava
     * *«questa persona segue questo programma»*, e da lì permetteva di
     * scriverne uno nuovo già legato a lei. ⛔ Da un programma post-infortunio
     * si capisce cos'è successo a chi lo esegue, e quel legame sul server non
     * lo teniamo.
     *
     * 💡 La domanda *«che scheda ha questa persona?»* non ha più risposta sul
     * server — **di proposito**. Ce l'ha il suo telefono.
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * 👤 **La scheda si guarda, non si compila** — 23/09/2026, F5.7.
     *
     * ⛔ **La pagina di modifica non c'è più.** Permetteva alla palestra di
     * cambiare nome, email e **password** di una persona: cambiare l'email è
     * prendere il controllo dell'account (il reset arriva lì), e scegliere la
     * password è poter entrare al posto suo. 💡 Il perché per esteso sta in
     * `SchedaDellIscritto`.
     */
    public static function infolist(Schema $schema): Schema
    {
        return SchedaDellIscritto::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'view' => ViewMember::route('/{record}'),
        ];
    }
}
