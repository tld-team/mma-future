from __future__ import annotations

import re
from collections import Counter
from typing import Iterable


_RAW_COUNTRY_DATA = """
AD|Andorra|AND
AE|United Arab Emirates|ARE
AF|Afghanistan|AFG
AG|Antigua & Barbuda|ATG
AI|Anguilla|AIA
AL|Albania|ALB
AM|Armenia|ARM
AO|Angola|AGO
AR|Argentina|ARG
AS|American Samoa|ASM
AT|Austria|AUT
AU|Australia|AUS
AW|Aruba|ABW
AX|Aland Islands|ALA
AZ|Azerbaijan|AZE
BA|Bosnia & Herzegovina|BIH
BB|Barbados|BRB
BD|Bangladesh|BGD
BE|Belgium|BEL
BF|Burkina Faso|BFA
BG|Bulgaria|BGR
BH|Bahrain|BHR
BI|Burundi|BDI
BJ|Benin|BEN
BL|St. Barthelemy|BLM
BM|Bermuda|BMU
BN|Brunei|BRN
BO|Bolivia|BOL
BQ|Bonaire, Sint Eustatius and Saba|BES
BR|Brazil|BRA
BS|Bahamas|BHS
BT|Bhutan|BTN
BW|Botswana|BWA
BY|Belarus|BLR
BZ|Belize|BLZ
CA|Canada|CAN
CC|Cocos (Keeling) Islands|CCK
CD|Congo (DRC)|COD
CF|Central African Republic|CAF
CG|Congo|COG
CH|Switzerland|CHE
CI|Cote d'Ivoire|CIV
CK|Cook Islands|COK
CL|Chile|CHL
CM|Cameroon|CMR
CN|China|CHN
CO|Colombia|COL
CR|Costa Rica|CRI
CU|Cuba|CUB
CV|Cabo Verde|CPV
CW|Curacao|CUW
CX|Christmas Island|CXR
CY|Cyprus|CYP
CZ|Czechia|CZE
DE|Germany|DEU
DJ|Djibouti|DJI
DK|Denmark|DNK
DM|Dominica|DMA
DO|Dominican Republic|DOM
DZ|Algeria|DZA
EC|Ecuador|ECU
EE|Estonia|EST
EG|Egypt|EGY
ER|Eritrea|ERI
ES|Spain|ESP
ET|Ethiopia|ETH
FI|Finland|FIN
FJ|Fiji|FJI
FK|Falkland Islands|FLK
FM|Micronesia|FSM
FO|Faroe Islands|FRO
FR|France|FRA
GA|Gabon|GAB
GB|United Kingdom|GBR
GD|Grenada|GRD
GE|Georgia|GEO
GF|French Guiana|GUF
GG|Guernsey|GGY
GH|Ghana|GHA
GI|Gibraltar|GIB
GL|Greenland|GRL
GM|Gambia|GMB
GN|Guinea|GIN
GP|Guadeloupe|GLP
GQ|Equatorial Guinea|GNQ
GR|Greece|GRC
GT|Guatemala|GTM
GU|Guam|GUM
GW|Guinea-Bissau|GNB
GY|Guyana|GUY
HK|Hong Kong SAR|HKG
HN|Honduras|HND
HR|Croatia|HRV
HT|Haiti|HTI
HU|Hungary|HUN
ID|Indonesia|IDN
IE|Ireland|IRL
IL|Israel|ISR
IM|Isle of Man|IMN
IN|India|IND
IO|British Indian Ocean Territory|IOT
IQ|Iraq|IRQ
IR|Iran|IRN
IS|Iceland|ISL
IT|Italy|ITA
JE|Jersey|JEY
JM|Jamaica|JAM
JO|Jordan|JOR
JP|Japan|JPN
KE|Kenya|KEN
KG|Kyrgyzstan|KGZ
KH|Cambodia|KHM
KI|Kiribati|KIR
KM|Comoros|COM
KN|St. Kitts & Nevis|KNA
KP|North Korea|PRK
KR|Korea|KOR
KW|Kuwait|KWT
KY|Cayman Islands|CYM
KZ|Kazakhstan|KAZ
LA|Laos|LAO
LB|Lebanon|LBN
LC|St. Lucia|LCA
LI|Liechtenstein|LIE
LK|Sri Lanka|LKA
LR|Liberia|LBR
LS|Lesotho|LSO
LT|Lithuania|LTU
LU|Luxembourg|LUX
LV|Latvia|LVA
LY|Libya|LBY
MA|Morocco|MAR
MC|Monaco|MCO
MD|Moldova|MDA
ME|Montenegro|MNE
MF|St. Martin|MAF
MG|Madagascar|MDG
MH|Marshall Islands|MHL
MK|North Macedonia|MKD
ML|Mali|MLI
MM|Myanmar|MMR
MN|Mongolia|MNG
MO|Macao SAR|MAC
MP|Northern Mariana Islands|MNP
MQ|Martinique|MTQ
MR|Mauritania|MRT
MS|Montserrat|MSR
MT|Malta|MLT
MU|Mauritius|MUS
MV|Maldives|MDV
MW|Malawi|MWI
MX|Mexico|MEX
MY|Malaysia|MYS
MZ|Mozambique|MOZ
NA|Namibia|NAM
NC|New Caledonia|NCL
NE|Niger|NER
NF|Norfolk Island|NFK
NG|Nigeria|NGA
NI|Nicaragua|NIC
NL|Netherlands|NLD
NO|Norway|NOR
NP|Nepal|NPL
NR|Nauru|NRU
NU|Niue|NIU
NZ|New Zealand|NZL
OM|Oman|OMN
PA|Panama|PAN
PE|Peru|PER
PF|French Polynesia|PYF
PG|Papua New Guinea|PNG
PH|Philippines|PHL
PK|Pakistan|PAK
PL|Poland|POL
PM|St. Pierre & Miquelon|SPM
PN|Pitcairn Islands|PCN
PR|Puerto Rico|PRI
PS|Palestinian Authority|PSE
PT|Portugal|PRT
PW|Palau|PLW
PY|Paraguay|PRY
QA|Qatar|QAT
RE|Reunion|REU
RO|Romania|ROU
RS|Serbia|SRB
RU|Russia|RUS
RW|Rwanda|RWA
SA|Saudi Arabia|SAU
SB|Solomon Islands|SLB
SC|Seychelles|SYC
SD|Sudan|SDN
SE|Sweden|SWE
SG|Singapore|SGP
SH|St Helena, Ascension, Tristan da Cunha|SHN
SI|Slovenia|SVN
SJ|Svalbard & Jan Mayen|SJM
SK|Slovakia|SVK
SL|Sierra Leone|SLE
SM|San Marino|SMR
SN|Senegal|SEN
SO|Somalia|SOM
SR|Suriname|SUR
SS|South Sudan|SSD
ST|Sao Tome & Principe|STP
SV|El Salvador|SLV
SX|Sint Maarten|SXM
SY|Syria|SYR
SZ|Eswatini|SWZ
TC|Turks & Caicos Islands|TCA
TD|Chad|TCD
TG|Togo|TGO
TH|Thailand|THA
TJ|Tajikistan|TJK
TK|Tokelau|TKL
TL|Timor-Leste|TLS
TM|Turkmenistan|TKM
TN|Tunisia|TUN
TO|Tonga|TON
TR|Turkiye|TUR
TT|Trinidad & Tobago|TTO
TV|Tuvalu|TUV
TW|Taiwan|TWN
TZ|Tanzania|TZA
UA|Ukraine|UKR
UG|Uganda|UGA
UM|U.S. Outlying Islands|UMI
US|United States|USA
UY|Uruguay|URY
UZ|Uzbekistan|UZB
VA|Vatican City|VAT
VC|St. Vincent & Grenadines|VCT
VE|Venezuela|VEN
VG|British Virgin Islands|VGB
VI|U.S. Virgin Islands|VIR
VN|Vietnam|VNM
VU|Vanuatu|VUT
WF|Wallis & Futuna|WLF
WS|Samoa|WSM
XK|Kosovo|XKS
YE|Yemen|YEM
YT|Mayotte|MYT
ZA|South Africa|ZAF
ZM|Zambia|ZMB
ZW|Zimbabwe|ZWE
"""

