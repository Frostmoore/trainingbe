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
