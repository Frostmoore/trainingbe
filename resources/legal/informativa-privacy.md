# Informativa privacy

> **Ultimo aggiornamento:** 16 agosto 2026

---

## 1. In sintesi

**La maggior parte dei dati di cui questa informativa parla non sta sui nostri server.**

Sonno, battito, variabilità cardiaca, peso, misure e foto dei progressi **non stanno sui nostri
server**: vivono nel telefono di chi li produce, in un archivio locale.

⚠️ **Con una sola eccezione, e la diciamo subito**: se accendi «Sonno e recupero nel consiglio del
giorno», ore dormite, risvegli, fasi, variabilità e battito a riposo **partono verso Anthropic**
insieme al resto del consiglio. Restano comunque fuori dai nostri server — passano, non si fermano.
Senza quella casella non parte niente, e il consiglio funziona lo stesso.
Non è una promessa organizzativa — è una proprietà del sistema, e le tabelle che li contenevano
**sono state cancellate** (fasi S1-S5 di `plan_security_and_retention.md`).

**I messaggi della chat non li possiamo leggere.** Sono cifrati fra il telefono di chi scrive e
quello di chi riceve; il server conserva byte di cui non ha la chiave (fase S6).

⚠️ Va scritto **così**, e non con formule del tipo «adottiamo misure di sicurezza adeguate»: la
differenza fra «li proteggiamo» e «non li abbiamo» è l'unica che conta il giorno di una violazione.

---

## 2. Chi tratta i dati

| | |
|---|---|
| **Titolare del trattamento** | [RAGIONE SOCIALE], [indirizzo della sede], P.IVA [numero], PEC [indirizzo] |
| **Contatto privacy** | [indirizzo email] |
| **Responsabile della protezione dei dati** | [nominativo e contatto, se designato] |

### 🚨 Il rapporto con le palestre — il punto che va deciso e scritto

| Ruolo | Chi | Perché |
|---|---|---|
| **Titolare** | ⚠️ **la palestra**, per i dati dei propri iscritti | Decide finalità e mezzi del rapporto con l'iscritto: chi la segue, che programma le dà |
| **Responsabile** (art. 28) | ⚠️ **noi**, che forniamo lo strumento | Trattiamo per suo conto |

⚠️ **Questa qualificazione va confermata da un legale.** È possibile sostenere anche la
contitolarità (art. 26), e la scelta cambia **chi risponde a chi**. Il testo dell'accordo sta in
`accordo_art28_palestre.md`.

💡 Per i **free user** della Parte B — quelli senza palestra — il titolare siamo noi e basta. Sono
due informative, o una con due sezioni.

---

## 3. Cosa trattiamo, dove sta, e perché

### 3.1 ☁️ Sui nostri server

| Dato | Finalità | Base giuridica |
|---|---|---|
| Nome, email, nome utente, telefono | Identificazione e accesso | Art. 6(1)(b) — esecuzione del contratto |
| Password (hash) | Accesso | Art. 6(1)(b) |
| Identificativo Google/Apple | Accesso con account esterno | Art. 6(1)(b) |
| Palestra di appartenenza, trainer assegnato | Erogazione del servizio | Art. 6(1)(b) |
| Sesso, data di nascita, altezza, livello di attività, obiettivo | Calcolo del fabbisogno calorico | 🚨 **Art. 9(2)(a) — consenso esplicito** |
| **Diario alimentare** | Registro di ciò che si mangia | 🚨 **Art. 9(2)(a)** — vedi §3.4 |
| Allenamenti svolti, serie, ripetizioni | Storico dell'attività | Art. 6(1)(b) |
| Schede **modello** della palestra | Patrimonio della palestra | Art. 6(1)(f) — legittimo interesse |
| **Messaggi cifrati** | Recapito | Art. 6(1)(b) · ⚠️ **illeggibili per noi** |
| Chiave **pubblica** di cifratura | Recapito dei messaggi | Art. 6(1)(b) |
| Pacchetto cifrato della chiave d'account | Recupero su un dispositivo nuovo | Art. 6(1)(b) · ⚠️ **illeggibile per noi** |
| Registro di controllo (accessi, impersonazioni) | Sicurezza | Art. 6(1)(f) |
| Conteggi di token AI | Quota e fatturazione | Art. 6(1)(b) · 💡 **conteggi, non contenuti** |
| Dichiarazione di maggiore età e consensi, **con data e ora** | Prova ai sensi dell'art. 7(1) | Art. 6(1)(c) |
| 🆕 **Il comune che indichi** | Farti trovare palestre e trainer vicino a te | Art. 6(1)(b) · 💡 **facoltativo**, si può togliere |
| 🆕 **La foto del profilo**, se la carichi | Farti riconoscere da chi ti scrive | Art. 6(1)(a) — consenso, dato caricandola |
| 🆕 Quali schede **sponsorizzate** hai visto, e quando | Fatturare la pubblicità a chi la compra | Art. 6(1)(f) — legittimo interesse |

#### 🆕 Il comune, la foto e le schede sponsorizzate — cosa comporta

