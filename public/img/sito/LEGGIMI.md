# Le immagini del sito

Qui dentro vanno i file delle immagini del sito pubblico. **Finché non ci sono, il sito funziona
lo stesso**: al loro posto compare un riempimento con i colori del prodotto, e nessuno vede
un'icona di immagine rotta.

L'elenco autorevole sta in `app/Support/ImmaginiDelSito.php` (costante `ATTESE`). Questo file lo
ripete per chi apre la cartella invece del codice.

## Cosa serve

| Nome del file | Misura | Dove compare |
|---|---|---|
| `og.jpg` | **1200 × 630** | L'anteprima quando il link viene condiviso (WhatsApp, Telegram, LinkedIn) |
| `eroe.jpg` | **1600 × 1200** | Lo sfondo dietro il telefono, in apertura della home |
| `platea-palestra.jpg` | **800 × 600** | La scheda «Per le palestre» |
| `platea-trainer.jpg` | **800 × 600** | La scheda «Per i trainer indipendenti» |
| `platea-solo.jpg` | **800 × 600** | La scheda «Per chi si allena da solo» |
| `privacy.jpg` | **1200 × 900** | La sezione «I dati del tuo corpo non stanno sui nostri server» |
| `prezzi.jpg` | **1600 × 700** | La fascia in apertura della pagina dei prezzi |

## Regole

🚨 **Le misure non sono un suggerimento.** Sono scritte negli attributi `width`/`height` del
componente e servono a riservare lo spazio **prima** che l'immagine si carichi. Un rapporto diverso
fa saltare la pagina mentre viene letta.

🚨 **Nessuna immagine può mostrare l'interfaccia dell'app.** Il telefono in apertura è disegnato in
HTML apposta: una schermata generata mostrerebbe un'app che non esiste, e il primo che la scarica se
ne accorge. Le fotografie danno il tono, non fanno affermazioni sul prodotto.

🚨 **Nessun logo di palestra, nessun volto presentato come cliente.** Non abbiamo clienti; farlo
sembrare il contrario è la stessa bugia dei numeri inventati.

⚠️ **`webp` se possibile**, altrimenti `jpg`. Il componente preferisce `webp` quando trova entrambi,
e a parità di resa pesa la metà: il server è condiviso con altri due siti.

⚠️ **Sotto i 300 KB l'una**, `og.jpg` sotto i 200 KB — alcuni servizi di messaggistica scartano le
anteprime più pesanti e tornano a mostrare il riquadro grigio.

💡 **Il nome non ha bisogno di un'impronta.** L'indirizzo che il sito scrive porta già la data di
modifica del file (`?v=...`): si sovrascrive il file e la nuova versione si vede subito, senza
svuotare nessuna cache.

## Come si sostituiscono

Si copia il file qui dentro con il nome esatto della tabella. Non c'è niente da rigenerare, niente
da compilare, nessun comando da lanciare.
