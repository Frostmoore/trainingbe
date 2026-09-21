<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Le impostazioni della palestra — B3.2.
 *
 * 🚨 **Cosa NON c'e', ed e' il punto della pagina.**
 * Stato dell'abbonamento, piano commerciale e tetto mensile di token AI **non**
 * compaiono: sono i termini del contratto con noi, e si toccano solo dal
 * pannello di piattaforma. Un cliente che si toglie da solo la sospensione o si
 * alza il tetto di consumo non e' un cliente, e' un problema di fatturazione.
 *
 * Il controllo non e' solo l'assenza dei campi — `TenantPolicy::update()` dice
 * la stessa cosa, e un campo aggiunto per distrazione qui non basterebbe a
 * scavalcarla.
 */
class GymSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Impostazioni';

    protected static ?string $title = 'Impostazioni della palestra';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.gym.pages.gym-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isGymAdmin() === true;
    }

    public function mount(): void
    {
        $this->form->fill($this->palestra()?->only([
            'name', 'contact_email', 'logo_path',
            'color_primary', 'color_secondary', 'color_accent',

            // 💶 F5.1 — la quota che la palestra fa ai suoi (D9).
            'prezzo_per_allievo_cent',
        ]) ?? []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([

                Section::make('La palestra')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nome')->required()->maxLength(120),
                        TextInput::make('contact_email')->label('Email di contatto')->email()->maxLength(255),
                    ]),

                /*
                 * ══ 💶 LA QUOTA CHE FATE PAGARE VOI — 23/09/2026, F5.1 ══════
                 *
                 * ✅ **D9**: *«lo scrive la palestra e noi non lo verifichiamo
                 * nemmeno. Sono dati suoi e a noi non interessano»*.
                 *
                 * 💡 Serve **solo a loro**: è l'unico numero che manca per
                 * rispondere a *«questo iscritto mi conviene?»*. ⛔ Senza, il
                 * cruscotto può mostrare solo il costo — metà del conto, quella
                 * che fa sembrare il servizio una spesa e basta.
                 *
                 * 🚨 **In centesimi in tabella, in euro a schermo.** ⚠️ La
                 * conversione sta qui e non nel modello: l'euro è il modo in cui
                 * la gente scrive i soldi, il centesimo è il modo in cui si
                 * contano senza perderne uno per strada.
                 */
                Section::make('La tua quota')
                    ->description(
                        'Quanto fai pagare al mese a un iscritto. Lo usi solo tu, nel tuo cruscotto: '
                        .'noi non lo vediamo, non lo verifichiamo e non lo usiamo per fatturare niente.'
                    )
                    ->schema([
                        TextInput::make('prezzo_per_allievo_cent')
                            ->label('Quota mensile per iscritto')
                            ->numeric()
                            ->prefix('€')
                            ->minValue(0)
                            ->maxValue(9999)
                            ->step('0.01')
                            ->helperText('Puoi lasciarlo vuoto: il cruscotto mostrera\' solo il costo.')
                            /*
                             * ⚠️ `null` resta `null`, e non diventa zero:
                             * «non l'ho scritto» e «li alleno gratis» sono due
                             * cose diverse, e il cruscotto le dice in due modi
                             * diversi.
                             */
                            ->formatStateUsing(fn (?int $state): ?string => $state === null
                                ? null
                                : number_format($state / 100, 2, '.', ''))
                            ->dehydrateStateUsing(fn (?string $state): ?int => filled($state)
                                ? (int) round(((float) $state) * 100)
                                : null),
                    ]),

                Section::make('Aspetto nell\'app')
                    ->description('E\' quello che vedono i tuoi iscritti quando aprono l\'app.')
                    ->columns(3)
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->directory('loghi')
                            ->columnSpanFull(),

                        ColorPicker::make('color_primary')->label('Primario')->required(),
                        ColorPicker::make('color_secondary')->label('Secondario')->required(),
                        ColorPicker::make('color_accent')->label('Accento')->required(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $palestra = $this->palestra();

        if ($palestra === null) {
            return;
        }

        $this->authorize('update', $palestra);

        $palestra->update($this->form->getState());

        Notification::make()
            ->title('Impostazioni salvate')
            ->body('I nuovi colori arrivano subito nell\'app degli iscritti.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('salva')
                ->label('Salva')
                ->submit('save'),

            /*
             * 🚨 Rigenerare il codice invalida quello gia' distribuito.
             *
             * E' l'azione che serve quando il codice e' finito dove non doveva —
             * un volantino, una chat di gruppo — e per questo esiste; ma chi non
             * si e' ancora iscritto con il vecchio resta fuori, quindi va
             * chiesta conferma e va lasciata traccia.
             */
            Action::make('rigenera_codice')
                ->label('Rigenera codice d\'invito')
                ->icon('heroicon-m-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Rigenerare il codice d\'invito?')
                ->modalDescription(
                    'Il codice attuale smettera\' di funzionare. Chi lo ha gia\' ricevuto e non si e\' '
                    .'ancora iscritto non potra\' piu\' usarlo.'
                )
                ->action(function (): void {
                    $palestra = $this->palestra();

                    if ($palestra === null) {
                        return;
                    }

                    $this->authorize('update', $palestra);

                    $vecchio = $palestra->join_code;
                    $palestra->update(['join_code' => Tenant::generateJoinCode()]);

                    app(AuditLogger::class)->log(
                        AuditAction::JoinCodeRegenerated,
                        $palestra,
                        ['precedente' => $vecchio],
                        tenant: $palestra,
                    );

                    Notification::make()
                        ->title('Nuovo codice: '.$palestra->join_code)
                        ->warning()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    public function getSubheading(): string|Htmlable|null
    {
        $palestra = $this->palestra();

        return $palestra !== null
            ? 'Codice d\'invito attuale: '.$palestra->join_code
            : null;
    }

    private function palestra(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }
}
