# Prompt per lavorare con Claude Code

Apri una sessione di Claude Code nella cartella del plugin e incolla il prompt, sostituendo `<N>` con
il numero del punto della roadmap (es. `1.3`). Un punto per sessione: le sessioni corte restano precise.

## Prompt di lavoro

```text
Lavora sul punto <N> di docs/roadmap.md.

Prima di scrivere codice:
1. Leggi CLAUDE.md, docs/roadmap.md e docs/data-model.md.
2. Se il punto ha riferimenti ad Alpha, leggi quei file in ../easyrankly (branch Alpha) solo per capire la
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
- Verifica il comportamento sul sito locale con `studio wp ...` o nel browser, non solo con i test.
- Aggiorna docs/data-model.md, readme.txt (se cambiano servizi esterni), uninstall.php e tests/UninstallTest.php
  (se ci sono dati nuovi), e spunta il punto in docs/roadmap.md.
- Fai il commit con un messaggio in italiano che spiega il perché.
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
