{{--
    💳 «Non siamo riusciti a incassare» — 27/09/2026.

    ══ ⛔ IL TESTO DI PRIMA ERA SBAGLIATO ═══════════════════════════════════

    Diceva «{nome}: il servizio è sospeso» e spiegava cosa restava spento.
    📌 Il committente: *«non mi piace, così è troppo "sono uno sciacallo e voglio
    farmi pagare". Rendilo più umano»*.

    🚨 E aveva ragione su una cosa precisa: quel testo **dava per scontato che il
    cliente non volesse pagare**. Nella vita vera il caso normale è un altro —
    una carta scaduta, un plafond finito, una banca che ha bloccato l'addebito —
    e la persona lo scopre da qui.

    🔑 Quindi si parte da **noi**, non da lui: *«non siamo riusciti a incassare»*.
    ⚖️ È anche più vero: l'addebito lo facciamo noi, ed è il nostro tentativo che
    è andato male.

    ── 💡 Le quattro cose, in quest'ordine ────────────────────────────────────

    1. **cosa è successo** — un addebito non è andato a buon fine;
    2. **cosa NON si è perso** — i dati ci sono tutti. Toglie la paura, ed è la
       ragione per cui questo riquadro non è rosso;
    3. **cosa è in pausa** — detto senza elencare minacce;
    4. **come si rimedia** — il metodo di pagamento, in un clic.

    ⛔ Nessuna scadenza, nessun «entro», nessun conto alla rovescia: chi legge
    questo ha già un problema, e aggiungerne uno non fa incassare prima.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div class="flex items-start gap-3">
                <x-filament::icon
                    icon="heroicon-o-credit-card"
                    class="mt-0.5 h-6 w-6 flex-shrink-0 text-amber-500"
                />

                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                        Non siamo riusciti a incassare l'ultimo addebito
                    </h2>

                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{-- 🔑 Prima la rassicurazione: è quello che serve sapere. --}}
                        Succede spesso: una carta scaduta, un limite di spesa, una banca che
                        ha fermato il pagamento. Intanto i tuoi dati e quelli dei tuoi
                        iscritti sono al loro posto, e non si perde niente.
                    </p>

                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{--
                            ⚠️ «In pausa» e non «spente»: è la stessa cosa detta senza
                            farne una punizione. E si nomina lo spazio, perché chi porta
                            due cappelli deve sapere DI QUALE stiamo parlando.
                        --}}
                        Fino al prossimo incasso, su <strong>{{ $this->getNome() }}</strong> le
                        funzioni dell'abbonamento restano in pausa. Controlla il metodo di
                        pagamento e riprende tutto da sé.
                    </p>
                </div>
            </div>

            <x-filament::button
                tag="a"
                :href="\App\Filament\Gym\Pages\IlTuoPiano::getUrl()"
                icon="heroicon-m-credit-card"
                class="flex-shrink-0"
            >
                Modifica il metodo di pagamento
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
