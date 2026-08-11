{{--
    Il cartello che ha sostituito la chat del pannello — S6.8.

    ⚠️ Non è una pagina «in costruzione»: è la spiegazione definitiva del perché
    i messaggi non si leggono più da qui. Il testo dice la cosa vera — non
    «funzione spostata», ma «non possiamo leggerli» — perché è quello il punto,
    ed è anche l'argomento di vendita verso gli iscritti.
--}}
<x-filament-panels::page>
    <div class="mx-auto max-w-2xl space-y-6 py-8 text-center">

        <div class="flex justify-center">
            <x-filament::icon
                icon="heroicon-o-lock-closed"
                class="h-12 w-12 text-primary-500"
            />
        </div>

        <h2 class="text-xl font-bold tracking-tight">
            I messaggi si leggono nell'app
        </h2>

        @if ($this->nonLetti > 0)
            <p class="text-sm font-medium text-primary-600 dark:text-primary-400">
                Hai {{ $this->nonLetti }}
                {{ $this->nonLetti === 1 ? 'messaggio non letto' : 'messaggi non letti' }}.
            </p>
        @endif

        <div class="space-y-4 text-sm text-gray-500 dark:text-gray-400">
            <p>
                Le conversazioni sono <strong>cifrate da un telefono all'altro</strong>:
                le chiavi per aprirle stanno solo sul tuo dispositivo e su quello
                della persona con cui parli.
            </p>

            <p>
                Vuol dire che <strong>nemmeno noi possiamo leggerle</strong>, e quindi
                nemmeno questo pannello può mostrartele: il server conserva i
                messaggi e li consegna, ma non ha modo di aprirli.
            </p>

            <p class="text-gray-400 dark:text-gray-500">
                È lo stesso motivo per cui il titolare della palestra non ha mai
                potuto leggere le conversazioni fra i trainer e i loro iscritti —
                solo che adesso non è più una regola del programma:
                è una proprietà dei dati.
            </p>
        </div>

    </div>
</x-filament-panels::page>