**Il comune non è la tua posizione.** Non usiamo il GPS e non lo chiediamo: indichi tu un comune da
un elenco, e serve solo a ordinare i risultati del catalogo. 💡 Puoi toglierlo quando vuoi, dal
profilo: l'app continua a funzionare per intero, perdi solo l'ordinamento per vicinanza.

**La foto del profilo è l'unica tua immagine che sta sui nostri server**, e la ragione è che serve a
farti riconoscere da chi ti scrive. ⚠️ Le foto dei progressi sono un'altra cosa e restano sul tuo
telefono (§3.2). Puoi togliere la foto quando vuoi.

🚨 **Le schede sponsorizzate: cosa registriamo davvero.** Quando il catalogo ti mostra una scheda a
pagamento, registriamo che *un* utente l'ha vista quel giorno — una volta al giorno per persona,
anche se apri il catalogo dieci volte. Serve a fatturare a chi compra la pubblicità.

⚠️ **Chi paga la pubblicità NON riceve il tuo nome, né sa che sei stato tu**: vede solo *quante
persone* ha raggiunto. E il dettaglio si cancella dopo **tredici mesi** — restano solo gli importi,
che non contengono nessun nome.

### 3.2 📱 Solo sul telefono — **non ci arrivano mai**

- **Sonno, fasi del sonno, variabilità cardiaca, battito a riposo** (da Health Connect / HealthKit)
  — 🚨 **salvo la casella «Sonno e recupero nel consiglio del giorno»**: se la accendi, questi dati
  vengono **trasmessi ad Anthropic** al momento del consiglio. Non li conserviamo noi, e senza il
  tuo consenso non partono
- **Peso, massa grassa, circonferenze**
- **Foto dei progressi**
- **Schede ricevute dal trainer** — 🚨 il *modello* resta sul server, ma **il legame fra la persona
  e il programma no**: da un programma post-infortunio si capisce cos'è successo a chi lo esegue
- La **chiave privata** che apre i messaggi

⚠️ **Conseguenza da dire chiaramente all'utente**: se disinstalla l'app senza un backup, questi dati
**si perdono**, e noi non possiamo ridarglieli. È il prezzo di non averli.

### 3.3 🔒 I messaggi: cosa vediamo davvero

| Vediamo | Non vediamo |
|---|---|
| **Che** due persone si sono scritte | **Cosa** si sono scritte |
| Quando, e quanti messaggi | Nemmeno una parola |

🚨 **Nemmeno con un accesso da amministratore, nemmeno durante un'impersonazione, nemmeno leggendo
il database.** Non è una regola del programma: la chiave non esiste sui nostri server.

### 3.4 🚨 Il diario alimentare e l'intelligenza artificiale

Per il consiglio del giorno e il riconoscimento dei pasti, **il testo del diario viene mandato ad
Anthropic PBC**, negli Stati Uniti.

⚠️ **Da ciò che una persona mangia si inferisce il suo stato di salute** (CGUE **C-184/20**): è
materiale dell'**art. 9**, e per questo il consenso è **separato** da tutti gli altri e si concede
a parte. Senza, il server rifiuta la chiamata — non è un controllo dell'interfaccia.

💡 **Quello che NON esce mai**: peso, misure, foto dei progressi. Esce il testo del diario e **un
numero calcolato sul telefono** (il fabbisogno calorico), che il server inoltra senza conservarlo.

### 3.3-bis 🆕 Sonno e recupero — **solo se lo accendi tu**

Dal 16/08/2026 il consiglio del giorno può tenere conto di come hai dormito. È una casella **a
parte**, spenta finché non la accendi, e si spegne da sola se togli il consenso all'AI.

**Cosa parte, esattamente** — nient'altro che questo:

| | |
|---|---|
| ore dormite, risvegli | quanto hai dormito |
| minuti di sonno profondo e REM | quanto è stato ristoratore |
| variabilità cardiaca e battito a riposo | **con la tua media**, perché da soli non vogliono dire niente |

🚨 **Non li conserviamo.** Il nostro server li riceve dalla tua app, li mette nella richiesta al
modello e li dimentica: non c'è nessuna tabella, nessuna colonna, nessun registro che li trattenga.

⚠️ **Anthropic li conserva fino a 30 giorni**, come tutto il resto di ciò che passa di lì.

💡 **Il consiglio non ti dirà mai cosa hai.** Al modello è vietato nominare apnee, disturbi del sonno
o stress: può usare quei numeri per dirti di andarci piano oggi, non per dirti che sei malato. Se
qualcosa sembra fuori scala per più giorni, l'unica cosa che farà è dirti di parlarne con il tuo
trainer o con un medico.

---

## 4. 🚨 Per quanto tempo

### 4.1 Da noi

