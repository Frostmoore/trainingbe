{{--
    Il pagamento non riuscito — 22/09/2026.

    Una cosa sola da fare: controllare la carta. Il pulsante porta dove si
    cambia, non alla home del pannello.
--}}
<x-mail::message>
# Il pagamento non è riuscito

Non siamo riusciti a incassare il pagamento di **{{ $nomeSpazio }}**@if ($quale) ({{ $quale }})@endif.

Di solito è la carta: scaduta, sostituita, o un limite della banca.

<x-mail::button :url="$pannello">
Controlla il metodo di pagamento
</x-mail::button>

**Cosa succede adesso.** Ci riproviamo automaticamente nei prossimi giorni.
Il servizio resta attivo: iscritti, schede e messaggi non si toccano. Se anche i
tentativi successivi non andassero a buon fine ti avvisiamo prima che
l'abbonamento scada.

Se hai già sistemato la carta, ignora pure questo messaggio.
</x-mail::message>