_CANONICAL_NAME_OVERRIDES = {
    "AG": "Antigua and Barbuda",
    "BA": "Bosnia and Herzegovina",
    "CD": "Democratic Republic of the Congo",
    "CG": "Republic of the Congo",
    "HK": "Hong Kong",
    "KN": "Saint Kitts and Nevis",
    "KR": "South Korea",
    "LC": "Saint Lucia",
    "MO": "Macau",
    "PM": "Saint Pierre and Miquelon",
    "PS": "Palestine",
    "SH": "Saint Helena, Ascension and Tristan da Cunha",
    "ST": "Sao Tome and Principe",
    "TC": "Turks and Caicos Islands",
    "TR": "Turkey",
    "TT": "Trinidad and Tobago",
    "VC": "Saint Vincent and the Grenadines",
    "WF": "Wallis and Futuna",
}

_MANUAL_ALIASES = {
    "Bolivia": ("Bolivia, Plurinational State of", "Plurinational State of Bolivia"),
    "Bosnia and Herzegovina": ("Bosnia & Herzegovina",),
    "Brunei": ("Brunei Darussalam",),
    "Cabo Verde": ("Cape Verde",),
    "Cote d'Ivoire": ("Ivory Coast", "Cote dIvoire", "Cote d Ivoire"),
    "Czechia": ("Czech Republic",),
    "Democratic Republic of the Congo": (
        "Congo, The Democratic Republic of the",
        "Democratic Republic of Congo",
        "DR Congo",
        "DRC",
        "Congo DRC",
    ),
    "Eswatini": ("Swaziland",),
    "Hong Kong": ("Hong Kong SAR", "Hong Kong S.A.R."),
    "Iran": ("Islamic Republic of Iran",),
    "Laos": ("Lao People's Democratic Republic", "Lao PDR"),
    "Macau": ("Macao", "Macao SAR", "Macau SAR", "Macao S.A.R.", "Macau S.A.R."),
    "Micronesia": ("Federated States of Micronesia",),
    "Moldova": ("Republic of Moldova",),
    "Myanmar": ("Burma",),
    "North Korea": ("Korea, North", "Democratic People's Republic of Korea", "DPRK"),
    "North Macedonia": ("Macedonia", "Republic of Macedonia"),
    "Palestine": ("State of Palestine", "Palestinian Territory", "Palestinian Authority"),
    "Republic of the Congo": ("Congo Republic", "Republic of Congo"),
    "Russia": ("Russian Federation",),
    "Saint Kitts and Nevis": ("St Kitts and Nevis", "St. Kitts & Nevis"),
    "Saint Lucia": ("St Lucia", "St. Lucia"),
    "Saint Pierre and Miquelon": ("St Pierre and Miquelon", "St. Pierre & Miquelon"),
    "Saint Vincent and the Grenadines": ("St Vincent and the Grenadines", "St. Vincent & Grenadines"),
    "Sao Tome and Principe": ("Sao Tome & Principe",),
    "South Korea": ("Korea, South", "Republic of Korea", "ROK"),
    "Syria": ("Syrian Arab Republic",),
    "Taiwan": ("Taiwan, Province of China",),
    "Tanzania": ("United Republic of Tanzania",),
    "Timor-Leste": ("East Timor",),
    "Turkey": ("Turkiye", "Turkiye Cumhuriyeti", "Republic of Turkey"),
    "United Kingdom": ("UK", "U.K.", "Great Britain", "Britain"),
    "United States": ("United States of America", "USA", "U.S.A.", "US", "U.S."),
    "Venezuela": ("Bolivarian Republic of Venezuela", "Venezuela, Bolivarian Republic of"),
}

