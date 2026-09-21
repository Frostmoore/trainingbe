<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Tables;

use App\Enums\PlanKind;
use App\Enums\TenantKind;
use App\Filament\God\Resources\Plans\PianoSuMisuraResource;
use App\Filament\God\Resources\Plans\PlanResource;
use App\Filament\Support\VoceDiScheda;
use App\Models\OffertaDiPiano;
use App\Models\Plan;
use App\Models\RichiestaDiContatto;
use App\Models\Tenant;
use App\Services\Billing\PianoCucitoAMano;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 💶 **L'elenco dei piani** — rifatto il 23/09/2026, fase F9.1.
 *
 * 📌 Il committente: *«Qui non si capisce un cazzo, falla bene · ci devono
 * essere tutti i piani veri · Nei piani per le persone "iscritti" deve essere
 * 0, non senza limiti, idem per trainer · a listino non so cosa sia · clienti
 * immagino sia "quanti ce l'hanno attivo" quindi chiamiamolo "attivi"»*.
 *
 * | Prima | Adesso |
 * |---|---|
 * | tabella con codice, nome, «a listino», «clienti» | **schede**: nome, per chi, prezzo con la sua temporalità, posti, **In vendita**, **Attivi** |
 * | «senza tetto» anche dove il posto non esiste | persona: iscritti **0**, trainer **0**; trainer: trainer **0** |
 * | «Clienti» = tutti gli abbonamenti mai fatti | «Attivi» = solo quelli **in corso** (`PlanSubscription::attivi()`) |
 * | listino e su misura mescolati | due pagine: «Piani e listino» e «Offerte su misura» → «Piani su misura» |
 */
class PlansTable
{
    /**
     * Lo stesso elenco per le due pagine. `$suMisura` sceglie quali piani, e
     * accende le due azioni che servono solo ai piani cuciti a mano.
     */
    public static function configure(Table $table, bool $suMisura = false): Table
    {
        return $table
            ->recordUrl(null)
            ->contentGrid(['sm' => 1, 'md' => 2, 'xl' => 3])
            // ⚠️ `$query`, non `$q`: Filament passa i parametri per NOME, e con un
            // nome che non conosce costruisce un Builder vuoto dal container.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('cucito_a_mano', $suMisura))
            ->columns([
                Stack::make([
                    TextColumn::make('name')
                        ->label('Nome')
                        ->weight('bold')
                        ->searchable()
                        ->description(fn (Plan $p): string => self::perChi($p).' · '.$p->code),

                    VoceDiScheda::make('Prezzo', TextColumn::make('prezzo')
                        ->label('Prezzo')
                        ->state(fn (Plan $p): string => self::prezzo($p))),

                    VoceDiScheda::make('Iscritti', TextColumn::make('posti_iscritto')
                        ->label('Iscritti')
                        ->state(fn (Plan $p): string => self::posti($p, 'iscritti'))),

                    VoceDiScheda::make('Trainer', TextColumn::make('posti_trainer')
                        ->label('Trainer')
                        ->state(fn (Plan $p): string => self::posti($p, 'trainer'))),

                    VoceDiScheda::make('In vendita', TextColumn::make('is_public')
                        ->label('In vendita')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'sì, sul sito e nel pannello' : 'no, solo con un link')
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray')),

                    // 📌 *«clienti immagino sia "quanti ce l'hanno attivo" quindi chiamiamolo "attivi"»*.
                    // ⚠️ Solo quelli in corso (`attivi()`): «Clienti» contava anche gli abbonamenti finiti.
                    VoceDiScheda::make('Attivi', TextColumn::make('attivi')
                        ->label('Attivi')
                        ->state(fn (Plan $p): int => $p->subscriptions()->attivi()->count())),
                ]),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Per chi')
                    ->options([
                        PlanKind::Gym->value => 'Palestra',
                        PlanKind::Trainer->value => 'Trainer',
                        PlanKind::Person->value => 'Persona',
                    ]),
            ])
            ->recordActions([
                Action::make('apri')
                    ->label('Apri')
                    ->icon('heroicon-m-arrow-right')
                    ->button()
                    ->outlined()
                    ->url(fn (Plan $p): string => $suMisura
                        ? PianoSuMisuraResource::getUrl('edit', ['record' => $p])
                        : PlanResource::getUrl('edit', ['record' => $p])),

                self::linkDiPagamento()->button()->outlined()->visible($suMisura),

                self::attivaSenzaPagamento()->button()->outlined()->visible($suMisura),
            ])
            ->toolbarActions([])
            // I piani sono una ventina: tutti in una pagina, senza sfogliare.
            ->paginated(false)
            ->defaultSort('price_cents')
            ->emptyStateHeading($suMisura ? 'Nessun piano su misura' : 'Nessun piano')
            ->emptyStateDescription($suMisura
                ? 'Qui si cuciono i piani per un cliente solo: prezzo, posti e temporalità concordati.'
                : 'Il listino lo scrive PlanSeeder al deploy; quelli che modifichi qui non li tocca più.');
    }

