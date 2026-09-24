<?php

declare(strict_types=1);

namespace App\Filament\Gym\Widgets;

use App\Filament\Gym\Support\ChiPossoSeguire;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * I numeri della palestra in cima al pannello.
 *
 * 🚨 **Sono scelti per rispondere a una domanda sola: chi sto per perdere.**
 * Il conteggio degli iscritti totali e' vanita'; «quanti non si allenano da
 * trenta giorni» e «quanti sono senza scheda» sono le due cose su cui una
 * palestra puo' agire domani mattina. Per questo la prima ha il colore
 * dell'allarme quando non e' zero.
 */
class GymOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $iscritti = $this->idIscritti();
        $totale = count($iscritti);

        /*
         * ⛔ **«Non si allenano da 30 giorni» e «Allenamenti questa settimana»
         * non ci sono piu'** — FASE 11.6, 21/08/2026.
         *
         * 📌 *«Niente, nemmeno se si allena»* (16/08). Gli allenamenti stanno
         * sul telefono di chi li fa: non e' che non li mostriamo, il server non
         * ce li ha piu'.
         *
         * ⚠️ **E' una perdita vera, dichiarata**: erano i due numeri con cui una
         * palestra capiva chi stava per abbandonare. 🚨 Lasciarli avrebbe voluto
         * dire mostrare **zero a tutti**, che e' peggio di non mostrarli: una
         * palestra avrebbe creduto che nessuno si allena piu'.
         *
         * 💡 Resta «Senza scheda», che nasce dalle schede — quelle restano.
         */

        /*
         * ══ ⛔ VIA «SENZA SCHEDA PUBBLICATA» — 22/09/2026, F5.4 ════════════
         *
         * 🚨 **Contava una cosa che non esiste più.** Cercava gli iscritti
         * senza una scheda in stato `published` *legata a loro*: ma dopo R2 le
         * schede non hanno destinatari — vivono sui telefoni, e il server tiene
         * solo i **modelli** della palestra.
         *
         * ⛔ Quindi quel numero era **il totale degli iscritti**, sempre, per
         * tutti: un allarme arancione permanente sotto cui non c'era niente da
         * fare. ⚠️ È lo stesso errore che nel 2026-08 aveva già portato a
         * togliere «non si allenano da 30 giorni»: un cruscotto che mostra zero
         * (o tutti) a tutti è peggio di un cruscotto che non lo mostra.
         *
         * 💡 I numeri che lo sostituiscono — posti, costo, guadagno — stanno in
         * `IPostiDellaPalestra` e `IlGiroDiSoldiDellaPalestra` (F5.1, F5.2).
         */
        /*
         * ══ 🚨 IL CRUSCOTTO CONTA **QUESTO SPAZIO**, E BASTA ════════════════
         *
         * 📌 Il committente, il 24/09: *«se l'hai organizzato a spazi, tanto
         * vale che nello spazio della palestra in dashboard si vedano solo gli
         * allievi della palestra. Idem per giulia»*.
         *
         * ⛔ **Via la card «I tuoi allievi» dentro la palestra.** Gli allievi
         * suoi sono roba del **suo** spazio: là si contano, e là si va a
         * guardarli con «Cambia spazio».
         *
         * 💡 E dentro la palestra quel numero era **già incoerente col resto
         * della pagina**: diceva «3» mentre l'elenco «Iscritti» di quello
         * spazio ne mostrava dieci, e i suoi tre lì dentro non c'erano — due
         * abitano altrove. Un numero che rimanda a una lista che in quello
         * spazio non esiste.
         */
        $personale = app(TenantContext::class)->get()?->ePersonale() === true;

        $stat = [
            Stat::make($personale ? 'I tuoi allievi' : 'Iscritti attivi', (string) $totale)
                ->description($personale
                    ? 'Le persone che segui, ovunque siano iscritte'
                    : 'Persone con un account funzionante')
                ->icon('heroicon-m-user-group')
                ->color('primary'),
        ];

        /*
         * ══ ⛔ LA SECONDA CARD C'E' STATA, PER MEZZA GIORNATA ═══════════════
         *
         * 📌 Il 24/09 il committente aveva chiesto, per il trainer di palestra,
         * *«un'altra card con allievi seguiti che indichi tutti gli allievi
         * della palestra»* accanto ai suoi. ⛔ Poi, vedendola: *«se l'hai
         * organizzato a spazi, tanto vale che nello spazio della palestra si
         * vedano solo gli allievi della palestra»*.
         *
         * 🚨 **Ed è più coerente, non solo più semplice**: il pannello è uno e
         * prende la forma dello spazio in cui sei. Un numero che parla di un
         * altro spazio, in mezzo a una pagina che parla di questo, è la stessa
         * incoerenza che rendeva quella card inseguibile — diceva «3» mentre
         * l'elenco accanto ne mostrava dieci, e quei tre lì dentro non c'erano.
         *
         * ⚠️ **Nel frattempo quella card ha però insegnato una cosa che resta**:
         * la sua descrizione diceva *«anche quelli non assegnati a te»*, e gli
         * iscritti di una palestra **non sono assegnati a nessuno**. La regola
         * sta in `memory/decisioni.md`, e il test che la tiene ferma guarda **le
         * parole** del cruscotto, non i numeri.
         */
        return $stat;
    }

    /**
     * Le persone del numero grosso in cima: **quelle di questo spazio**.
     *
     * ══ 🚨 PERCHE' PASSA DA `ChiPossoSeguire` — F10.7, 24/09/2026 ══════════
     *
     * 📌 Il committente: *«Nel pannello di giulia trainer mi dice iscritti
     * attivi 1. Qui credo proprio sia sbagliato, perché lei segue due allievi,
     * anche se uno è di una palestra non vedo perché non dovrebbe essere
     * conteggiato visto che la paga»*.
     *
     * ⛔ **Era la terza copia della stessa regola**, e sbagliava come le altre
     * due: cercava chi ha il ruolo `member` **in questo tenant**, e gli allievi
     * di un trainer indipendente che avevano già un account **vivono nello
     * spazio della loro palestra**. 🚨 Ne contava uno su due, e il numero
     * mancante era una persona che lui paga.
     *
     * 💡 Adesso la domanda è una sola, e la risposta dipende **dallo spazio**:
     *
     * | Dove sei | Cosa conta |
     * |---|---|
     * | in una palestra | gli iscritti **della palestra** — che un trainer segue tutti |
     * | nel tuo spazio | i **tuoi** allievi, ovunque siano iscritti |
     *
     * @return list<int>
     */
    private function idIscritti(): array
    {
        if (app(TenantContext::class)->id() === null) {
            return [];
        }

        return ChiPossoSeguire::query()
            ->where('is_active', true)
            ->pluck('users.id')
            ->all();
    }
}
