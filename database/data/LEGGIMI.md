# I dati precaricati — da dove vengono

> ⚠️ Questi file **non sono nostri**. Ognuno ha una fonte e una licenza, e vanno
> citate. Vale la stessa regola già applicata al catalogo degli alimenti (CREA e
> Open Food Facts): i dati non si inventano e la provenienza si scrive.

---

## `comuni.jsonl` — 7.896 comuni italiani

Una riga per comune, in JSONL. 💡 Non JSON indentato: pesa la metà, e quando
l'ISTAT pubblica un elenco nuovo il `diff` mostra le righe cambiate invece di un
blocco unico riscritto.

### Le colonne

| Chiave | Cosa contiene | Da dove |
|---|---|---|
| `c` | Codice ISTAT a 6 cifre, con gli zeri davanti (`001001`) | ISTAT |
| `n` | Denominazione in italiano | ISTAT |
| `a` | Denominazione nell'altra lingua, se c'è (`Bozen`) | ISTAT |
| `p` | Sigla della provincia (`TO`) | ISTAT |
| `pn` | Nome della provincia (`Torino`) | ISTAT |
| `r` | Regione | ISTAT |
| `cap` | CAP principale — 🚨 **il primo**, non l'unico | comuni-json |
| `lat` · `lng` | Coordinate del centro | italy_geo |

### Le tre fonti

1. **ISTAT — «Elenco dei comuni italiani»**
   `https://www.istat.it/storage/codici-unita-amministrative/Elenco-comuni-italiani.csv`
   È **la spina dorsale**: l'elenco ufficiale e aggiornato. Codifica `Windows-1252`,
   separatore `;`. Licenza **CC BY 4.0** — l'attribuzione è dovuta, ed è questa riga.

2. **`matteocontrini/comuni-json`** — per il **CAP**.
   Derivato ISTAT. Aggancio sul codice a 6 cifre. Copre 7.887 comuni su 7.896.

3. **`MatteoHenryChinaski/Comuni-Italiani-2018-...`** — per le **coordinate**.
   ⚠️ **Vintage 2018**: i comuni nati o fusi dopo restano senza coordinate — sono
   **57**, e per loro la vicinanza ripiega su provincia e regione (vedi
   `Vicinanza`). Aggancio sul codice ISTAT **numerico**, che lì è scritto senza
   zeri davanti (`1001`, non `001001`).

### 🚨 Perché le coordinate ci sono, se il GPS è stato escluso

Sono le coordinate **del comune**, non della persona. La decisione di §4.1 del
piano — *«vicino a me» è la città scelta a mano, non il GPS* — riguarda il non
mandare al server **la posizione di qualcuno**. Il fatto che Bologna disti 40 km
da Modena è un dato pubblico, e permette di ordinare i risultati per distanza
vera invece di inventare una tabella di province confinanti scritta a mano.

💡 Ed è più preciso di «stessa provincia»: chi sta a Rimini ha Pesaro a 30 km e
Ferrara a 100, ma Pesaro è in un'altra regione.

### Come si rigenera

Il file **non** si modifica a mano. Si ricostruisce con lo script che unisce le
tre fonti, e si ricommitta. ⚠️ Non lo fa un comando dell'applicazione di
proposito: un import che scarica da tre URL a ogni esecuzione è un import che si
rompe il giorno che uno dei tre sparisce.

Per caricarlo nel database:

```
php artisan comuni:importa
```

---

## `illustrazioni/` — 305 disegni, e `illustrazioni.jsonl`

Un PNG per esercizio, 512×512, **grigio + alfa**: il colore non c'è perché non
serve — la forma sta tutta nel canale alfa, e l'app la tinge a schermo.

### 🚨 Perché sono bianchi, e perché è voluto

Il tratto originale è `fill="#fff"` su fondo trasparente. ⛔ Su una card chiara
sarebbero **invisibili**. Si tengono così e si colorano nell'app
(`Miniatura.tinta`, `BlendMode.srcIn`): lo stesso file serve tema chiaro e tema
scuro, e nessuno deve mantenere due parchi immagini.

💡 E c'è una seconda ragione, legale: **tingere a schermo non crea un'opera
derivata da ridistribuire**. Il file che spediamo resta l'originale, e la
clausola *ShareAlike* non ha niente da mordere.

### Le colonne di `illustrazioni.jsonl`

| Chiave | Cosa contiene |
|---|---|
| `s` | Slug, cioè il nome del file senza `.png` |
| `n` | Nome inglese, come nella fonte |
| `m` · `q` | Muscolo primario e attrezzo secondo la fonte |
| `c` | 🚨 **Il credito da mostrare**, per esteso |
| `u` | L'indirizzo della fonte |

### Le due fonti

1. **`bryllim/workout-guide`** — 302 esercizi, tre fotogrammi ciascuno.
   Codice **MIT**, disegni **CC BY-SA 4.0**. Si prende il **fotogramma 2**: è la
   posizione di lavoro, quella che fa riconoscere il movimento, ed è disegnata
   più grande delle altre due. ⚠️ Verificato guardando un campione, non dedotto.

2. **`everkinetic/data`** — **CC BY-SA 4.0**, ed è la fonte da cui deriva anche
   la prima. Se ne prendono **tre soli**, per esercizi che a workout-guide
   mancano. ⚠️ Disegna nero su fondo bianco: vanno rovesciati per stare accanto
   agli altri, e i tre file di partenza stanno in `everkinetic/`.
   ⛔ **Altri due candidati sono stati scartati**: il rasterizzatore sbaglia il
   loro disegno (una zeppa nera in mezzo alla figura). 🚨 Una figura sbagliata è
   peggio di un segnaposto — nessuno la legge come un errore.

### ⚖️ L'attribuzione è una condizione della licenza

CC BY-SA 4.0 permette l'uso **anche commerciale**, ma solo dando credito. ⛔
Senza, l'uso non è autorizzato. Il credito viaggia come proprietà del file
caricato e arriva all'app in `image_credit`; l'app lo scrive sotto l'esercizio
nella pagina della scheda.

🚨 **`null` quando la foto l'ha caricata la palestra**: quella è loro, e
attribuirla a Bryl Lim sarebbe un credito **falso**.

### ⛔ Due disegni non sono attaccati a nessun esercizio

`bent-over-rear-delt-raise` e `chair-dip`: dicono la stessa cosa di «Alzate
posteriori» e «Dips su panca». 💡 I file restano perché la cartella è uno
specchio fedele della fonte, ma un quasi-doppione nel catalogo farebbe scegliere
a caso `ExerciseMatcher`.

### Come si rigenera

Come i comuni: **non a mano**, e non a ogni avvio dell'applicazione.

```
git clone --depth 1 https://github.com/bryllim/workout-guide.git /tmp/wg
php database/data/costruisci-illustrazioni.php /tmp/wg
```

Serve `magick` (ImageMagick) nel PATH. Per caricarli nel database:

```
php artisan esercizi:illustrazioni
```

⚠️ Il comando è **idempotente** e non sostituisce le foto caricate a mano dalle
palestre (`--forza` per farlo lo stesso).
