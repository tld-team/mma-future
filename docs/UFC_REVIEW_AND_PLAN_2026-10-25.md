# UFC provera: pregled postojećeg sistema i plan razvoja

Datum: 25. oktobar 2026. Obuhvat: lokalni izvorni kod, postojeći JSON podaci, lokalna WordPress baza `local`, postojeći testovi i javne roster.watch stranice. Staging i produkciona baza nisu proveravani. Skrejper za UFC proveru nije pokrenut; postojeći izvori nisu uvezeni niti su menjane uredničke odluke.

## Zaključak

Već postoji upotrebljiv početni tok: roster.watch preuzimanje → JSON → ZIP → Regular Import → UFC kandidati → administratorska potvrda → trajno isključenje i opponent-only praćenje. Razvoj treba nastaviti na tom toku.

Najveće prepreke su nepovezanost sa najnovijim uvozima, pogrešno označavanje zapisa prisutnih samo u logu, ograničeno poređenje imena, zastarevanje dokaza i nedovoljno informacija na ekranu za ručnu odluku. Pronađeni problemi uglavnom mogu dovesti do propuštenih ili pogrešno predstavljenih kandidata; sadašnji importer sam ne potvrđuje UFC potpis.

Predloženo pravilo ostaje saglasno postojećim `UFC_SIGNING_WORKFLOW.md` i `FIGHTER_TRACKING_WORKFLOW.md`: potvrđen ulazak/potpis za UFC trajno isključuje borca iz MMA Future sistema rangiranja, i nakon izlaska iz UFC-a. Signal iz izvora sam po sebi nije potvrda. DWCS, TUF, Road to UFC, glasina ili zakazan nastup bez dovoljno dokaza ne smeju sami postati potvrđen ugovor.

## 1. Stvarno stanje podataka

### Aktivni i stari direktorijum

Aktivni `DATA_DIR` je `/Users/lukamutic/Local Sites/mma-future/app/public/dev-tools/scraper/data`. Podrazumevana putanja se dobija iz stvarne lokacije Python paketa, uz mogućnost promene preko `MMAF_SCRAPER_DATA_DIR`.

`/Users/lukamutic/Local Sites/mma-future/scraper/src` je simbolički link na novi izvorni kod, ali `scraper/data` je zaseban, stariji direktorijum. U njemu su rezultati od 5. oktobra i profilni izveštaj od 8. oktobra. Za sledeći test eksplicitno zadati aktivni direktorijum. Opcija `--latest-dir` sama ne premešta i `RUNS_DIR`; promenljiva `MMAF_SCRAPER_DATA_DIR` premešta oba.

| Aktivni artefakt | Zatečeno stanje |
| --- | --- |
| `results.json` | 23. oktobar; 289 događaja i 1.487 zadržanih borbi |
| `fighter_profiles.json` | 25. oktobar; 2.952 profila, 2.942 uspešna i 10 blokiranih; uspešnost 99,66% |
| `ufc_roster.json` | 20. oktobar po vremenu Beograda; `run_id=ufc-20261019T234757Z-91c8977e`; ispravan sačuvani checksum |
| UFC sadržaj | 705 redova trenutnog rostera, 2.115 redova bivšeg rostera, 1.370 promena; ukupno 2.910 objedinjenih identiteta |
| Zapisi samo iz loga | 91; od toga 52 imaju `added`, bez `removed`, a ipak su označeni `former` |

Deset blokiranih profila treba zadržati kao neuspele/nepotpune provere. Njihov nedostajući UFC podatak ne znači da nisu u UFC-u. Nijedan od sadašnjih svežih profila nema popunjen `current_roster` signal; to takođe nije negativan dokaz.

Event coverage ima delimične stavke, a `daily_summary.json` i `manual_review.json` trenutno ne postoje u aktivnom `latest`. Redovni exporter ih pravi na zamrznutoj kopiji i primenjuje svoje provere. Završeno skrejpovanje zato treba razlikovati od uspešno validiranog i uvezenog paketa. Zaseban UFC paket ne zavisi od tih fajlova.

