<?php

declare(strict_types=1);

namespace App\Filament\Gym\Widgets;

use App\Filament\Gym\Support\ChiPossoSeguire;
use App\Models\User;
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
        $stat = [
            Stat::make($this->dipendente() ? 'I tuoi allievi' : 'Iscritti attivi', (string) $totale)
                ->description($this->dipendente()
                    ? 'Le persone assegnate a te'
                    : 'Persone con un account funzionante')
                ->icon('heroicon-m-user-group')
                ->color('primary'),
        ];

        /*
         * ══ 🆕 👥 LA SECONDA CARD, SOLO PER IL TRAINER DI PALESTRA ══════════
         *
         * 📌 Il committente, il 24/09, guardando il pannello di un trainer
         * dipendente: *«in dashboard dice 0 iscritti. Che di suo sarebbe anche
         * giusto, ma vorrei che mostrasse un'altra card con allievi seguiti che
         * indichi tutti gli allievi della palestra»*.
         *
         * 💡 Le due domande sono diverse e stanno bene una accanto all'altra:
         * «quanti ne seguo io» dice quanto lavoro ho, «quanti ce ne sono qui»
         * dice dov'è il posto in cui lavoro. ⛔ Un trainer appena assunto vedeva
         * solo uno zero, e uno zero da solo sembra un pannello rotto.
         *
         * ⚠️ **Non è una fuga di dati**: dal 16/09 un trainer di palestra vede
         * l'elenco completo degli iscritti in lettura (`UserPolicy::viewAny`,
         * `ChiPossoSeguire`). 🚨 Il numero non dice niente che l'elenco non dica
         * già — e il giorno che quella decisione cambiasse, questa card va tolta
         * **insieme** all'elenco, non dopo.
         */
        if ($this->dipendente()) {
            $stat[] = Stat::make('Allievi della palestra', (string) count($this->idDellaPalestra()))
                ->description('Tutti gli iscritti, anche quelli non assegnati a te')
                ->icon('heroicon-m-building-storefront')
                ->color('gray');
        }

        return $stat;
    }

    /**
     * 🏢 Chi guarda è un trainer **dipendente** di questa palestra?
     *
     * ⚠️ `isTrainer()` risponde **sullo spazio corrente**: dentro la palestra è
     * `trainer`, a casa sua è `free_user`. È la stessa domanda che ha tenuto
     * fuori dal pannello tutti i trainer di palestra per sei giorni
     * (`RoleAwareLoginResponse`) — qui va bene perché qui lo spazio corrente
     * **è** la palestra che si sta guardando.
     */
    private function dipendente(): bool
    {
        $utente = auth()->user();

        return $utente instanceof User && $utente->isTrainer() && ! $utente->isGymAdmin();
    }

    /**
     * Le persone che questo cruscotto conta come «mie».
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
     * 💡 La regola sta scritta **una volta sola** in `ChiPossoSeguire`: chi la
     * cerca la trova, e chi la cambia la cambia per tutti — elenco iscritti,
     * tendina dell'import e questo cruscotto.
     *
     * @return list<int>
     */
    private function idIscritti(): array
    {
        if (app(TenantContext::class)->id() === null) {
            return [];
        }

        $query = ChiPossoSeguire::query()->where('is_active', true);

        /*
         * ⚠️ Il trainer dipendente resta ai **suoi**: `ChiPossoSeguire` gli dà
         * tutta la palestra perché quella è la regola degli *elenchi* (16/09,
         * *«tutti in lettura»*), ma il numero grosso in cima al pannello è «il
         * mio lavoro», e i totali della palestra stanno nella card accanto.
         */
        if ($this->dipendente()) {
            $query->whereIn('users.id', auth()->user()->assignedMembers()->pluck('users.id')->all());
        }

        return $query->pluck('users.id')->all();
    }

    /**
     * 🆕 Tutti gli iscritti **di questo spazio**, senza il filtro dell'assegnato.
     *
     * @return list<int>
     */
    private function idDellaPalestra(): array
    {
        if (app(TenantContext::class)->id() === null) {
            return [];
        }

        return ChiPossoSeguire::query()->where('is_active', true)->pluck('users.id')->all();
    }
}
