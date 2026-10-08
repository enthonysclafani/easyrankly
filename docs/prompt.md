# Prompt per lavorare con Claude Code

Funziona sia nel cloud sia in locale. Nel cloud avvia la sessione sul repository `enthonysclafani/easyrankly`,
branch `Refactory`; il prompt lo controlla comunque, perché il branch predefinito (`main`) contiene il vecchio plugin.

Un punto della roadmap per sessione: le sessioni corte restano precise.

## Prima sessione nel cloud (una volta sola)

```text
Fai `git fetch origin Refactory` e passa al branch Refactory se non ci sei già.
Leggi CLAUDE.md, poi verifica che l'ambiente sia pronto: controlla l'output di tools/cloud-setup.sh e lancia
`composer check`. Se qualcosa fallisce, correggi lo script di setup (non le regole), apri una pull request verso
Refactory e spunta "Prima sessione Claude Code nel cloud" in docs/roadmap.md. Dimmi cosa hai verificato.
```

## Prompt di lavoro

Sostituisci `<N>` con il numero del punto (es. `1.3`).

```text
Lavora sul punto <N> di docs/roadmap.md.

Prima di scrivere codice:
0. Fai `git fetch origin Refactory` e parti dal branch Refactory aggiornato.
1. Leggi CLAUDE.md, docs/piano.md, docs/roadmap.md e docs/data-model.md.
2. Se il punto ha riferimenti ad Alpha, leggi quei file con `git show origin/Alpha:<percorso>` solo per capire la
   logica e i casi limite già scoperti. Non copiarli: riscrivi secondo CLAUDE.md.
3. Presentami un piano breve e aspetta il mio ok:
   - file da creare o modificare;
   - dati che salverai (option, meta, post type, tassonomie) e come cambia docs/data-model.md;
   - hook del core che usi o filtri, e perché non serve altro;
   - test che scriverai (comportamento visibile, casi limite, utente senza permessi);
   - impatto sulle prestazioni del frontend.

Durante il lavoro:
- Scrivi i test insieme al codice. Lancia `composer architecture` spesso e `composer check` alla fine.
- Se ti accorgi che serve qualcosa fuori dal punto, una dipendenza, un hook pubblico o un'eccezione alle
  invarianti, fermati e chiedimelo.

Per chiudere:
- Verifica il comportamento con i test di integrazione (e, in locale, anche sul sito Studio).
- Aggiorna docs/data-model.md, readme.txt (se cambiano servizi esterni), uninstall.php e tests/UninstallTest.php
  (se ci sono dati nuovi), e spunta il punto in docs/roadmap.md.
- Fai il commit con un messaggio in italiano che spiega il perché e apri una pull request verso Refactory.
- Nel resoconto dimmi cosa hai verificato, cosa no, e se resta qualcosa per il punto successivo.
```

## Prompt di revisione

Da usare in una sessione nuova, dopo uno o più punti, per un controllo indipendente:

```text
Rivedi le modifiche dall'ultimo tag (o dagli ultimi <K> commit) rispetto a CLAUDE.md.
Cerca in particolare: query in più nel frontend, permessi mancanti o troppo larghi, input non sanitizzato,
output non escapato, dati non elencati in docs/data-model.md o non rimossi da uninstall.php, funzioni fuori
perimetro, astrazioni non necessarie. Per ogni problema indica file e riga, lo scenario concreto in cui si
manifesta e la correzione. Non modificare nulla finché non te lo chiedo.
```
