<?php

declare(strict_types=1);

namespace App\Support\Sicurezza;

/**
 * 🔒 **Questa sessione sta agendo per conto di qualcun altro?** — voce 64, 27/09/2026.
 *
 * ══ 📌 LA RISPOSTA E' SEMPRE «NO», E LA DOMANDA RESTA COMUNQUE ═════════════
 *
 * ⛔ Fino al 27/09/2026 esisteva `App\Support\Impersonation\Impersonator`: il
 * super admin poteva entrare nei panni di un cliente dal pannello di piattaforma.
 * 📌 Il committente: *«NIENTE IMPERSONATOR, l'avevamo tolto»* … *«è un data breach
 * costruito apposta per farmi mandare in galera»*.
 *
 * 🚨 E aveva ragione sul punto tecnico: un accesso nei panni di un cliente è, nei
 * log applicativi, **indistinguibile** da un accesso di quel cliente. ⚠️ La traccia
 * rende l'accesso *ricostruibile*, non *lecito* — e davanti al GDPR il titolare del
 * trattamento è chi ha la porta.
 *
 * ══ 🔑 PERCHE' QUESTA CLASSE ESISTE INVECE DI CANCELLARE LE GUARDIE ════════
 *
 * `ConversationPolicy` e `CancelloDellaChat` chiedevano all'impersonatore *«sto
 * impersonando?»* per **negare la lettura delle chat**. ⛔ Cancellare quelle
 * domande, adesso che la risposta è sempre `false`, sarebbe la scorciatoia
 * sbagliata:
 *
 * 🚨 Se un giorno una funzione «entra come» tornasse — per un motivo qualsiasi,
 * scritta da qualcun altro, chiamata in un altro modo — **la riservatezza delle
 * chat sarebbe già caduta**, e nessun test lo direbbe. ⚠️ Il difetto si
 * manifesterebbe il giorno in cui qualcuno legge i messaggi fra un iscritto e il
 * suo trainer: infortuni, disturbi alimentari, foto.
 *
 * 💡 Quindi la **domanda** resta, in un posto solo, e la risposta è `false` per
 * costruzione. ⚖️ Costa una classe di dieci righe e compra il fatto che quel
 * giorno il difetto **non** ci sarà.
 *
 * ══ ⚠️ E PERCHE' NON SI CHIAMA PIU' «IMPERSONATOR» ════════════════════════
 *
 * ⛔ Una classe chiamata `Impersonator` che non può impersonare è **un nome che
 * mente**, e il nome è la prima cosa che qualcuno legge prima di riscrivere la
 * funzione che «manca». 💡 Questo nome dice cosa si chiede, non cosa si fa.
 *
 * 🚨 **Non si aggiungono metodi qui.** Niente `entra()`, niente `avvia()`, niente
 * `perConto()`: `divieti.md` del 27/09/2026 lo vieta senza scadenza, e questa
 * classe esiste proprio per non far sembrare quel divieto una dimenticanza.
 */
final class SessionePerContoTerzi
{
    /**
     * ⛔ **Sempre `false`, per costruzione.**
     *
     * 💡 Non legge la sessione e non può leggerla: non esiste nessuna chiave che
     * qualcuno possa scriverci per far diventare vera questa risposta.
     */
    public function inCorso(): bool
    {
        return false;
    }
}