### Lokalna WordPress baza

Čitana je neposredno, kroz transakciju samo za čitanje, bez podizanja WordPress aplikacije i bez migracija.

| Provera | Nalaz |
| --- | --- |
| Verzija šeme | `1.15.0`; sve tri UFC tabele postoje |
| Kanonski borci | 9.804; svi imaju javni i rankable flag; `in_ufc=1`: 0 |
| `mmaf_ufc_roster_observations` | 0 |
| `mmaf_ufc_signing_candidates` | 0 |
| `mmaf_ufc_signing_events` | 0 |
| Poslednji uspešan UFC uvoz | opcija `mmaf_ufc_roster_last_import` ne postoji |
| Najnoviji sačuvani profil u WP | 8. oktobar |
| Poslednji kanonski Tapology import run | 7. oktobar |
| Poslednji Regular Import posao | career-history, završen 8. oktobra |
| Aktivni ranking | lokalni run 21, formula v1.5, od 12. oktobra |

Ova lokalna baza ne predstavlja novi scrape od 23–25. oktobra. Ne zaključujemo da staging ima isto stanje.

### Probno poređenje već sačuvanih podataka

Bez mrežnog skrejpovanja i bez upisa kandidata proverena je ista normalizacija imena koju koristi `UfcSigningService`:

- Stari UFC snimak naspram lokalne kanonske baze: 213 roster identiteta daju 215 parova sa borcima; 98 parova ima isti datum rođenja, 23 različit datum, a 94 nema oba datuma. Nijedan par nije UFC potvrda.
- Isti snimak naspram svežih 2.952 profila: 56 parova po normalizovanom prikaznom imenu; 39 ima isti datum rođenja, 9 konflikt, a 8 nema oba datuma. Ova druga provera ne koristi kanonske aliase i nije puna simulacija budućeg WP uvoza.

Broj 215 i broj 56 odnose se na različite skupove. Nisu očekivani rezultat narednog svežeg skrejpovanja. Sačuvani zbirni nalazi: [local-evidence.json](</Users/lukamutic/Local Sites/mma-future/output/ufc-review-20261025/local-evidence.json>).

## 2. Šta je već implementirano

| Deo sistema | Postojeće ponašanje |
| --- | --- |
| UFC skrejper | Posećuje Current Roster, Former Roster i Log; ne otvara pojedinačne profile. Čuva imena, izvorni ključ/URL, DOB, pol, kategoriju, status, log događaje i izvorne ćelije. |
| Zaštita snimka | Sva tri izvora moraju imati podatke; proveravaju se HTTP/blokade, minimalni brojevi i pad ispod 60% prethodnog broja. `latest` se menja tek posle uspešnih provera; neuspeh ostavlja prethodni dobar snimak. |
| Transport | `export-ufc-roster-bundle` pravi zaseban ZIP; redovni i delta exporter mogu uključiti `ufc_roster.json`. Manifest proverava veličine i SHA-256. |
| Uvoz | Regular Import poznaje `mode=ufc_roster`; proverava dokument, zahteva pregled/odobrenje uvoza, pravi backup i proverava da UFC uvoz nije promenio zaštićene odluke o borcima. |
| Poređenje | U WP-u pri uvozu UFC dokumenta; tačno normalizovano kanonsko ime ili alias; DOB pojačava dokaz ili upisuje konflikt. Više boraca istog imena ostaje vidljivo za proveru. |
| Red za proveru | `Review → UFC Signings`, statusi `pending`, `deferred`, `rejected`, `confirmed`; potvrdi, odbaci identitet ili ostavi za pregled. |
| Ručna vest | URL i obavezna napomena, opcion stvarni datum potpisa; dostupno i na pojedinačnom borcu. |
| Potvrda | Trajni signing događaj i audit; `in_ufc=1`, `opponent_only`, `is_public=0`, `is_rankable=0`, `ineligible_ufc`. Borbe i identitet ostaju sačuvani. |
| Sajt i ranking | Javne ranking liste filtriraju potvrđenog UFC borca i iz aktivnog starog snimka, dopunjavaju Top 15 i renumerišu prikaz. Stari draft sa takvim borcem ne može da se aktivira. Zakazuje se novi obračun/draft, uz odvojenu aktivaciju. |
| Ispravka greške | Deaktivira potvrdu, dodaje korekciju i zadržava borca privatnim/opponent-only do zasebnog odobrenja povratka. |
| Povratna veza ka skrejperu | Postoji tracking export; uz prosleđen manifest opponent-only borci preskaču redovno obogaćivanje profila. |

