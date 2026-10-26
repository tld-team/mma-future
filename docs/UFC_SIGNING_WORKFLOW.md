# UFC potpisi: uputstvo za operatora

UFC roster se preuzima lokalno. WordPress ne kontaktira `roster.watch` i uvoz sam ne uklanja borca iz rangiranja. Borac izlazi iz rangiranja tek kada administrator potvrdi kandidata ili ručno evidentira vest.

## Redovni paket

Pre pravljenja redovnog paketa pokreni:

```bash
cd /putanja/do/mma-future/scraper
export MMAF_SCRAPER_DATA_DIR="/Users/lukamutic/Local Sites/mma-future/app/public/dev-tools/scraper/data"
.venv/bin/python -m mma_future_scraper.cli check-ufc-roster
.venv/bin/python -m mma_future_scraper.cli export-import-bundle --output output/import-ready/mmaf-import.zip
```

Uspešna provera atomski menja `data/latest/ufc_roster.json`. `data/latest/ufc_roster_status.json` pokazuje poslednji uspeh ili grešku. Ako je stranica blokirana, nepotpuna ili promeni strukturu, prethodni uspešan dokument ostaje netaknut. Redovni paket bez UFC dokumenta je validan; WordPress izveštaj navodi poslednji uspešan UFC uvoz. UFC dokument stariji od 48 sati preskače se uz upozorenje, dok se zastareo zaseban UFC paket odbija.

## Zasebna UFC provera

Kada treba proveriti samo UFC stanje:

```bash
cd /putanja/do/mma-future/scraper
export MMAF_SCRAPER_DATA_DIR="/Users/lukamutic/Local Sites/mma-future/app/public/dev-tools/scraper/data"
.venv/bin/python -m mma_future_scraper.cli check-ufc-roster
.venv/bin/python -m mma_future_scraper.cli export-ufc-roster-bundle --output output/import-ready/ufc-roster.zip
```

ZIP se otprema kroz postojeću stranicu **Regular Import**. Importer pre pisanja proverava manifest, veličinu i SHA-256 svakog dokumenta. Posle pregleda plana import treba odobriti na isti način kao redovni paket.

Pre uvoza nove verzije koda pokreni proverenu migraciju `wp mmaf system migrate`. U import pregledu proveri ciljnu bazu/sajt, identifikatore događaja, profila i UFC snimka, kao i broj novih kandidata i konflikata.

Sačuvani UFC snimak može se ponovo porediti bez mrežnog zahteva:

```bash
wp mmaf ufc status --format=json
wp mmaf ufc compare --dry-run --format=json
wp mmaf ufc compare --apply --format=json
wp mmaf background work
```

`--fighter-id=ID` sužava poređenje na jednog kanonskog borca. `--apply` vraća ID posla; `status` prikazuje njegov napredak. Admin dugme **Ponovi poređenje** radi isto.

## Administratorska potvrda

1. Otvori **Review → UFC Signings**.
2. Uporedi MMA Future borca, roster.watch profil, datum rođenja i prikazane konflikte.
3. Uz obrazloženje izaberi **Potvrdi UFC**, **Različit borac**, **Isti borac — nedovoljan dokaz** ili **Odloži proveru**. Za potvrdu uprkos konfliktu dodaj i nezavisan URL dokaza.

Odbijena veza identiteta opstaje kroz naredne uvoze. Odluka „Isti borac — nedovoljan dokaz” ponovo se otvara samo kada se relevantan dokaz promeni. Zatvorenu odluku administrator može eksplicitno ponovo otvoriti uz obrazloženje. Promena identiteta ili dokaza između otvaranja ekrana i slanja odluke zahteva osvežavanje pregleda.

Potvrda odmah postavlja trajnu UFC oznaku, upisuje događaj i audit zapis, prazni javni keš i zakazuje pripremu novog ranking drafta. Ne aktivira novi ranking automatski. Stari draft koji i dalje sadrži potvrđenog potpisnika ne može biti aktiviran.

Za vest koja još nije stigla na roster.watch otvori pojedinačnog borca u **Canonical Fighters** i u odeljku **UFC signing** unesi URL dokaza, obaveznu napomenu i stvarni datum potpisa samo ako je poznat. Datum roster promene i vreme našeg otkrivanja nisu datum ugovora.

Ako je potvrda bila pogrešna, na istom mestu koristi **Correct incorrect confirmation** i upiši obavezno obrazloženje. Ispravka ostaje zabeležena u istoriji i ne vraća automatski borca u ranking ako ne ispunjava ostale uslove.
