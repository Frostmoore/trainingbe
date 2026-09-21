<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Models\Promozione;
use App\Models\User;
use App\Services\Billing\LePromozioni;
use Illuminate\Support\Collection;

/**
 * 👀 **Le offerte che una persona vede** — 24/09/2026, P8 di `plan_promozioni.md`
 * (opzione 38).
 *
 * Una regola sola per l'app (`GET /api/v1/promozioni`) e per il pannello
 * (`OffertaPerTe`): ⛔ due elenchi scritti due volte finirebbero per mostrare
 * offerte diverse alla stessa persona.
 *
 * | Si vede | Quando |
 * |---|---|
 * | una promozione con `visibile_in` che contiene quel posto | se è spendibile, la prende, e può ancora prenderla |
 * | una promozione con un acquisto **in sospeso** per lei | sempre (è già sua: regalo col link, sconto sbloccato) |
 *
 * 💡 `visibile_in` vuoto = nessun elenco: la vede solo chi ha il link.
 */
class LeOfferteDellaPersona
{
    public function __construct(
        private readonly ChiLaRiceve $chiLaRiceve,
        private readonly ConsegnaDeiPremi $consegna,
        private readonly LePromozioni $promozioni,
    ) {}

    /**
     * @param  'app'|'pannello'|'sito'  $dove
     * @return Collection<int, Promozione>
     */
    public function per(User $utente, string $dove): Collection
    {
        return Promozione::query()
            ->spendibili()
            ->with(['premi', 'bersagli', 'condizioni'])
            ->latest()
            ->get()
            ->filter(function (Promozione $p) use ($utente, $dove): bool {
                if ($p->haUnAcquistoInSospeso($utente) !== null) {
                    return true;
                }

                return in_array($dove, (array) $p->visibile_in, true)
                    && ! $p->eFuoriBudget()
                    && $this->chiLaRiceve->prende($p, $utente)
                    && ($p->laPuoPrendereAncora($utente) && ! $p->haSblocchi());
            })
            ->values();
    }

    /**
     * Le offerte **pubbliche**, per il sito: per tutti, senza condizioni.
     *
     * ⚠️ Una promozione mirata (una palestra, un ruolo, «solo i nuovi») sul
     * sito sarebbe una promessa a chi non la può avere.
     *
     * @return Collection<int, Promozione>
     */
    public function pubbliche(): Collection
    {
        return Promozione::query()
            ->spendibili()
            ->with(['premi', 'bersagli', 'condizioni'])
            ->latest()
            ->get()
            ->filter(fn (Promozione $p): bool => in_array('sito', (array) $p->visibile_in, true)
                && $p->condizioni->isEmpty()
                && ! $p->eFuoriBudget()
                && $p->bersagli->where('escludi', false)->pluck('tipo.value')->all() === ['tutti']
                && $p->bersagli->where('escludi', true)->isEmpty())
            ->values();
    }

    /**
     * Perché il link di un'offerta non vale per questa persona — `null` se vale.
     *
     * ⚠️ **Motivi diversi, messaggi diversi** (F2): «scaduta», «esaurita», «non è
     * per te», «l'hai già ricevuta» sono quattro telefonate diverse.
     */
    public function perche(string $codice, User $utente): ?string
    {
        if (blank($codice)) {
            return 'Questo indirizzo non porta a nessuna offerta.';
        }

        if ($this->promozioni->dalLink($codice, $utente) !== null) {
            return null;
        }

        $promozione = Promozione::query()->with(['bersagli', 'condizioni', 'premi'])->where('codice', mb_strtoupper(trim($codice)))->first();

        if ($promozione === null) {
            return 'Questo link non porta a nessuna offerta.';
        }

        if (($motivo = $promozione->perchePiuNonVale()) !== null) {
            return $motivo;
        }

        if ($this->chiLaRiceve->prende($promozione, $utente) && ! $promozione->laPuoPrendereAncora($utente)) {
            return 'Hai già ricevuto questa offerta.';
        }

        return 'Questa offerta non è per il tuo account.';
    }

    /**
     * Come la si racconta all'app.
     *
     * @return array<string, mixed>
     */
    public function perLApp(Promozione $p, User $utente): array
    {
        return [
            'codice' => $p->codice,
            'nome' => $p->nome,
            'descrizione' => $p->descrizione,
            'cosa' => ucfirst($p->scrittoBene()),
            'genere' => $p->genere->value,
            'scade_il' => $p->scade_il?->toIso8601String(),
            /*
             * ⚠️ «Attiva» solo per le promozioni **col link**: le automatiche
             * arrivano da sole, massa ed eventi pure — un tasto lì sarebbe un
             * secondo modo di prenderle che nessuno ha deciso.
             */
            'attivabile' => $p->attivazione === ComeSiAttiva::Link && $this->consegna->percheNo($p, $utente) === null,
            'in_sospeso' => $p->haUnAcquistoInSospeso($utente) !== null,
            'si_usa_pagando' => $p->haPremiDAcquisto(),
        ];
    }
}
