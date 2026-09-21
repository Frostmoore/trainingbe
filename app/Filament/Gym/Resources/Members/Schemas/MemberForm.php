<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Schemas;

use App\Support\Tenancy\TenantContext;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * La scheda anagrafica di un iscritto — B3.4.
 *
 * Il profilo (sesso, altezza, obiettivo) sta nella stessa pagina anche se e' su
 * un'altra tabella: per chi lavora in palestra e' la stessa cosa, e costringere
 * a due salvataggi diversi significa che il secondo non lo fa nessuno — e senza
 * quei campi il fabbisogno calorico non si calcola.
 */
class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome e cognome')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Unica **dentro la palestra**: la stessa persona puo'
                        // essere iscritta anche altrove.
                        ->unique(
                            table: 'users',
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->where(
                                'tenant_id',
                                app(TenantContext::class)->id(),
                            ),
                        ),

                    TextInput::make('username')
                        ->label('Nome utente')
                        ->maxLength(30)
                        ->unique(table: 'users', ignoreRecord: true)
                        ->helperText('Unico su tutta la piattaforma. Facoltativo.'),

                    TextInput::make('phone')
                        ->label('Telefono')
                        ->tel()
                        ->maxLength(32),

                    /*
                     * ══ ⛔ LA PASSWORD NON LA SCEGLIE LA PALESTRA — F5.7 ════
                     *
                     * 📌 Il committente, sulla pagina dell'iscritto: *«⛔ niente
                     * identità modificabile, ⛔ niente password»*.
                     *
                     * 🚨 **Scegliere la password di qualcun altro significa
                     * conoscerla**: poter entrare nell'app al posto suo, e da lì
                     * leggere le sue chat e i suoi allenamenti. ⛔ L'account non
                     * dipende dalla palestra (D4): è della persona, anche
                     * mentre si allena lì.
                     *
                     * ✅ Al suo posto: l'account nasce **senza password**, e a
                     * lui parte il link per sceglierne una. Lo fa
                     * `CreateMember::afterCreate()`.
                     *
                     * 💡 E la strada normale resta l'**invito**: questo modulo
                     * serve a chi ha già l'elenco su carta e non vuole mandare
                     * venti link uno per uno.
                     */

                    Toggle::make('is_active')
                        ->label('Attivo')
                        ->default(true)
                        ->helperText('Disattivato: non entra piu\' dall\'app.'),
                ]),

            /*
             * ⛔ **Via «Chi lo segue»** — 23/09/2026, F5.6.
             *
             * 🚨 **Non governava più niente.** Dal 16/09 un trainer vede tutta
             * la palestra, come già la vede nell'app; e dopo R2 nemmeno le
             * schede passano dall'assegnazione. ⚠️ Un campo che sembra
             * decidere chi vede cosa, e non lo decide, è peggio di un campo
             * che manca.
             *
             * 💡 La tabella `member_trainer` **resta**: la usa la chat, per
             * sapere con chi può parlare un iscritto.
             */

            /*
             * 🆕 ⛔ **Qui c'era il profilo dell'iscritto** — tolto il 19/09/2026.
             * Prima i dati del corpo (sesso, data di nascita, altezza, attivita',
             * obiettivo, peso obiettivo), poi anche le note dello staff:
             * 📌 *«puoi togliere anche le note dello staff, non servono a molto
             * sul server»*. Il profilo sta sul telefono della persona.
             */
        ]);
    }

    /*
     * ⛔ **`trainerDellaPalestra()` non c'è più** — 23/09/2026, F5.6.
     *
     * Riempiva la tendina «Trainer assegnati», che è sparita con la sezione
     * «Chi lo segue»: vedi sopra il perché.
     */
}