_EXTRA_RECORDS = (
    {
        "canonical_name": "England",
        "display_code": "ENG",
        "alpha2": None,
        "alpha3": None,
        "record_type": "territory",
        "aliases": (),
    },
    {
        "canonical_name": "Scotland",
        "display_code": "SCO",
        "alpha2": None,
        "alpha3": None,
        "record_type": "territory",
        "aliases": (),
    },
    {
        "canonical_name": "Wales",
        "display_code": "WAL",
        "alpha2": None,
        "alpha3": None,
        "record_type": "territory",
        "aliases": (),
    },
    {
        "canonical_name": "Northern Ireland",
        "display_code": "NIR",
        "alpha2": None,
        "alpha3": None,
        "record_type": "territory",
        "aliases": (),
    },
)


def normalize_country(raw: str | None) -> dict[str, str | None] | None:
    raw_value = _normalize_space(raw)
    if not raw_value:
        return None
    comparable = _country_comparable(raw_value)
    record = _COUNTRY_KEY_TO_RECORD.get(comparable)
    if not record:
        return None
    return {
        "canonical_name": record["canonical_name"],
        "display_code": record["display_code"],
        "alpha2": record["alpha2"],
        "alpha3": record["alpha3"],
        "matched_alias": raw_value,
        "match_type": _COUNTRY_KEY_TO_MATCH_TYPE.get(comparable, "alias"),
    }


