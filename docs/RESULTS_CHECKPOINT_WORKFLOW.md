# Nastavak prekinutog results scrape-a

Na ovom lokalnom Mac projektu operator pokreće:

```bash
zsh "/Users/lukamutic/Local Sites/mma-future/output/safe-scrape/run-events.command"
```

Za proveru bez pristupa Tapologyju i bez promene `latest/` ili `state/`:

```bash
zsh "/Users/lukamutic/Local Sites/mma-future/output/safe-scrape/run-events.command" --preview
```

Launcher koristi postojeći Chrome profil, uvek headed, i čeka da operator ručno
otvori FightCenter. Postavi Results, MMA, All promotions i All regions, potvrdi
da je stvarna listing stranica vidljiva, pa pritisni Enter u terminalu.

Svaka tura ima najviše 10 mrežnih obrada event stranica, uključujući prazne,
amaterske i druge stranice bez upotrebljivih rezultata. Potvrđeni lokalni eventi
ne troše ovaj limit. Pauza između mrežnih obrada ostaje najmanje 10 sekundi,
a između listing stranica 20 sekundi. Prva greška/blokada prekida turu.
Ponovo pokreni istu komandu za sledeću malu turu kada je pristup normalan.

Events režim ne otvara stranice pojedinačnih borbi. Koristi potvrđene rezultate
sa event kartice; podaci koji tamo nisu dostupni, uključujući pre-fight rekord,
ostaju nepoznati. Time jedna event obrada ne pokreće dodatne zahteve po borbi.

## Šta se ponovo koristi

Launcher automatski pronalazi results checkpoint-e u lokalnim run folderima iz
poslednjih 48 sati, kao i zasebne lokalne recovery checkpoint-e pod
`data/recovery/results/`. Proverava JSON ugovor, izvorne ID-jeve, hash vrednosti,
coverage dokaze, opseg filtera i klasifikaciju iz sačuvanih DOM snimaka.
Koristi samo kompletne, završene evente i potvrđena izuzeća sa važećim parserom.
Delimični, nedostupni i zastareli izvori ostaju za mrežnu proveru; noviji
delimični snimak sprečava upotrebu starijeg kompletnog snimka.

Potvrđene prazne kartice, na kojima je jedini nerešen razlog nedostatak rezultata,
čekaju 24 sata od originalne provere pre ponovnog zahteva. I dalje su delimične
i ostaju navedene za dopunu; kartice koje već imaju neke završene borbe ne
preskaču se po ovom pravilu. Kada vreme čekanja istekne, ponovo su podobne za proveru.

`recover_results_snapshot` može lokalno pripremiti zaseban checkpoint iz kompletnog
DOM snimka sačuvanog pre prekida. Čuva izvorno vreme opažanja, ne dopisuje
nepoznate podatke i ne menja originalni run, `latest/`, operativni state ili bazu.

Posle uspešne ture provereni checkpoint eventi i novi rezultati prolaze kroz
standardni kumulativni commit. Postojeći interni ID-jevi opstaju. Originalno
vreme opažanja ostaje u coverage dokazu i zapisima o scrape-u.
Prekinuti izvorni run zadržava status `failed`.

Ako nova tura ponovo padne ili operator pritisne Ctrl+C, poslednje uspešno
stanje ostaje netaknuto. Novi checkpoint, sačuvani DOM snimci i manifest
omogućavaju naredni nastavak. Pregled se zapisuje u
`output/safe-scrape/events_resume_preview.json`, a konkretan run dobija
`results_resume_report.json` i odvojene brojače vraćenih i mrežno obrađenih evenata.

## CLI podrška

`run --results-only --resume-results-from /apsolutna/putanja/checkpoint_results.json`
prima jedan ili više ponovljenih checkpoint argumenata.
`--max-event-fetches 10` ograničava stvarne pokušaje otvaranja event stranica.
`--no-scrape-matchup-pages` isključuje zasebne stranice borbi, a
`--empty-results-cooldown-hours 24` uključuje odlaganje praznih kartica.
Ove opcije ne menjaju podrazumevano ponašanje postojećih runova.
Resume se ne kombinuje sa fresh/full scan ili prisilnim event proverama.

Uspešan mali batch ne znači da je završen pregled svih 15 listing stranica.
Manifest pokazuje razlog zaustavljanja i broj mrežnih obrada. Borci i UFC
provera ostaju posebni koraci nakon odgovarajućeg events opsega.

## Redovna tura boraca

Lokalni `output/safe-scrape/prepare_fighters.py` pravi indeks i queue iz novih,
izmenjenih i nepotpunih evenata poslednjeg uspešnog runa. Opšti manual review
starih evenata ne proširuje ovu turu. Raniji uspešni profili ostaju sačuvani.
Pripremu radi jednom za events turu, pre pokretanja browsera.

Pripremljeni queue operator pokreće:

```bash
zsh "/Users/lukamutic/Local Sites/mma-future/output/safe-scrape/run-fighters.command"
```

Komanda radi headed u postojećem Chrome profilu i obrađuje sve preostale borce
redom. Jedna vidljiva browser sesija ostaje otvorena tokom cele ture.
Svaki profil čuva kumulativno. Pauza je 2 sekunde između profila, bez dužih
grupnih pauza. Prva greška prekida turu bez automatskog ponavljanja.
Uspešno obrađeni u postojećem queue-u preskaču se; queue ne pravi ponovo
između nastavaka. Posle greške najpre proveri uzrok.