    /**
     * 🔗 **Il link di pagamento** — 21/09/2026.
     *
     * 📌 *«certo che il piano cucito a mano deve essere fatto con stripe»*. Si
     * crea un link, il cliente lo apre e paga su Stripe come da qualunque altro
     * piano.
     */
    public static function linkDiPagamento(): Action
    {
        return Action::make('manda_il_link')
            ->label('Link di pagamento')
            ->icon('heroicon-m-link')
            ->color('primary')
            ->modalHeading(fn (Plan $record): string => "Link di pagamento per «{$record->name}»")
            ->modalDescription(
                'Chi apre il link vede il piano e paga su Stripe. Il piano resta fuori '
                .'vendita: lo compra solo chi ha questo indirizzo.'
            )
            ->modalSubmitActionLabel('Crea il link')
            ->schema([
                Select::make('tenant_id')
                    ->label('Cliente')
                    ->options(fn (Plan $record): array => self::clientiPossibili($record))
                    ->searchable()
                    ->placeholder('Non è ancora cliente')
                    ->helperText(
                        'Se lo scegli, il link vale solo per lui. ⚠️ Lascialo vuoto per chi non '
                        .'ha ancora uno spazio: si iscrive dal link stesso.'
                    ),

                TextInput::make('email')
                    ->label('Email a cui lo mandi')
                    ->email()
                    ->helperText('Facoltativa: serve a ritrovare l\'offerta, e a precompilare il modulo.'),

                // 💡 Chiude il giro: scrivono dal modulo → si concorda → si manda il link.
                Select::make('richiesta_di_contatto_id')
                    ->label('Nasce da una richiesta')
                    ->options(fn (): array => RichiestaDiContatto::query()
                        ->latest()
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (RichiestaDiContatto $r): array => [
                            $r->getKey() => $r->nome.($r->organizzazione !== null ? " — {$r->organizzazione}" : ''),
                        ])
                        ->all())
                    ->searchable()
                    ->placeholder('Nessuna'),

                DateTimePicker::make('scade_il')
                    ->label('Vale fino al')
                    ->default(now()->addDays(30))
                    ->helperText(
                        '⚠️ Vuoto = non scade mai, ed è la scelta peggiore: un prezzo concordato '
                        .'oggi resterebbe comprabile fra due anni.'
                    ),
            ])
            // ⚠️ `$data` e non `$dati`: Filament risolve gli argomenti per nome.
            ->action(function (Plan $record, array $data): void {
                $offerta = OffertaDiPiano::create([
                    'plan_id' => $record->getKey(),
                    'token' => OffertaDiPiano::nuovoToken(),
                    'tenant_id' => $data['tenant_id'] ?? null,
                    'email' => $data['email'] ?? null,
                    'richiesta_di_contatto_id' => $data['richiesta_di_contatto_id'] ?? null,
                    'creata_da_id' => auth()->id(),
                    'scade_il' => $data['scade_il'] ?? null,
                ]);

                // ⚠️ `persistent()`: il link è l'unica cosa che conta; si ritrova in «Link di pagamento».
                Notification::make()
                    ->title('Link creato')
                    ->body($offerta->link())
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * ⚠️ **L'assegnazione a mano resta, ma non è la strada normale.** Serve a
     * chi paga per bonifico o con fattura emessa fuori. ⛔ Accende un servizio
     * che nessuno fattura: è scritto sul pulsante, e chiede conferma.
     */
    public static function attivaSenzaPagamento(): Action
    {
        return Action::make('assegna')
            ->label('Attiva senza pagamento')
            ->icon('heroicon-m-exclamation-triangle')
            ->color('danger')
            ->modalHeading(fn (Plan $record): string => "Attiva «{$record->name}» senza passare da Stripe")
            ->modalDescription(
                '⛔ Nessuno fatturerà questo piano: usala solo se il cliente paga per bonifico '
                .'o con fattura emessa fuori. Per farlo pagare, usa il link.'
            )
            ->modalSubmitActionLabel('Attiva comunque')
            ->schema([
                Select::make('tenant_id')
                    ->label('Cliente')
                    ->options(fn (Plan $record): array => self::clientiPossibili($record))
                    ->searchable()
                    ->required()
                    ->helperText('Solo i clienti della specie giusta: un piano da palestra va a una palestra.'),

                DateTimePicker::make('da')
                    ->label('A partire da')
                    ->helperText('Vuoto = adesso. Serve per far partire un accordo dal primo del mese.'),
            ])
            ->action(function (Plan $record, array $data): void {
                $tenant = Tenant::query()->findOrFail($data['tenant_id']);

                try {
                    app(PianoCucitoAMano::class)->assegna(
                        $tenant,
                        $record,
                        isset($data['da']) && $data['da'] !== null
                            ? Carbon::parse((string) $data['da'])
                            : null,
                    );
                } catch (Throwable $e) {
                    // ⚠️ Il messaggio si mostra: qui chi legge è il super admin, e deve sapere perché.
                    Notification::make()
                        ->title('Non assegnato')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Piano attivato senza pagamento')
                    ->body("{$tenant->name} adesso è su «{$record->name}». ⛔ Nessuno lo fatturerà.")
                    ->warning()
                    ->send();
            });
    }

    /** «Palestra», «Trainer», «Persona». */
    public static function perChi(Plan $p): string
    {
        return match ($p->kind) {
            PlanKind::Gym => 'Palestra',
            PlanKind::Trainer => 'Trainer',
            PlanKind::Person => 'Persona',
        };
    }

    /** «130,00 € al mese», oppure «4,00 € a iscritto + 15,00 € al mese (minimo 30)». */
    public static function prezzo(Plan $p): string
    {
        if ($p->aConsumo()) {
            return self::euro((int) $p->prezzo_iscritto_cent).' a iscritto'
                .($p->canone_cent > 0 ? ' + '.self::euro((int) $p->canone_cent).' al mese' : ' al mese')
                .($p->minimo_iscritti ? ' (minimo '.$p->minimo_iscritti.')' : '');
        }

        if ((int) $p->price_cents === 0) {
            return 'gratis';
        }

        return self::euro((int) $p->price_cents).' '.$p->perIntervallo();
    }

    /**
     * I posti, detti giusti.
     *
     * 📌 *«Nei piani per le persone "iscritti" deve essere 0, non senza
     * limiti, idem per trainer»*. 💡 `null` vuol dire «senza tetto» solo dove
     * il posto **esiste**: una persona non ha né iscritti né trainer, e un
     * trainer non ha altri trainer — lì il numero vero è **zero**.
     *
     * @param  'iscritti'|'trainer'  $quali
     */
    public static function posti(Plan $p, string $quali): string
    {
        if ($p->kind === PlanKind::Person || ($quali === 'trainer' && $p->kind === PlanKind::Trainer)) {
            return '0';
        }

        $n = $quali === 'iscritti' ? $p->max_members : $p->max_trainers;

        return $n === null ? 'senza limiti' : (string) $n;
    }

    /**
     * I clienti a cui questo piano si può dare.
     *
     * 🚨 Il filtro sta **anche** qui e non solo in `PianoCucitoAMano`: una
     * tendina che elenca duemila spazi personali sotto un piano da palestra è un
     * invito a sbagliare.
     *
     * @return array<int, string>
     */
    private static function clientiPossibili(Plan $piano): array
    {
        $query = Tenant::query()->orderBy('name');

        $query = $piano->kind === PlanKind::Gym
            ? $query->where('kind', TenantKind::Gym->value)
            : $query->where('kind', TenantKind::Personal->value);

        return $query->pluck('name', 'id')->all();
    }

    public static function euro(int $centesimi): string
    {
        return number_format($centesimi / 100, 2, ',', '.').' €';
    }
}
