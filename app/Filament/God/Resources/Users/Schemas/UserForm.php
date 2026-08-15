<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Schemas;

use App\Models\User;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Billing\PianoAttivo;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Il modulo dell'utente visto dalla piattaforma.
 *
 * 🚨 **Cosa NON c'e', ed e' voluto:**
 *
 * - **la palestra.** Spostare una persona da una palestra all'altra non e' una
 *   modifica di campo: si porterebbe dietro schede, diario e ruoli, che sono
 *   legati al vecchio `tenant_id`. Finche' non esiste una procedura che sposta
 *   *tutto*, il campo resta in sola lettura — un menu a tendina qui creerebbe
 *   in un click un utente con dati orfani.
 * - **i ruoli.** Sono per palestra (spatie in modalita' teams) e si assegnano
 *   dal pannello della palestra, dove il contesto e' quello giusto. Da qui si
 *   vedono soltanto.
 * - **`is_super_admin`.** Non e' fillable di proposito (vedi `User`): si concede
 *   da seeder o da console. Un interruttore in un modulo web e' esattamente il
 *   punto da cui una scalata di privilegi comincia.
 * - **la password.** Non si cambia la password di un cliente: si manda il
 *   ripristino. Conoscerla e' un problema, non un servizio.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Identita')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Unica **per palestra**, non in assoluto: la stessa
                        // persona puo' essere iscritta a due palestre.
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule, ?User $record) => $rule->where(
                                'tenant_id',
                                $record?->tenant_id,
                            ),
                        ),

                    TextInput::make('username')
                        ->label('Nome utente')
                        ->maxLength(30)
                        ->unique(ignoreRecord: true)
                        ->helperText('Unico su tutta la piattaforma: si usa per entrare al posto dell\'email.'),

                    TextInput::make('phone')
                        ->label('Telefono')
                        ->tel()
                        ->maxLength(32),
                ]),

            Section::make('Appartenenza')
                ->columns(2)
                ->schema([
                    Placeholder::make('tenant_readonly')
                        ->label('Palestra')
                        ->content(fn (?User $record): string => $record?->tenant?->name ?? 'Piattaforma (nessuna palestra)'),

                    Placeholder::make('roles_readonly')
                        ->label('Ruoli')
                        ->content(fn (?User $record): string => static::ruoliDi($record)),
                ]),

            /*
             * La quota AI di questa persona — 14/08/2026.
             *
             * ── 🚨 Tre valori, e sono TRE cose diverse ─────────────────────
             *
             * | Valore | Significato |
             * |---|---|
             * | **vuoto** | «non decide questo livello» → si scende al successivo |
             * | **`0`** | **illimitato** |
             * | **`N`** | esattamente N chiamate al mese |
             *
             * ⚠️ Vuoto e `0` sembrano la stessa cosa e sono opposti: senza la
             * distinzione non si potrebbe sbloccare **una persona sola**
             * lasciando il default a tutte le altre — si potrebbe solo alzare
             * il tetto a tutti. E' la stessa convenzione di tutti e cinque i
             * livelli della catena (`MemberAiQuota::capFor()`).
             *
             * 🚨 **Qui non si decide se l'AI spetti**, e non esiste un valore
             * che voglia dire «niente AI»: quella domanda ha un cancello suo,
             * `RequirePlanWithAi`, che gira **prima** (D2). Mettere `0` a chi ha
             * un piano senza AI non gliela accende.
             */
            Section::make('AI')
                ->description(
                    'Vale solo per questa persona e scavalca palestra, trainer e piano. '
                    .'Lasciare tutto vuoto per usare quello che le spetterebbe.'
                )
                ->columns(2)
                ->schema([
                    /*
                     * 🚨 **Il cancello, che e' una domanda diversa dal tetto.**
                     *
                     * Fino al 15/08 qui c'erano solo i due tetti, e non
                     * bastavano: la quota dice *quante* chiamate, questo dice
                     * *se* l'AI spetti. ⚠️ Su dati veri l'utente #13 dello
                     * staging aveva quota illimitata e `ai=no` — il tetto era un
                     * rubinetto senza acqua.
                     *
                     * 💡 `false` non e' simmetria decorativa: e' il solo modo di
                     * guardare cosa vede chi **non** ha l'AI senza smontare il
                     * piano di una palestra intera.
                     */
                    Select::make('ai_enabled_override')
                        ->label('Funzioni AI')
                        ->columnSpanFull()
                        ->native(false)
                        ->options([
                            '' => 'Come dice il piano',
                            '1' => 'Accese per questa persona',
                            '0' => 'Spente per questa persona',
                        ])
                        // ⚠️ `null` deve restare distinguibile da «spente»: sono
                        // tre valori, e la tendina ne mostra tre.
                        ->formatStateUsing(fn (?bool $state): string => match ($state) {
                            true => '1',
                            false => '0',
                            null => '',
                        })
                        ->helperText(
                            'Accenderle non da\' anche la quota: il tetto sono i due campi qui sotto. '
                            .'Sono due domande diverse — SE l\'AI spetti, e QUANTE chiamate.'
                        ),

                    TextInput::make('ai_monthly_call_cap')
                        ->label('Chiamate AI al mese')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100000)
                        ->helperText('Vuoto = come le altre. 0 = ILLIMITATO. Altrimenti il numero di chiamate.'),

                    TextInput::make('ai_monthly_photo_call_cap')
                        ->label('...di cui con foto')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100000)
                        ->helperText(
                            'SOTTO-LIMITE del numero qui sopra, non un budget a parte: '
                            .'una foto consuma entrambi i contatori, e costa circa sette volte '
                            .'una chiamata normale. Vuoto = come le altre. 0 = illimitato.'
                        ),

                    Placeholder::make('quota_effettiva')
                        ->label('Quanto le spetta adesso')
                        ->columnSpanFull()
                        ->content(fn (?User $record): string => static::quotaEffettiva($record)),
                ]),

            Section::make('Stato')
                ->columns(2)
                ->schema([
                    Toggle::make('is_active')
                        ->label('Attivo')
                        ->helperText('Disattivato: non entra ne\' dall\'app ne\' dal pannello. La disattivazione finisce nel registro.'),

                    Placeholder::make('last_login_at')
                        ->label('Ultimo accesso')
                        ->content(fn (?User $record): string => $record?->last_login_at?->format('d/m/Y H:i') ?? 'mai'),
                ]),
        ]);
    }

    /**
     * Quanto le spetta **davvero**, con il consumo del mese.
     *
     * ── 🚨 Perche' questa riga vale piu' dei due campi sopra ───────────────
     *
     * I campi dicono cosa e' stato scritto **a questo livello**; questa riga
     * dice cosa risponde la catena intera (`MemberAiQuota`). ⚠️ Sono due cose
     * diverse tutte le volte che il campo e' vuoto — cioe' quasi sempre — e
     * senza questa riga chi guarda il modulo vede due caselle vuote e conclude
     * «non ha quota», che e' esattamente il contrario del vero.
     *
     * 💡 Mostra anche il **consumato**: un tetto senza il consumo non dice se
     * serva alzarlo.
     */
    private static function quotaEffettiva(?User $record): string
    {
        if ($record === null) {
            return '—';
        }

        $quota = app(MemberAiQuota::class);

        $descrivi = static function (?int $tetto, int $usate): string {
            // ⚠️ `null` qui vuol dire **illimitato**, non «non impostato»:
            // `capFor()` ha gia' risolto la catena e ha gia' tradotto lo `0`.
            return $tetto === null
                ? "illimitato ({$usate} usate questo mese)"
                : "{$usate} / {$tetto} questo mese";
        };

        // 🚨 Prima il cancello, poi il tetto: un tetto su un'AI spenta e' un
        // numero che non vuol dire niente, ed e' esattamente l'equivoco in cui
        // si e' caduti il 14/08.
        if (! app(PianoAttivo::class)->haLaAi($record)) {
            return 'AI SPENTA per questa persona: il tetto qui sotto non si applica.';
        }

        return 'Chiamate: '.$descrivi($quota->capFor($record), $quota->usedThisMonth($record))
            .' · con foto: '.$descrivi($quota->capFor($record, true), $quota->usedThisMonth($record, true));
    }

    /**
     * I ruoli dell'utente, letti **nella sua palestra**.
     *
     * Non basta `$record->roles`: in modalita' teams la relazione e' filtrata
     * sul tenant corrente, e qui il contesto e' vuoto — verrebbe fuori un elenco
     * vuoto per tutti. Si legge quindi la tabella pivot direttamente.
     */
    private static function ruoliDi(?User $record): string
    {
        if ($record === null) {
            return '—';
        }

        $ruoli = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $record->getMorphClass())
            ->where('model_has_roles.model_id', $record->getKey())
            ->pluck('roles.name')
            ->all();

        if ($record->isSuperAdmin()) {
            array_unshift($ruoli, 'super_admin (piattaforma)');
        }

        return $ruoli === [] ? 'nessuno' : implode(', ', $ruoli);
    }
}
