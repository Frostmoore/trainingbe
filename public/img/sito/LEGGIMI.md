# Le immagini del sito

Qui dentro stanno le immagini del sito pubblico. ✅ **Caricate tutte e sette il 16/08/2026.**

Se una sparisce il sito non si rompe: al suo posto compare un riempimento con i colori del
prodotto, e nessuno vede un'icona di immagine rotta. ⚠️ Il rovescio è che **una sparizione non si
nota**: se ne accorge solo `all_seven_images_are_actually_on_disk()`.

L'elenco autorevole sta in `app/Support/ImmaginiDelSito.php` (costante `ATTESE`). Questo file lo
ripete per chi apre la cartella invece del codice.

## Cosa serve

| File | Misura | Peso | Dove compare |
|---|---|---|---|
| `og.jpg` | 1200 × 630 | 91 KB | L'anteprima quando il link viene condiviso |
| `eroe.webp` | 1600 × 1200 | 46 KB | Lo sfondo dietro il telefono, in apertura della home |
| `platea-palestra.webp` | 800 × 600 | 26 KB | La scheda «Per le palestre» |
| `platea-trainer.webp` | 800 × 600 | 26 KB | La scheda «Per i trainer indipendenti» |
| `platea-solo.webp` | 800 × 600 | 33 KB | La scheda «Per chi si allena da solo» |
| `privacy.webp` | 1200 × 900 | 21 KB | La sezione «I dati del tuo corpo non stanno sui nostri server» |
| `prezzi.webp` | 1600 × 700 | 33 KB | La fascia in apertura della pagina dei prezzi |

**276 KB in tutto.** 🚨 `og` è l'unica in JPG: alcuni raccoglitori di anteprime non leggono il webp
e tornerebbero a mostrare il riquadro grigio. È l'unico posto in cui il formato lo decide qualcun
altro.

📌 **Gli originali non ritagliati** (1536 × 1024, PNG, ~2 MB l'uno) stanno nel repo documentale, in
`memory/immagini-sorgente/`, con i comandi esatti che hanno prodotto questi file. Non stanno qui
perché questa cartella è **pubblicata**, e quattordici megabyte di PNG verrebbero spediti a chi apre
il sito.

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
da compilare, nessun comando da lanciare: l'indirizzo che il sito scrive porta la data di modifica
del file, quindi la versione nuova si vede subito.

⚠️ **Per un ritaglio diverso si riparte dagli originali**, non da questi: questi sono già stati
tagliati e compressi una volta, e ricomprimerli somma i difetti.