| Dato | Quanto |
|---|---|
| Account e dati collegati | Finché l'account esiste |
| Dopo la cancellazione | **Cancellati subito**, con le eccezioni sotto |
| Riga utente | **Anonimizzata**, non cancellata — vedi §4.3 |
| Messaggi cifrati | Restano, **illeggibili da chiunque** — vedi §4.3 |
| Registro di controllo | ⚠️ **DA DECIDERE**: proposta **24 mesi** |
| Conteggi di token | ⚠️ **DA DECIDERE**: proposta **fino alla fine dell'esercizio fiscale + 10 anni** (art. 2220 c.c.) |
| 🆕 Comune indicato · foto del profilo | Finché li tieni: si tolgono in qualsiasi momento dal profilo |
| 🆕 **Chi ha visto quali schede sponsorizzate** | **13 mesi**, poi si cancella. 💡 Coprono il confronto con lo stesso mese dell'anno prima, che è la forma più lontana che può prendere una contestazione su una fattura |
| 🆕 Importi delle campagne pubblicitarie | Restano: **non contengono nessun nome** |

### 4.2 Presso Anthropic — **i numeri veri**

| | Quanto |
|---|---|
| Uso normale | **fino a 30 giorni** |
| Contenuto segnalato dai sistemi automatici | **fino a 2 anni** |
| Punteggi di classificazione di sicurezza | **fino a 7 anni** |

🚨 **I sette anni sono la riga scomoda, e va scritta.** È un dato *sul* contenuto di una persona, e
**sopravvive alla cancellazione dell'account da noi**: non possiamo cancellarlo, perché non è nostro
e non sta da noi. Ometterlo sarebbe esattamente il tipo di omissione che si paga.

⚠️ **E non dipende dallo ZDR**: i 2 anni sul contenuto segnalato valgono **anche** con un accordo di
zero data retention attivo. Noi comunque **non ne abbiamo uno**.

✅ **Anthropic non addestra modelli sul contenuto dei clienti** — è nei termini commerciali.

### 4.3 Perché qualcosa resta dopo la cancellazione

**La riga utente si anonimizza invece di sparire.** I messaggi puntano ad essa: cancellarla
porterebbe via **anche le risposte del trainer**, che è la sua documentazione professionale e può
servirgli a distanza di anni. Dopo l'anonimizzazione quella persona è «Utente eliminato», e i
messaggi restano **cifrati con una chiave che non esiste più**.

💡 Sono byte casuali accanto a un segnaposto: non identificano più nessuno.

---

## 5. A chi comunichiamo i dati

| Destinatario | Cosa riceve | Dove | Base del trasferimento |
|---|---|---|---|
| **La palestra e il trainer assegnato** | Anagrafica, allenamenti, diario — 🚨 **non** i messaggi, **non** peso e misure | UE | Contratto |
| **Anthropic PBC** | Testo del diario, fabbisogno calcolato | 🚨 **USA** | Art. 28 + **SCC** (dec. UE 2021/914) |
| **Firebase / Google** *(notifiche)* | Token del dispositivo, «hai un messaggio» — 💡 **mai il testo** | USA | Art. 28 + **SCC** |
| **Fornitore dell'hosting** | Tutto ciò che sta sul server | UE | Art. 28 |

⚠️ **Nessun dato viene venduto, e nessun dato va alla pubblicità.** Va detto esplicitamente: sarà
la prima domanda quando arriveranno le inserzioni sui piani gratuiti (requisito B9).

---

## 6. I diritti, e come si esercitano **davvero**

| Diritto | Come |
|---|---|
| **Accesso** (art. 15) | Al contatto privacy. 💡 I dati che stanno sul telefono si leggono dall'app |
| **Rettifica** (art. 16) | Dal profilo, subito |
| **Cancellazione** (art. 17) | ✅ **Profilo → Elimina account.** Dall'app, senza scrivere a nessuno |
| **Portabilità** (art. 20) | Al contatto privacy per i dati sul server. 💡 Per quelli sul telefono c'è il **file di backup**, esportabile dall'app |
| **Revoca del consenso** (art. 7(3)) | ✅ **Profilo → Privacy e consensi.** Togliere costa **quanto** mettere |
| **Opposizione** (art. 21) | Al contatto privacy |
| **Reclamo** | **Garante per la protezione dei dati personali** — `garanteprivacy.it` |

🚨 **La revoca non ha effetto retroattivo** (art. 7(3), terzo periodo): quello che è già stato fatto
resta lecito. Chi vuole anche la cancellazione usa «Elimina account». Nell'app è già scritto così.

---

## 7. 🚨 Minori

**Il servizio è riservato a chi ha compiuto 18 anni.** Alla registrazione si dichiara di essere
maggiorenni, la dichiarazione viene conservata con data e ora, e vale **su ogni porta d'ingresso**,
compreso l'accesso con Google e Apple.

⚠️ **È una dichiarazione, non una verifica** — e va detto. Non chiediamo un documento: sarebbe un
trattamento **più** invasivo di quello che stiamo proteggendo.

💡 **Perché 18 e non 14**: sotto i 14 anni servirebbe raccogliere e verificare il consenso di chi
esercita la responsabilità genitoriale; fra i 14 e i 17 la soglia cambia da Stato a Stato
dell'Unione. Una riga sola chiude il problema invece di gestirlo.

---

## 8. Modifiche a questa informativa

Se il trattamento cambia, questa pagina viene aggiornata e la data in cima cambia con lei. Le
modifiche che riguardano i consensi non si applicano da sole: vengono richieste di nuovo.