Aktuelne javne stranice i dalje izlažu [trenutni roster](https://www.roster.watch/), [bivši roster](https://www.roster.watch/former.html) i [log dodavanja/uklanjanja](https://www.roster.watch/log.html). Log ima datum promene i tekst dodavanja ili uklanjanja, uključujući zapise bez linka. To je izvor za pronalaženje kandidata; datum dodavanja ne proglašavamo datumom ugovora.

## 3. Konkretne rupe koje treba zatvoriti

### P1 — Podaci su preuzeti, ali poređenje nije izvršeno u ciljnoj bazi

`check-ufc-roster` samo preuzima i pravi JSON. `export-ufc-roster-bundle` samo pakuje. Kandidati nastaju tek u `UfcSigningService::import_document()` pri odobrenom WP uvozu. Lokalni UFC JSON postoji, ali lokalni UFC uvoz nikada nije urađen. Sveži profili takođe nisu u lokalnoj kanonskoj bazi.

**Potrebno:** u operativnom toku evidentirati ciljnu bazu/sajt, run događaja, run profila i run UFC snimka. Novi podaci moraju prvo stići u odgovarajuću kanonsku bazu, ili biti u istom paketu koji uvozi results → profiles → UFC. Zaseban UFC uvoz poredi sa onim što WP tada već ima.

### P1 — Nov potpis prisutan samo u logu dobija `former`

U `build_document()` svaki log identitet koji nije na roster listama dobija `roster_status='former'`, čak i kada jedini događaj kaže `added`. To je reprodukovano malim lokalnim primerom. Problem postoji i u sačuvanom stvarnom snimku: 52 takva zapisa imaju samo dodavanje.

**Potrebno:** odvojiti `current`, `former`, `log_only/unknown` i tip dokaza `added/removed`. Ne zaključivati trenutni ugovorni status iz odsustva na listi. Promena zahteva podršku novog statusa i na PHP validatoru; ne menjati samo Python. Ako nema individualnog URL-a, dokaz voditi na log stranicu, uz datum i sačuvan tekst. Bez negativnog dokaza nema automatskog vraćanja već potvrđenog borca.

### P1 — Nema samostalnog ponovnog poređenja

Jedino pronađeno pozivanje uvoza/poređenja UFC dokumenta dolazi iz UFC batch-a importera. Običan kasniji uvoz boraca/profila bez `ufc_roster.json` ne pokreće poređenje sa već sačuvanim rosterom. Poruka uz unmatched listu obećava šire ponašanje nego što je trenutno implementirano.

**Potrebno:** izdvojiti ponovljivo poređenje koje radi nad sačuvanim opažanjima, bez novog mrežnog preuzimanja. Pozvati ga nakon uvoza/izmene imena, aliasa ili DOB-a, povezivanja identiteta i merge-a boraca; dodati ručno pokretanje i pregled rezultata. Obrada treba da bude u postojećem job/worker sistemu, sa checkpoint-om i jasnim završnim statusom.

### P1 — Dokazi kandidata zastarevaju, a odluke nemaju dovoljan kontekst

`INSERT IGNORE` sprečava duplikat para, ali ne osvežava postojeći `match_reason` ili `conflicts_json`. Roster opažanje se ažurira, dok stari konflikt može ostati. Isto važi kada se promeni DOB borca. Kandidat pamti rešavaoca i vreme, ali odbijanje/odlaganje nema zaseban audit zapis ni obrazloženje. Potvrda nema proveru da je dokaz još isti kao na ekranu koji administrator gleda.

**Potrebno:** osvežavati izračunate činjenice nezavisno od ručne odluke. Čuvati verziju matchera, poslednju proveru, relevantan fingerprint dokaza i verziju identiteta, kao i podatke na kojima je odluka doneta. Fingerprint treba da isključi promenljive run/timestamp vrednosti kako identičan novi scrape ne bi stalno ponovo otvarao stavku. Čuvati ručno odbijanje istog para; materijalno promenjen dokaz označiti za ponovni pregled po jasno definisanim pravilima. Svaka odluka dobija audit, napomenu i stanje pre/posle.

### P1 — Log se čuva, ali se ne prikazuje administratoru

Upit učitava `events_json`, pol i kategoriju, ali ekran uglavnom prikazuje ime, DOB, roster status i vreme našeg opažanja. Ne prikazuje konkretan `added/removed` događaj ni njegov datum. Log-only stavka bez URL-a ima prazan link. Nema jasno odvojenih pojmova „isti čovek“ i „dokazan UFC ulazak“.

**Potrebno:** uporedni pregled oba profila, link na kanonskog borca i Tapology, roster/log link, relevantne promene i poslednje opažanje, DOB/pol/kategorija i razumljivo objašnjenje konflikta. Omogućiti „isti borac, ali UFC potpis nije dokazan“, uz razlog; ne prisiljavati administratora da koristi „Not the same fighter“ za takav slučaj. Kod konflikta tražiti dodatnu napomenu/dokaz za potvrdu. I veoma jako podudaranje ostaje ručna odluka.

### P2 — Poređenje propušta varijante imena

Postojeće poređenje je tačna jednakost posle uklanjanja akcenata i interpunkcije. Ne rešava pouzdano inicijale (`A.J.` / `AJ`), drugačiju transliteraciju, promenu prezimena, redosled ili skraćeno ime ako alias već ne postoji. Pol i kategorija trenutno ne učestvuju u proveri konflikata.

**Potrebno:** prvo trajna ručno potvrđena veza source identiteta i kanonskog ID-a; zatim tačno ime/alias + DOB, pa tačno ime/alias bez DOB-a. Tek zatim ograničeni predlozi sličnih imena uz dodatne podatke. Pol može biti jak konflikt; promena težinske kategorije je samo pomoćni signal. Datum rođenja sam nikada nije dovoljan za identitet. Beležiti konkretne razloge, bez lažnog procenta sigurnosti.

### P2 — Nedostaje pregledan životni ciklus identiteta

Nema eksplicitne source→fighter veze kao nezavisne odluke, nema objedinjavanja name-only ključa sa kasnije dobijenim profilnim URL-om, niti razrešenja drugih kandidata istog izvornog identiteta. I već potvrđen borac može ponovo dobiti kandidat-par iz drugog opažanja. Upiti za kandidate i unmatched ne filtriraju naknadno soft-deleted/merged borce.

**Potrebno:** verifikovane pozitivne i negativne veze; konsolidacija promenjenih izvornih ključeva uz istoriju; prenos preko kanonskog merge-a; odvajanje stvarnog duplikata borca od različitih ljudi sa istim imenom. Kod potvrde razrešiti povezane stavke ili ih označiti kao konflikt identiteta, uz audit. Već potvrđene prikazivati u istoriji, bez nove pending buke.

### P2 — Svežina i nepotpun scrape nisu dovoljno vidljivi

Skrejper štiti poslednji dobar JSON, ali zaseban exporter proverava uglavnom šemu i postojanje hash-a; može ponovo spakovati stari snimak. PHP uvoz nema pravilo starosti niti zabranu vraćanja starijeg snimka preko novijeg. Minimalni brojevi štite od velikih ispada, ali nisu dokaz pune pokrivenosti.

**Potrebno:** prikazati poslednji pokušaj, poslednji uspeh i starost izvora; imati politiku svežine nezavisnu od učestalosti ostalih scrape-ova, npr. cilj do 24 h za proveru novih potpisa i vidljivo upozorenje posle 48 h, kao predložene podesive vrednosti. Stariji paket ne sme tiho pregaziti novije opažanje. Sačuvati podatke o parsiranju svake stranice, pokrivenosti polja, nepoznatim akcijama i sirovom dokazu pri grešci. Neuspešno preuzimanje ne potvrđuje ni „nije u UFC-u“ ni „nema promena“.

### P2 — Limit 500 skriva stvarno stanje reda

I kandidati i unmatched imaju fiksni `LIMIT 500`, bez paginacije i stvarnog ukupnog broja. Naslov prikazuje broj učitanih redova. UFC stavke nisu dodate u postojeći zbirni Review summary. Nema prikaza istorije confirmed/rejected odluka na tom ekranu.

**Potrebno:** paginacija, tačni brojači, filteri i istorija; prioritet dati borcima u javnom/aktivnom rankingu, zatim svežim dodavanjima, konfliktima i ostalima. Dodati vidljiv UFC badge uz borca i broj otvorenih stavki u admin pregledu. Unmatched nisu automatski greške i ne treba praviti kanonske borce za ceo UFC roster.

### P2 — Import preview ne pokazuje stvarni plan poređenja

`check_ufc` trenutno proverava dokument i dodaje jednu stavku sa brojem izvornih boraca. Tek primena stvara pojedinačne kandidate. Administrator pre uvoza nema dobar pregled koliko bi kandidata, konflikata ili postojećih odluka bilo zahvaćeno.

**Potrebno:** isti matcher za dry-run i primenu, sa brojačima new/updated/unchanged/open/rejected-preserved/already-confirmed/unmatched, uz sažetak prioriteta i konflikata. Vezati plan za verziju roster snimka i verziju kanonskih identiteta/aliasa, uz ponovno računanje ako se promeni bilo koji ulaz.

### P2 — UI status i arhiviranje nisu usklađeno predstavljeni

Tema ima „Signed with the UFC“ baner, ali potvrda istovremeno postavlja `is_public=0` i prebacuje post u draft. Normalni posetilac zato ne dolazi do tog banera na arhiviranom profilu. To odgovara novijoj tracking politici, ali nije javni arhivski profil.

**Potrebno:** za ovu isporuku zadržati postojeći privatni `opponent_only` model i to jasno dokumentovati/testirati. Ime i istorija protivnika ostaju u tuđim borbama, bez javnog linka ka privatnom profilu. Eventualni javni alumni profil bio bi zasebna promena proizvoda.

### P2 — Operativna integracija mora biti proverena i verzionisana

UFC servis, test i uputstvo trenutno su među untracked fajlovima, uz ranije izmene drugih delova projekta. Lokalno prisustvo ne dokazuje da ih staging/produkcija ima. HTTP admin POST je već pod postojećim `WriteGuard::adminGuard`; servisu ipak treba eksplicitan guard za budući CLI/worker put, uz poštovanje spoljne transakcije u importeru. Post-commit invalidacija, sync posta i zakazivanje obračuna dele jedan callback; delimičan neuspeh mora imati pouzdan ponovljiv oporavak.

**Potrebno:** u razvojnom paketu obuhvatiti sve zavisne fajlove, dodati verzionisanu migraciju ako se šema menja, i proveriti instalaciju na postojećoj i praznoj šemi. Sačuvati postojeći mehanizam backup-a, zaključavanja i ručne aktivacije rankinga. Promene praviti na grani sa prefiksom `c/`, bez uključivanja drugih zatečenih izmena.

## 4. Predloženi tok nakon razvoja

1. Skrejper snimi sva tri izvora i proveri kvalitet; istorijski log ostaje izvor događaja, bez izmišljanja trenutnog statusa.
2. Exporter upakuje konkretan uspešan i dovoljno svež snimak sa dokazima i verzijom ugovora podataka.
3. WP prikaže dry-run poređenja nad svojom ažurnom kanonskom bazom.
4. Odobren uvoz čuva opažanja, veze i kandidate; ne menja `in_ufc`, tracking niti javne flagove.
5. Novi kanonski borac ili promena njegovog identiteta pokreće lokalno ponovno poređenje sa poslednjim odgovarajućim snimkom, bez novog mrežnog zahteva.
6. Administrator vidi dokaz i odlučuje: potvrđen UFC ulazak; različit identitet; isti identitet bez dovoljnog UFC dokaza; odloženo istraživanje. Svaka odluka je objašnjiva i evidentirana.
7. Tek UFC potvrda aktivira trajno isključenje, postojeće opponent-only praćenje, čišćenje javnog prikaza i novi draft.
8. Korekcija pogrešne potvrde čuva istoriju; povratak u normalno praćenje/public/rankable ide postojećim zasebnim postupkom.

Pregled ili odlaganje kandidata ne treba automatski da skriva javnog borca. Ako se kasnije uvede privremeni ranking hold za nerešene jake signale, to mora biti posebno pravilo, a ne zloupotreba `in_ufc`.

## 5. Redosled implementacije i kriterijumi prihvatanja

| Faza | Konkretan posao | Završeno kada |
| --- | --- | --- |
| 0 — Osnova | Potvrditi aktivni data direktorijum i ciljnu bazu; uporediti uvezene event/profile run-ove; evidentirati verziju koda i šeme; uskladiti novi scrape sa import health gate-om. | Izveštaj jasno pokazuje koje podatke stvarno poredi; nema mešanja starog local skupa i svežeg scrape-a. |
| 1 — Dokazi i parser | Uvesti log-only/unknown u verzionisani ugovor; fallback na log URL; čuvati događaje i precizne datume; poboljšati validaciju stranica, svežine i povratka na stariji snimak. | Dodavanje bez profila ostaje vidljivo i nije `former`; blokada/nepotpun izvor čuvaju poslednji dobar snimak uz vidljiv neuspeh. |
| 2 — Matcher i ponavljanje | Izdvojiti matcher; dodati dry-run i ponovno poređenje sačuvanih opažanja; prikačiti ga na relevantne kanonske promene; osvežavati konflikte; uvesti proverene veze i deduplikaciju ključeva. | Roster uvezen pre novog borca nalazi tog borca nakon njegovog uvoza, bez ponovnog preuzimanja; isti ulazi ne proizvode duplikate ni promene odluka. |
| 3 — Ručna provera | Uporedni dokazi, istorija promena, četiri smislene odluke, napomene/audit, provera verzije dokaza pri kliku; pagination, prioriteti i tačni brojači. | Administrator može pouzdano da odluči i za zero-bout signing, istoimene borce, konflikt DOB-a i log bez individualnog linka. |
| 4 — Isključenje i oporavak | Ojačati guard/transakcije i ponovno izvršavanje post-commit poslova; proveriti sve javne izlaze, ranking activation i tracking export. | Potvrđen borac odmah nestaje sa lista i ostaje protivnik u istoriji; kasniji scrape/uvoz ga ne vrati; korekcija ne vraća automatski rangiranje. |
| 5 — Verifikacija i isporuka | Python DOM fixtures + PHP matcher/service testovi + izolovani ZIP/import/review test; proveriti migracije, release paket i staging tok. | Prihvaćeni scenariji prolaze, provereni izveštaji se čuvaju i operator može samostalno da ponovi ceo tok. |

Minimalna prva isporuka treba da obuhvati faze 0–3 i regresionu proveru već postojećeg isključenja. Sličnost/transliteracija imena može biti sledeća iteracija, pošto tačno poređenje, log i ponovno poređenje prorade pouzdano. Ne uvoditi dodatne sportske izvore pre nego što ovaj osnovni tok bude merljiv i proverljiv.

### Testovi koje treba dodati ili dopuniti

- Identitet: ime+DOB, alias, nedostajući DOB, suprotan DOB/pol, više istih imena, inicijali/transliteracija, soft-delete i merge.
- Dokaz: current/former, dodat bez debija, samo log sa/bez URL-a, više dodavanja/uklanjanja, datum promene različit od datuma ugovora, signal koji ne dokazuje UFC ugovor.
- Ponavljanje: identičan uvoz; roster pre borca; naknadna promena DOB-a/aliasa; odbijen par; odložena stavka sa novim dokazom; name-only ključ koji dobije profil; već potvrđen borac.
- Transport: Python dokument prihvaćen u PHP-u; oba formata ugovora tokom prelaza; neispravan hash, stari snimak, neuspešan najnoviji pokušaj, delimična stranica i očuvanje prethodnog dobrog fajla.
- Review: stvarni ukupni brojevi preko 500 stavki, filtriranje i istorija, konflikt pri potvrdi sa zastarelog ekrana, nonce/capability i pisanje dok radi import/obračun.
- Posledice: potvrda/reject/defer sami po sebi imaju tačno definisane posledice; Top 15 i pagination; REST/profili/kartice/search/tuđe istorije; invalidacija keša; zabrana aktiviranja starog drafta; korekcija i poseban povratak; ponovljiva obrada greške posle commit-a.

## 6. Šta je provereno u ovom pregledu

- Pročitani Python skrejper/CLI/exporter, PHP šema/migracija/UFC servis, Regular Import, Review i fighter admin, eligibility/ranking/public query i template tokovi, tracking i postojeći testovi.
- Neposredno pročitano lokalno stanje baze, uz izričito read-only transakciju.
- Provereni sadržaji aktivnih i starih lokalnih JSON fajlova i ispravnost sačuvanog UFC checksum-a.
- Lokalno reprodukovano pogrešno `former` označavanje log-only dodavanja.
- Prošlo **151 postojećih testova**: `test_ufc_roster.py`, `test_import_bundle.py`, `test_cli.py`, `test_scoring_evidence.py`, `test_fighter_profiles.py`, `test_tapology_event_filtering.py`.
- Potvrđeno da je Chromium koji očekuje postojeći Playwright instaliran. Sam browser nije pokrenut.
- Postojeći PHP integration test za UFC potpise je pregledan i uključen je u izmenjeni CI workflow; nije izvršen u ovom pregledu jer izolovana test baza nije aktivna. Test zahteva izolovanu bazu i javnog rangiranog fixture borca. Ne pokretati ga na radnoj `local` bazi.
- Novi UFC scrape i kompletan novi ZIP→WP→ručna potvrda tok nisu izvršeni; njih ostavljamo za sledeći operatorov test i razvojne faze.

## 7. Komande za prvi ručni test postojećeg skrejpera

Ovo su postojeće komande. Ne zavise od realizacije predloženih razvojnih faza. Prva preuzima izvore, druga pravi ZIP. `&&` sprečava pakovanje prethodnog dobrog snimka ako novo preuzimanje prijavi grešku.

```bash
cd "/Users/lukamutic/Local Sites/mma-future/scraper"
export MMAF_SCRAPER_DATA_DIR="/Users/lukamutic/Local Sites/mma-future/app/public/dev-tools/scraper/data"

.venv/bin/python -m mma_future_scraper.cli check-ufc-roster --headed && \
.venv/bin/python -m mma_future_scraper.cli export-ufc-roster-bundle \
  --output "../output/ufc-review-20261025/ufc-roster-$(date -u +%Y%m%dT%H%M%SZ).zip"
```

Prva komanda treba da ispiše `ok: true`, novi `run_id`, putanju i brojeve `current`, `former`, `log_events`, `fighters`. Druga treba da ispiše `ready_for_import: true`, putanju ZIP-a, veličinu i checksum. Brojevi nisu unapred fiksirani. Ako prva padne, greška je u `app/public/dev-tools/scraper/data/runs/ufc-*/ufc_roster_failure.json`; ne koristiti stari `latest` kao dokaz uspeha novog pokušaja.

Opciona provera novog izlaza, bez mrežnog zahteva:

```bash
.venv/bin/python - <<'PY'
import hashlib, json, os
from pathlib import Path
path = Path(os.environ["MMAF_SCRAPER_DATA_DIR"]) / "latest" / "ufc_roster.json"
document = json.loads(path.read_text(encoding="utf-8"))
body = dict(document)
expected = body.pop("content_sha256")
actual = hashlib.sha256(json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()
assert actual == expected, "UFC checksum mismatch"
print(json.dumps({"path": str(path), "run_id": document["run_id"], "scraped_at": document["scraped_at"], "counts": document["counts"], "checksum_ok": True}, ensure_ascii=False, indent=2))
PY
```

Da bi stvarno nastali kandidati, ZIP se potom uvozi na odgovarajući sajt kroz **Regular Import**: otpremanje → pregled plana → odobrenje uvoza → završen posao → **Review → UFC Signings**. Pre toga taj sajt mora imati odgovarajuće sveže kanonske borce/profile. Alternativa je isti sveži roster uključiti u sledeći redovni paket, koji ga obrađuje posle results/profiles faze.

Sam terminalski test ne popunjava WordPress red za proveru i ne menja ranking. U sadašnjoj verziji na review ekranu još postoje gore opisani nedostaci, naročito log-only status i izostavljen prikaz log događaja. Prvi test koristimo za potvrdu preuzimanja, strukture i pakovanja; administrativne odluke zahtevaju proveru konkretnog dokaza.

## 8. Ključne lokacije koda

- [Python parser, izvori, log i objavljivanje snimka](</Users/lukamutic/Local Sites/mma-future/app/public/dev-tools/scraper/src/mma_future_scraper/ufc_roster.py:83>)
- [CLI komande](</Users/lukamutic/Local Sites/mma-future/app/public/dev-tools/scraper/src/mma_future_scraper/cli.py:364>) i [direktorijumi](</Users/lukamutic/Local Sites/mma-future/app/public/dev-tools/scraper/src/mma_future_scraper/config.py:8>)
- [Zaseban ZIP exporter](</Users/lukamutic/Local Sites/mma-future/app/public/dev-tools/scraper/src/mma_future_scraper/import_bundle.py:167>)
- [UFC šema](</Users/lukamutic/Local Sites/mma-future/app/public/wp-content/plugins/mma-future-data-engine/includes/Migrations/Schema.php:46>)
- [Uvoz, matcher i kandidat-parovi](</Users/lukamutic/Local Sites/mma-future/app/public/wp-content/plugins/mma-future-data-engine/includes/Services/UfcSigningService.php:41>)
- [Odluke, potvrda i korekcija](</Users/lukamutic/Local Sites/mma-future/app/public/wp-content/plugins/mma-future-data-engine/includes/Services/UfcSigningService.php:91>)
- [Regular Import paket](</Users/lukamutic/Local Sites/mma-future/app/public/wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport/Package.php:55>) i [UFC audit faza](</Users/lukamutic/Local Sites/mma-future/app/public/wp-content/plugins/mma-future-data-engine/includes/Services/RegularImport/Engine.php:118>)
- [UFC review ekran](</Users/lukamutic/Local Sites/mma-future/app/public/wp-content/plugins/mma-future-data-engine/includes/Admin/ReviewPage.php:422>)
- [Javni ranking filteri](</Users/lukamutic/Local Sites/mma-future/app/public/wp-content/plugins/mma-future-data-engine/includes/Repositories/PublicReadRepository.php:14>) i [javna vidljivost profila](</Users/lukamutic/Local Sites/mma-future/app/public/wp-content/plugins/mma-future-data-engine/includes/Services/PublicReadService.php:22>)
- [Postojeći PHP integration test](</Users/lukamutic/Local Sites/mma-future/app/public/tests/integration/verify-ufc-signings.php:1>) i [Python UFC testovi](</Users/lukamutic/Local Sites/mma-future/app/public/dev-tools/scraper/tests/test_ufc_roster.py:1>)
