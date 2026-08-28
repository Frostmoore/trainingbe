<?php

declare(strict_types=1);

namespace App\Models\Contracts;

/**
 * La tabella la cui libreria segue la persona, e non se ne va piu' — 3b-M.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«un utente deve poter aggiungere quanti esercizi vuole… devono essere
 * visibili solo a lui se e' un free_user, a lui e a tutti i suoi iscritti se e'
 * un free_trainer, un trainer o una palestra — e gli utenti che sono stati
 * iscritti con questi non devono piu' perdere quegli esercizi»*.
 *
 * ══ 🚨 PERCHE' UN'INTERFACCIA E NON UNA RIGA DENTRO LO SCOPE ══════════════
 *
 * ⛔ `TenantOrGlobalScope` e' generico: dice «il mio tenant, piu' le righe
 * globali». Se la regola dei trainer stesse dentro di lui **senza chiedere il
 * permesso al modello**, la prima tabella che domani usa
 * `BelongsToTenantOrGlobal` si ritroverebbe le righe di altri tenant **senza
 * che nessuno l'abbia deciso** — e nessun test se ne accorgerebbe, perche' vede
 * righe in piu', non in meno.
 *
 * 💡 Questa interfaccia rende la scelta **esplicita e per tabella**: la porta si
 * apre solo dove qualcuno l'ha scritto a mano.
 *
 * ⚠️ E' un contratto **senza metodi** di proposito: non chiede niente al
 * modello, dichiara una proprieta' della tabella. Un metodo da implementare
 * sarebbe stato codice copiato uguale in ogni modello che la usa.
 *
 * ══ ⚖️ COSA SIGNIFICA DAVVERO «NON DEVONO PIU' PERDERE» ═══════════════════
 *
 * 🚨 La visibilita' si aggancia alla **riga di legame** `trainer_member`, e
 * **non guarda `disattivato_il`**. Non e' una svista: la decisione D5 dice gia'
 * *«il legame resta, la storia si conserva, il canale si chiude»*, e quella riga
 * non si cancella mai.
 *
 * ⛔ Se la visibilita' finisse insieme al rapporto, una persona che cambia
 * trainer si ritroverebbe lo **storico degli allenamenti** pieno di esercizi che
 * non sa piu' leggere: non cancellati — muti. E' esattamente il danno che
 * `UnisciAUnaPalestra` esiste per evitare quando si entra in una palestra.
 */
interface LibreriaCondivisaConGliIscritti {}
