<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Schemas;

use App\Models\AiCreditMovement;
use App\Models\PacchettoGettoni;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reporting\IContiDellaPalestra;
use App\Support\Tenancy\TenantContext;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * 👤 **La scheda di un iscritto, vista dalla palestra** — 23/09/2026, F5.7.
 *
 * 📌 Il committente: *«⛔ niente identità modificabile, ⛔ niente password»*, e
 * dentro **revenue per allievo** e il tasto del **reset password** (F5.6).
 *
 * ══ 🚨 PERCHÉ SI GUARDA E NON SI MODIFICA ═══════════════════════════════
 *
 * ⛔ Prima questa era una pagina di **modifica**: la palestra poteva cambiare
 * nome, email, nome utente e **scegliere la password** di una persona.
 *
 * ⚠️ Cambiare l'email di un account è **prenderne il controllo**: il reset
 * della password arriva lì. 🚨 E scegliere la password di qualcun altro
 * significa conoscerla — cioè poter entrare nell'app al posto suo, leggere le
 * sue chat cifrate e i suoi allenamenti. ⛔ L'account **non dipende dalla
 * palestra** (D4): è della persona, anche mentre si allena lì.
 *
 * ✅ Quello che resta alla palestra è il **rapporto**: sospendere, disiscrivere,
 * regalare gettoni, e mandare un link perché la password se la rifaccia da sé.
 *
 * ══ ⚠️ E COSA NON SI VEDE, DI PROPOSITO ═════════════════════════════════
 *
 * ⛔ Non c'è **niente su come vive**: né allenamenti, né peso, né consumi
 * dell'AI, né cosa ha comprato da noi. 💡 È la regola di sempre — *«la palestra
 * sa CHI È ISCRITTO e COSA HA COMPRATO [da lei], non come vive»* — e i gettoni
 * qui sotto compaiono **solo** per la parte che riguarda la palestra: il saldo,
 * per sapere se serve ricaricarlo, e quelli che gli ha regalato lei.
 */