def location_country_candidate(raw_location: str | None) -> str | None:
    parts = [part for part in (_normalize_space(part) for part in (raw_location or "").split(",")) if part]
    if not parts:
        return None
    return parts[-1]


def summarize_unmatched_country_values(values: Iterable[str | None]) -> list[dict[str, int | str]]:
    counts: Counter[str] = Counter()
    for value in values:
        raw_value = _normalize_space(value)
        if raw_value and normalize_country(raw_value) is None:
            counts[raw_value] += 1
    return _sorted_counts(counts)


def summarize_unmatched_location_country_candidates(values: Iterable[str | None]) -> list[dict[str, int | str]]:
    counts: Counter[str] = Counter()
    for value in values:
        candidate = location_country_candidate(value)
        if candidate and normalize_country(candidate) is None:
            counts[candidate] += 1
    return _sorted_counts(counts)


def _normalize_space(value: str | None) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def _country_comparable(value: str) -> str:
    value = _normalize_space(value)
    value = re.sub(r"^(?:flag\s+of|country|nationality)\s*:?\s*", "", value, flags=re.I)
    value = re.sub(r"\s+(?:flag|nationality)$", "", value, flags=re.I)
    value = value.replace("&", " and ")
    value = value.replace("’", "'").replace("‘", "'")
    value = value.lower()
    value = re.sub(r"\bsaint\b", "st", value)
    value = re.sub(r"[^a-z0-9]+", " ", value)
    value = _normalize_space(value)
    tokens = value.split()
    if 2 <= len(tokens) <= 3 and all(len(token) == 1 for token in tokens):
        return "".join(tokens)
    return value


def _sorted_counts(counts: Counter[str]) -> list[dict[str, int | str]]:
    return [{"raw": raw, "count": count} for raw, count in sorted(counts.items(), key=lambda item: (-item[1], item[0]))]


def _build_country_indexes() -> tuple[list[dict[str, str | None]], dict[str, dict[str, str | None]], dict[str, str]]:
    records: list[dict[str, str | None]] = []
    key_to_record: dict[str, dict[str, str | None]] = {}
    key_to_match_type: dict[str, str] = {}

    def register(record: dict[str, str | None], alias: str | None, match_type: str) -> None:
        comparable = _country_comparable(alias or "")
        if not comparable:
            return
        key_to_record[comparable] = record
        key_to_match_type[comparable] = match_type

    for line in _RAW_COUNTRY_DATA.strip().splitlines():
        alpha2, raw_name, alpha3 = line.split("|")
        canonical_name = _CANONICAL_NAME_OVERRIDES.get(alpha2, raw_name)
        record = {
            "canonical_name": canonical_name,
            "display_code": alpha3,
            "alpha2": alpha2,
            "alpha3": alpha3,
            "record_type": "country",
        }
        records.append(record)
        register(record, canonical_name, "exact_country")
        if raw_name != canonical_name:
            register(record, raw_name, "alias")
        register(record, alpha2, "alias")
        register(record, alpha3, "alias")

    canonical_index = {record["canonical_name"]: record for record in records}
    for canonical_name, aliases in _MANUAL_ALIASES.items():
        record = canonical_index[canonical_name]
        for alias in aliases:
            register(record, alias, "alias")

    for record in _EXTRA_RECORDS:
        records.append(record)
        register(record, record["canonical_name"], record["record_type"] or "territory")
        for alias in record.get("aliases", ()):
            register(record, str(alias), "alias")

    return records, key_to_record, key_to_match_type


_COUNTRY_RECORDS, _COUNTRY_KEY_TO_RECORD, _COUNTRY_KEY_TO_MATCH_TYPE = _build_country_indexes()
COUNTRY_NORMALIZER_SUPPORTED_ENTRY_COUNT = len(_COUNTRY_RECORDS)
COUNTRY_NORMALIZER_SUPPORTED_ALIAS_COUNT = len(_COUNTRY_KEY_TO_RECORD)
