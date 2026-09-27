{{--
    «Il tuo piano» — §9.6, 18/09/2026; 🆕 rifatta il 23/09/2026 (F8.4-F8.6).

    ⚠️ Classi `tc-*`, non di Tailwind: il CSS del pannello è precompilato e
    `grid gap-4 sm:grid-cols-2 mt-4 flex h-full` non ci sono — ed era per questo
    che le card «non avevano il margine giusto». Vedi pannello-palestra.css.
--}}
<x-filament-panels::page>
    @php($listino = $this->listino())
    @php($inCorso = $this->inCorso())
    @php($fatture = $this->fatture())
    @php($mancano = $this->datiMancanti())
    @php($euro = fn (?int $cent) => $cent === null ? '—' : number_format($cent / 100, 2, ',', '.').' €')

    <div class="tc-pagina">
        {{--
            🚨 Il pagamento non riuscito PER PRIMO, sopra tutto. Stripe riprova
            per qualche giorno — questo è il momento di sistemare la carta.
            ⚠️ Senza il portale di Stripe (F8.5) la carta si cambia scrivendoci.
        --}}
        @if ($listino['pagamento_fallito_il'])
            <div class="tc-avviso tc-avviso-pericolo">
                <strong>L'ultimo pagamento non è andato a buon fine</strong>
                (dal {{ \Illuminate\Support\Carbon::parse($listino['pagamento_fallito_il'])->format('d/m/Y') }}).
                Stripe riproverà nei prossimi giorni. Se la carta è scaduta, cambiala: appena salvata,
                ripaghiamo subito quello che è rimasto in sospeso.
                <div class="tc-azione">
                    <x-filament::button color="danger" wire:click="cambiaCarta" wire:loading.attr="disabled" icon="heroicon-m-credit-card">
                        Cambia carta
                    </x-filament::button>
                </div>
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">Adesso</x-slot>

            @if ($inCorso)
                <p class="tc-piano-nome">{{ $inCorso->plan?->name }}</p>

                <p class="tc-piano-testo">
                    @if ($inCorso->ends_at === null)
                        Non scade: si paga a mese chiuso, finché non lo cambi.
                    @elseif ($inCorso->rinnova)
                        Si rinnova da solo il {{ $inCorso->ends_at->format('d/m/Y') }}.
                    @else
                        Finisce il {{ $inCorso->ends_at->format('d/m/Y') }} e non si rinnova.
                    @endif
                </p>
            @else
                <p class="tc-piano-testo">Non hai ancora un piano. Scegline uno qui sotto.</p>
            @endif

            {{-- 🆕 💳 Dopo F8: il metodo di pagamento si cambia da qui, senza il portale. --}}
            {{-- 🆕 🛑 25/09: e da qui si disdice (a fine periodo), o ci si ripensa. --}}
            <div class="tc-azione">
                @if ($this->haUnMetodoDiPagamento())
                    <x-filament::button color="gray" wire:click="cambiaCarta" wire:loading.attr="disabled" icon="heroicon-m-credit-card">
                        Cambia metodo di pagamento
                    </x-filament::button>
                @endif

                {{ $this->disdiciAction }}
                {{ $this->ripensaciAction }}
            </div>
        </x-filament::section>

        {{--
            🆕 💳 **Perché i pulsanti sono spenti** — 27/09/2026.

            ⛔ Senza questa riga i pulsanti risulterebbero spenti **senza una ragione**, e
            una cosa che non funziona e non dice perché si legge come un guasto. 🚨 È lo
            stesso difetto dei messaggi che mostravano una diagnosi falsa: manda la persona
            a cercare la causa nel posto sbagliato.
        --}}
        @if ($listino['pagamento_in_sospeso'] ?? false)
            <x-filament::section>
                <div class="flex items-start gap-3">
                    <x-filament::icon
                        icon="heroicon-o-information-circle"
                        class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-500"
                    />

                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Il cambio di piano si riapre appena incassiamo l'addebito di questo
                        mese. Intanto puoi sistemare il metodo di pagamento qui sopra: non
                        serve fare altro, ci riproviamo da soli.
                    </p>
                </div>
            </x-filament::section>
        @endif

        <div class="tc-piani">
            @foreach ($listino['piani'] as $p)
                @php($suo = $p['codice'] === $listino['piano_attuale'])

                <x-filament::section>
                    <div class="tc-piano">
                        <p class="tc-piano-nome">{{ $p['nome'] }}</p>

                        @if ($this->eSuMisura($p))
                            {{-- 🆕 F8.6: il su misura si chiede, non si compra. --}}
                            <p class="tc-prezzo">Su misura</p>
                            <p class="tc-piano-testo">
                                Per le palestre grandi, o con esigenze particolari:
                                raccontaci come lavorate e ti facciamo una proposta.
                            </p>
                        @elseif ($p['a_consumo'])
                            <p class="tc-prezzo">{{ $euro($p['prezzo_iscritto_cent']) }} <small>a iscritto, al mese</small></p>
                            <p class="tc-piano-testo">
                                Minimo {{ $p['minimo_iscritti'] }} iscritti. Salvi la carta adesso,
                                e paghi a mese chiuso per quelli che hai avuto.
                            </p>
                        @else
                            <p class="tc-prezzo">{{ $euro($p['prezzo_cent']) }} <small>{{ $p['per_intervallo'] ?? 'al mese' }}</small></p>
                            {{--
                                ⚠️ Niente `@if` attaccato alle parole: `trainer@endif` non è
                                una direttiva per Blade. L'ha trovato il test che la apre davvero.
                            --}}
                            <p class="tc-piano-testo">
                                {{ $p['posti'] === null ? 'Posti senza limite' : $p['posti'].' posti' }}{{ $p['posti_trainer'] !== null ? ', '.$p['posti_trainer'].' trainer' : '' }}
                            </p>
                        @endif

                        <div class="tc-piano-azione">
                            @if ($suo)
                                <x-filament::badge color="success">Il tuo piano</x-filament::badge>
                            @elseif ($this->eSuMisura($p))
                                {{ $this->suMisuraAction }}
                            @else
                                {{--
                                    ⚠️ Il testo dice cosa succede: chi ha già un canone su
                                    Stripe PASSA a un altro (conguaglio), gli altri lo
                                    SCELGONO (pagamento). La strada la decide il server.
                                --}}
                                {{--
                                    🆕 🔒 **Finché il mese non è incassato, non si cambia piano**
                                    — 27/09/2026. 📌 *«quando clicchi su rinnova ora, deve solo
                                    farmi pagare, non mi deve permettere di cambiare il piano»*.

                                    ⚠️ Il pulsante si **spegne** e non sparisce: un piano che
                                    scompare fa credere di averlo perso. 💡 Spento con accanto il
                                    perché si legge come una condizione, non come un guasto.

                                    ⛔ E questo è solo l'aspetto: il cancello vero è in
                                    `IlTuoPiano::scegli()`, perché `wire:click` si chiama dalla
                                    console del browser.
                                --}}
                                @php($troppoPiccolo = $p['troppo_piccolo'] ?? null)

                                <x-filament::button
                                    wire:click="scegli('{{ $p['codice'] }}')"
                                    wire:loading.attr="disabled"
                                    :disabled="($listino['pagamento_in_sospeso'] ?? false) || $troppoPiccolo !== null"
                                >
                                    {{ $listino['puo_cambiare'] && ! $p['a_consumo'] ? 'Passa a questo' : 'Scegli' }}
                                </x-filament::button>

                                {{--
                                    🆕 🚫 **Il perché, accanto al pulsante spento** — 27/09/2026.

                                    📌 *«Per poterlo selezionare, devo prima liberare gli slot in
                                    eccesso»*. ⚠️ Quindi il piano si **vede** e non si sceglie: ⛔
                                    nasconderlo farebbe credere che non esista, e chi sta valutando
                                    di scendere non capirebbe cosa gli manca.

                                    🔑 Il testo è **lo stesso** che lancerebbe il server, perché
                                    viene dallo stesso metodo: il pulsante e il rifiuto non possono
                                    dire due cose diverse.
                                --}}
                                @if ($troppoPiccolo !== null)
                                    <p class="tc-piano-testo" style="margin-top: .5rem">
                                        {{ $troppoPiccolo }}
                                    </p>
                                @endif
                            @endif
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <p class="tc-nota">
            Salendo di piano paghi subito la differenza per i giorni che restano. Scendendo,
            la differenza diventa un credito sui mesi dopo — ma non si può scendere sotto i
            posti che stai già usando.
        </p>

        {{-- 🆕 🧾 F8.4 — le fatture, al posto del portale di Stripe. --}}
        <x-filament::section>
            <x-slot name="heading">Le tue fatture</x-slot>

            @if ($mancano !== [])
                <div class="tc-avviso">
                    Per ricevere le fatture ci servono ancora {{ implode(', ', $mancano) }}.
                    Li inserisci in <a href="{{ \App\Filament\Gym\Pages\GymSettings::getUrl() }}">Impostazioni</a>:
                    appena salvati, le fatture in attesa partono da sole.
                </div>
            @endif

            @if ($fatture->isEmpty())
                <p class="tc-piano-testo">Nessuna fattura ancora: nasce da sola a ogni pagamento.</p>
            @else
                <div class="tc-scorre">
                    <table class="tc-tabella">
                        <thead>
                            <tr>
                                <th>Numero</th>
                                <th>Data</th>
                                <th>Cosa</th>
                                <th class="tc-destra">Totale</th>
                                <th class="tc-destra"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fatture as $f)
                                <tr>
                                    <td>{{ $f->numeroPerEsteso() ?? '—' }}</td>
                                    <td>{{ $f->data?->format('d/m/Y') ?? '—' }}</td>
                                    <td>{{ $f->descrizione }}</td>
                                    <td class="tc-destra">{{ $euro($f->totale_cent) }}</td>
                                    <td class="tc-destra">
                                        @if ($f->eEmessa())
                                            <x-filament::link :href="route('filament.admin.fattura-pdf', ['fattura' => $f->getKey()])" icon="heroicon-m-arrow-down-tray">
                                                PDF
                                            </x-filament::link>
                                        @else
                                            <x-filament::badge color="warning">In attesa dei tuoi dati</x-filament::badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>

</x-filament-panels::page>