class SchedaDellIscritto
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Chi è')
                ->columns(3)
                ->schema([
                    TextEntry::make('name')->label('Nome')->weight('bold'),

                    TextEntry::make('email')
                        ->label('Email')
                        ->copyable()
                        ->copyMessage('Email copiata'),

                    TextEntry::make('phone')->label('Telefono')->placeholder('non l\'ha dato'),

                    TextEntry::make('stato')
                        ->label('Stato')
                        ->badge()
                        ->state(fn (User $r): string => $r->is_active ? 'attivo' : 'sospeso')
                        ->color(fn (User $r): string => $r->is_active ? 'success' : 'warning'),

                    TextEntry::make('created_at')
                        ->label('Iscritto il')
                        ->state(fn (User $r): string => $r->created_at?->format('d/m/Y').' · '
                            .($r->created_at?->diffForHumans(syntax: true) ?? '')),

                    TextEntry::make('last_login_at')
                        ->label('Ultimo accesso')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('non è mai entrato'),

                    /*
                     * 💡 **La risposta a «non riesce a entrare».**
                     * `password_is_set` è `false` per chi è nato da un invito e
                     * non l'ha mai scelta: è il caso di supporto più comune che
                     * ci sia, e sta qui per non doverlo indovinare.
                     *
                     * ⛔ La password **non si vede e non si sceglie**: accanto
                     * c'è il tasto che gli manda il link per rifarla.
                     */
                    TextEntry::make('password_is_set')
                        ->label('Password')
                        ->badge()
                        ->state(fn (User $r): string => $r->password_is_set ? 'l\'ha scelta lui' : 'mai scelta')
                        ->color(fn (User $r): string => $r->password_is_set ? 'gray' : 'warning')
                        ->helperText('Non si vede e non si imposta da qui: gli si manda il link.'),
                ]),

            /*
             * ══ 💶 QUANTO TI RENDE — 23/09/2026, F5.6 ══════════════════════
             *
             * 📌 Il committente: dentro la pagina dell'iscritto ci va la
             * **revenue per allievo**.
             *
             * 🚨 **I due numeri vengono da due mondi**, e il riquadro lo dice:
             * il costo lo conosciamo al centesimo (è il vostro canone diviso
             * gli iscritti); la quota l'avete scritta voi, e ✅ **D9** dice che
             * non la verifichiamo.
             *
             * ⚠️ È lo stesso conto per **tutti** gli iscritti: non è «quanto
             * rende Mario», è «quanto rende un iscritto», mostrato qui perché
             * è qui che ci si fa la domanda.
             */
            Section::make('Quanto ti rende')
                ->columns(4)
                ->description('Sono i numeri del tuo piano, uguali per ogni iscritto. La quota l\'hai scritta tu nelle Impostazioni.')
                ->schema([
                    TextEntry::make('costo')
                        ->label('Ti costa al mese')
                        ->state(fn (): string => self::euro(self::conti()->costoPerAllievoCent(self::palestra()))),

                    TextEntry::make('prezzo')
                        ->label('Gli fai pagare')
                        ->state(fn (): string => self::euro(self::conti()->prezzoPerAllievoCent(self::palestra()))),

                    TextEntry::make('margine')
                        ->label('Ti resta')
                        ->weight('bold')
                        ->state(fn (): string => self::euro(self::conti()->marginePerAllievoCent(self::palestra()))),

                    /*
                     * 💡 **Il cumulato è l'unico numero davvero suo**: dipende
                     * da **quando** si è iscritto. ⚠️ È una stima, e lo dice:
                     * assume che abbia pagato ogni mese da allora, cosa che noi
                     * non possiamo sapere — quei soldi non passano da qui.
                     */
                    TextEntry::make('da_quando')
                        ->label('Da quando è qui, in tutto')
                        ->state(fn (User $r): string => self::cumulato($r))
                        ->helperText('Stima: quota × mesi da quando si è iscritto.'),
                ]),

            Section::make('Gettoni')
                ->columns(3)
                ->description('⚠️ I gettoni sono suoi: glieli puoi regalare, non riprendere. Quello che ci fa non lo vediamo né noi né tu.')
                ->schema([
                    TextEntry::make('ai_credits')
                        ->label('Ne ha adesso')
                        ->badge()
                        ->state(fn (User $r): string => number_format((int) $r->ai_credits, 0, ',', '.'))
                        ->color(fn (User $r): string => ((int) $r->ai_credits) > 0 ? 'success' : 'gray'),

                    TextEntry::make('regalati')
                        ->label('Gliene hai regalati')
                        ->state(fn (User $r): string => number_format(self::regalatiDaNoi($r), 0, ',', '.')),

                    TextEntry::make('speso_per_lui')
                        ->label('Quanto ci hai speso')
                        ->state(fn (User $r): string => self::euro(self::spesoPerLui($r))),
                ]),
        ]);
    }

    // ───────────────────────── i conti ─────────────────────────

    private static function palestra(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }

    private static function conti(): IContiDellaPalestra
    {
        return app(IContiDellaPalestra::class);
    }

    private static function euro(?int $cent): string
    {
        return $cent === null ? '—' : number_format($cent / 100, 2, ',', '.').' €';
    }

    /**
     * Quanto ha portato questo iscritto da quando è qui.
     *
     * ⚠️ **Stima dichiarata**: quota × mesi. ⛔ Non sappiamo chi ha pagato e
     * chi era in ferie — quei soldi non passano da noi.
     */
    private static function cumulato(User $iscritto): string
    {
        $palestra = self::palestra();
        $prezzo = $palestra === null ? null : self::conti()->prezzoPerAllievoCent($palestra);

        if ($prezzo === null || $iscritto->created_at === null) {
            return '—';
        }

        // 💡 `+1`: il mese in cui si è iscritto conta, anche se è iniziato ieri.
        $mesi = $iscritto->created_at->diffInMonths(now()) + 1;

        return self::euro((int) ($prezzo * $mesi))." · {$mesi} mesi";
    }

    /**
     * 🎁 I gettoni che **questa palestra** gli ha regalato — F5.8.
     *
     * ⚠️ `pagato_da_tenant_id`, non `tenant_id`: il movimento vive nello spazio
     * di **chi li ha ricevuti**, ed è lì che `TenantScope` lo va a cercare.
     */
    private static function regalatiDaNoi(User $iscritto): int
    {
        $palestra = self::palestra();

        if ($palestra === null) {
            return 0;
        }

        return (int) AiCreditMovement::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('user_id', $iscritto->getKey())
            ->where('pagato_da_tenant_id', $palestra->getKey())
            ->sum('delta');
    }

    /**
     * 💶 Quanto ha speso la palestra in gettoni **per lui**.
     *
     * ⛔ Non si legge dal registro degli incassi: lì una riga è un pagamento, e
     * un pagamento può contenere gettoni per più persone. 💡 Qui si stima dal
     * listino corrente, e serve a rispondere a «quanto mi è costato tenerlo
     * carico».
     */
    private static function spesoPerLui(User $iscritto): ?int
    {
        $regalati = self::regalatiDaNoi($iscritto);

        if ($regalati === 0) {
            return 0;
        }

        $pacchetto = PacchettoGettoni::query()
            ->inVendita()
            ->orderBy('gettoni')
            ->first();

        if ($pacchetto === null) {
            return null;
        }

        return (int) round($regalati * $pacchetto->centPerGettone());
    }
}
