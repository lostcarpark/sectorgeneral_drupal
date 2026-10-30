"""Turn the parsed old-site data into structured records for Drupal.

Usage:
  python3 -I prepare_import.py <old site dir> <books.json> <stories.json> <out dir>

Writes <out dir>/import.json and <out dir>/import-report.md. Every guess the
script makes is listed in the report so editors can check it.
"""

import json
import re
import subprocess
import sys
from pathlib import Path

# ---------------------------------------------------------------------------
# Curated facts about each book page. Titles fix the old site's typos and
# split combined headings such as "Open Prison/Escape Orbit".

SG = 'Sector General'
BOOKS = {
    'secretvisitors.html': {'title': 'The Secret Visitors', 'type': 'novel', 'year': 1957, 'alt': ['Tourist Planet']},
    'hospitalstation.html': {'title': 'Hospital Station', 'type': 'novel', 'year': 1962, 'series': (SG, 1)},
    'secondending.html': {'title': 'Second Ending', 'type': 'novel', 'year': 1961},
    'starsurgeon.html': {'title': 'Star Surgeon', 'type': 'novel', 'year': 1963, 'series': (SG, 2)},
    'deadlylitter.html': {'title': 'Deadly Litter', 'type': 'collection', 'year': 1964},
    'openprison.html': {'title': 'Open Prison', 'type': 'novel', 'year': 1964, 'alt': ['The Escape Orbit']},
    'watchbelow.html': {'title': 'The Watch Below', 'type': 'novel', 'year': 1966},
    'alljudgementfled.html': {'title': 'All Judgement Fled', 'type': 'novel', 'year': 1968},
    'aliensamongus.html': {'title': 'The Aliens Among Us', 'type': 'collection', 'year': 1969},
    'majoroperation.html': {'title': 'Major Operation', 'type': 'novel', 'year': 1971, 'series': (SG, 3)},
    'tomorrowistoofar.html': {'title': 'Tomorrow Is Too Far', 'type': 'novel', 'year': 1971},
    'lifeboat.html': {'title': 'Lifeboat', 'type': 'novel', 'year': 1972, 'alt': ['Dark Inferno']},
    'dreammillennium.html': {'title': 'The Dream Millennium', 'type': 'novel', 'year': 1973},
    'monstersmedics.html': {'title': 'Monsters and Medics', 'type': 'collection', 'year': 1977},
    'ambulanceship.html': {'title': 'Ambulance Ship', 'type': 'novel', 'year': 1979, 'series': (SG, 4)},
    'underkill.html': {'title': 'Underkill', 'type': 'novel', 'year': 1979},
    'futurespast.html': {'title': 'Futures Past', 'type': 'collection', 'year': 1982},
    'sectorgeneral.html': {'title': 'Sector General', 'type': 'collection', 'year': 1983, 'series': (SG, 5)},
    'interpreters.html': {'title': 'The Interpreters', 'type': 'chapbook', 'year': 1985},
    'starhealer.html': {'title': 'Star Healer', 'type': 'novel', 'year': 1985, 'series': (SG, 6)},
    'codeblue.html': {'title': 'Code Blue—Emergency', 'type': 'novel', 'year': 1987, 'series': (SG, 7)},
    'federationworld.html': {'title': 'Federation World', 'type': 'novel', 'year': 1988},
    'silentstarsgoby.html': {'title': 'The Silent Stars Go By', 'type': 'novel', 'year': 1991},
    'genocidalhealer.html': {'title': 'The Genocidal Healer', 'type': 'novel', 'year': 1992, 'series': (SG, 8)},
    'galacticgourmet.html': {'title': 'The Galactic Gourmet', 'type': 'novel', 'year': 1996, 'series': (SG, 9)},
    'whitepapers.html': {'title': 'The White Papers', 'type': 'collection', 'year': 1996, 'editors': ['Mark Olson', 'Bruce Pelz']},
    'finaldiagnosis.html': {'title': 'Final Diagnosis', 'type': 'novel', 'year': 1997, 'series': (SG, 10)},
    'mindchanger.html': {'title': 'Mind Changer', 'type': 'novel', 'year': 1998, 'series': (SG, 11)},
    'doublecontact.html': {'title': 'Double Contact', 'type': 'novel', 'year': 1999, 'series': (SG, 12)},
    'firstprotector.html': {'title': 'The First Protector', 'type': 'novel', 'year': 2000},
    'beginningoperations.html': {'title': 'Beginning Operations', 'type': 'omnibus', 'year': 2001, 'series': (SG, None),
                                 'contains': ['hospitalstation.html', 'starsurgeon.html', 'majoroperation.html']},
    'alienemergencies.html': {'title': 'Alien Emergencies', 'type': 'omnibus', 'year': 2002, 'series': (SG, None),
                              'contains': ['ambulanceship.html', 'sectorgeneral.html', 'starhealer.html']},
}
# Book pages that already exist in Drupal (matched by title).
EXISTING_BOOKS = {'hospitalstation.html': 1, 'starsurgeon.html': 5, 'ambulanceship.html': 15}

# Books with no edition lines on the old site: a first edition from the
# page's summary line, so covers and dates have somewhere to live.
MINIMAL_FIRST = {
    'majoroperation.html': 'Ballantine, 1971',
    'monstersmedics.html': 'Corgi, 1977',
    'silentstarsgoby.html': 'Ballantine, 1991',
    'tomorrowistoofar.html': 'Ballantine, 1971',
    'underkill.html': 'Corgi, 1979',
    'interpreters.html': 'Birmingham SF Group, 1985',
}

# Special edition lines.
SPECIAL_EDITIONS = {
    'TALES OF SECTOR GENERAL': {'title': 'Tales of Sector General', 'publisher': 'Science Fiction Book Club', 'printing': 'Book club omnibus',
                                'format': 'Hardback', 'books': ['galacticgourmet.html', 'finaldiagnosis.html', 'mindchanger.html']},
}

# Translated titles garbled on the old site (lost accents, ß read as b,
# dropped apostrophes, typos). The old spelling is kept in the edition notes.
TITLE_FIXES = {
    'Statione Ospedale & Settore Generale': 'Stazione ospedale & Settore generale',
    'Hospital no Espaco': 'Hospital no espaço',
    'Die Weltraum-Medizine': 'Die Weltraum-Mediziner',
    'Hospitaal Tussen de Sterran': 'Hospitaal tussen de sterren',
    "L'Hopital des Etoiles": "L'hôpital des étoiles",
    'Feild Hospitol': 'Field Hospital',
    'Rymd Sjukhusset': 'Rymdsjukhuset',
    'Das Raumschiff der Ratsel': 'Das Raumschiff der Rätsel',
    "L'Astronave del Massacro": "L'astronave del massacro",
    'Da s Prometheus Projekt': 'Das Prometheus-Projekt',
    'Bruder im Kosmos': 'Brüder im Kosmos',
    'Das Ambulance SchIff': 'Das Ambulanzschiff',
    'Die Foderaataaion': 'Die Föderation',
    'Das Jahrtaussend der Traume': 'Das Jahrtausend der Träume',
    'Il Sognio del Millennio': 'Il sogno del millennio',
    'Gefangnis im All': 'Gefängnis im All',
    'Prisao Aberta': 'Prisão aberta',
    'Das Swarze Inferno': 'Das schwarze Inferno',
    'Naufragio Transparante': 'Naufragio trasparente',
    'SOS Fran Rymden': 'SOS från rymden',
    'De Buitnste Duisternis': 'De buitenste duisternis',
    'Die Auberidischen': 'Die Außerirdischen',
    'Die Auberisischen': 'Die Außerirdischen',
    'Il Visitatori Occulti': 'I visitatori occulti',
    'Touristplaneten': 'Turistplaneten',
    'Vita cum gli Automi': 'Vita con gli automi',
    'Fangede i Djuopet': 'Fångade i djupet',
    'O Mondo do Abismo': 'O mundo do abismo',
    'Incontro Nell Abismo': "Incontro nell'abisso",
    'Chiruge in Galactique': 'Chirurgien galactique',
    'Chirurg Tussende Sterren': 'Chirurg tussen de sterren',
    'Medico Espacial': 'Médico espacial',
    'Star Chirurg': 'Sternchirurg',
    'Sector Genera': 'Sector General',
    'Notfall Code Blau': 'Notfall: Code Blau',
    'In De Ban Van Het Gevaar': 'In de ban van het gevaar',
    'Stazione Ospedale': 'Stazione ospedale',
}
# Garbled transliterations of Cyrillic titles, which can't be repaired safely.
UNREADABLE_TITLES = ('Kocnnyecknn', 'Mek3be3ahar')

# (pattern, publisher, publisher series, language). First match wins, so
# more specific patterns come first.
PUBLISHERS = [
    (r'tales of sector general', 'Science Fiction Book Club', None, 'English'),
    (r'book club', 'Science Fiction Book Club', None, 'English'),
    (r'del ?rey', 'Del Rey', None, 'English'),
    (r'^(bb|ballantine|balintine|ballintine|bal-hi)\b', 'Ballantine Books, New York', None, 'English'),
    (r'transworld|corgi', 'Corgi, London, UK', None, 'English'),
    (r'^macdonald hardcover', 'Macdonald, UK', None, 'English'),
    (r'macdonald/futura|futura|orbit', 'Futura/Orbit, London', None, 'English'),
    (r'^orb\b', 'Orb (Tor Books)', None, 'English'),
    (r'^tor\b', 'Tor Books', None, 'English'),
    (r'^ace\b', 'Ace Books', None, 'English'),
    (r'four.square', 'Four Square', None, 'English'),
    (r'digit', 'Digit Books', None, 'English'),
    (r'white lion', 'White Lion', None, 'English'),
    (r'michael joseph', 'Michael Joseph', None, 'English'),
    (r'rapp', 'Rapp & Whiting', None, 'English'),
    (r'ronald whiting', 'Ronald Whiting & Wheaton', None, 'English'),
    (r'walker', 'Walker & Co', None, 'English'),
    (r'old earth', 'Old Earth Books', None, 'English'),
    (r'ian henry', 'Ian Henry Publications', None, 'English'),
    (r'^vern house', 'Severn House', None, 'English'),
    (r'ebury', 'Ebury Press', None, 'English'),
    (r'nesfa', 'NESFA Press', None, 'English'),
    (r'blackie|out of this world', 'Blackie', None, 'English'),
    (r'birmingham', 'Birmingham SF Group', None, 'English'),
    (r'^galaxy', 'Galaxy', None, 'English'),
    (r'^if\b|worlds of if', 'Worlds of If', None, 'English'),
    (r'^new worlds', 'New Worlds', None, 'English'),
    (r'^fantastic', 'Fantastic', None, 'English'),
    (r'heyne', 'Heyne Verlag', None, 'German'),
    (r'goldmann', 'Goldmann', 'Goldmann Science Fiction', 'German'),
    (r'ullstein', 'Ullstein', None, 'German'),
    (r'terra extra', 'Moewig', 'Terra Extra', 'German'),
    (r'terra utopische', 'Moewig', 'Terra Utopische Romane', 'German'),
    (r'terra sf', 'Moewig', 'Terra SF', 'German'),
    (r'^terra\b', 'Moewig', 'Terra', 'German'),
    (r'utopia classics', 'Moewig', 'Utopia Classics', 'German'),
    (r'^utopia\b.*moewig', 'Moewig', 'Utopia', 'German'),
    (r'moewig', 'Moewig', None, 'German'),
    (r'utopis\b|pabel', 'Erich Pabel Verlag', None, 'German'),
    (r'utopische zukunfts', 'Erich Pabel Verlag', 'Utopia Zukunftsroman', 'German'),
    (r'urania', 'Mondadori', 'Urania', 'Italian'),
    (r'mondadori', 'Mondadori', None, 'Italian'),
    (r'galassia', 'La Tribuna', 'Galassia', 'Italian'),
    (r'tascabili', 'Bompiani', 'Tascabili Bompiani', 'Italian'),
    (r'editrice nord', 'Editrice Nord', None, 'Italian'),
    (r'futuria', 'Futuria', None, 'Italian'),
    (r'masque|champs', 'Librairie Des Champs-Élysées', 'Le Masque Science-Fiction', 'French'),
    (r'scala|ridderhof', 'Scala', None, 'Dutch'),
    (r'^born\b', 'Born', None, 'Dutch'),
    (r'livros do brasil|livres d[ao] brasil', 'Livros do Brasil', None, 'Portuguese'),
    (r'kosmos', 'Wahlströms', 'Kosmos', 'Swedish'),
    (r'lindfors', 'Lindfors Förlag', 'Science Fiction Serien', 'Swedish'),
    (r'nova rocket|laissez faire', 'Laissez Faire Produktions', 'Nova Rocket', 'Swedish'),
    (r'hapna', 'Häpna!', None, 'Swedish'),
    (r'alfa', 'Wydawnictwa Alfa', None, 'Polish'),
    (r'ast publishers', 'AST', None, 'Russian'),
    (r'russia|moscow', None, None, 'Russian'),
    (r'hayakawa', 'Hayakawa Publishing', None, 'Japanese'),
]
SERIAL_PUBLISHERS = {'Galaxy', 'Worlds of If', 'New Worlds', 'Fantastic', 'Häpna!'}
SHORT_PUBLISHER = {
    'Ballantine Books, New York': 'Ballantine', 'Corgi, London, UK': 'Corgi', 'Futura/Orbit, London': 'Futura/Orbit',
    'Macdonald, UK': 'Macdonald', 'Orb (Tor Books)': 'Orb', 'Tor Books': 'Tor', 'Ace Books': 'Ace',
    'Librairie Des Champs-Élysées': 'Librairie Des Champs-Élysées', 'Science Fiction Book Club': 'SFBC',
}

# Magazines in the short story index, mapped to Magazine vocabulary terms.
MAGAZINES = [
    (r'^new worlds', 'New Worlds'),
    (r'^astounding', 'Astounding Science Fiction'),
    (r'^nebula', 'Nebula'),
    (r'^science fantasy', 'Science Fantasy'),
    (r'^sf adventures', 'Science Fiction Adventures'),
    (r'^fantasy and sf', 'The Magazine of Fantasy & Science Fiction'),
    (r'^galaxy', 'Galaxy'),
    (r'^analog', 'Analog Science Fiction and Fact'),
    (r'^the gate', 'The Gate'),
]
# Anthologies by other editors in which stories first appeared. Editors not
# named on the old site are marked for checking in the report.
ANTHOLOGIES = {
    # Editors, publishers and dates from the story pages' "First Publication" lines.
    'New Writings In SF 7': {'title': 'New Writings in SF 7', 'year': 1966, 'editors': ['John Carnell'], 'publisher': 'Dennis Dobson', 'image': 'new-sf-7.jpg'},
    'New Writings In SF 12': {'title': 'New Writings in SF 12', 'year': 1968, 'editors': ['John Carnell'], 'publisher': 'Corgi, London, UK', 'image': 'new-sf-12.jpg'},
    'New Writings In SF 14': {'title': 'New Writings in SF 14', 'year': 1969, 'editors': ['John Carnell'], 'publisher': 'Dennis Dobson'},
    'New Writings In SF 16': {'title': 'New Writings in SF 16', 'year': 1970, 'editors': ['John Carnell'], 'publisher': 'Dennis Dobson', 'image': 'new-sf-16.jpg'},
    'New Writings In SF 18': {'title': 'New Writings in SF 18', 'year': 1971, 'editors': ['John Carnell'], 'publisher': 'Corgi, London, UK', 'image': 'new-sf-18.jpg'},
    'New Writings In SF 21': {'title': 'New Writings in SF 21', 'year': 1972, 'editors': ['John Carnell'], 'publisher': 'Sidgwick & Jackson', 'image': 'new-sf-21.jpg'},
    'New Writings in SF': {'title': 'New Writings in SF 22', 'year': 1973, 'editors': ['Ken Bulmer'], 'publisher': 'Sidgwick & Jackson'},
    # Editors not named on the old site: from general knowledge, to be verified.
    'Stellar 2': {'title': 'Stellar 2', 'year': 1976, 'editors': ['Judy-Lynn del Rey'], 'unverified': True, 'image': 'stellar2.jpg'},
}
# Reprint anthologies shown on story pages.
REPRINT_ANTHOLOGIES = {
    'galacticempires.jpg': {'title': 'Galactic Empires', 'year': 1976, 'editors': ['Brian Aldiss'], 'unverified': True, 'story': 'residentphysician.html'},
    'bestofbritish2.jpg': {'title': 'The Best of British SF 2', 'year': 1977, 'editors': ['Mike Ashley'], 'story': 'tableau.html'},
}
REPRINT_ISSUES = {
    'extro2.jpg': {'magazine': 'Extro', 'issue': '2', 'date': 'April/May 1982', 'year': 1982, 'story': 'dynastyofone.html'},
}
# Index entries that are novels serialised in magazines; they become
# magazine-serial editions of the book rather than stories.
SERIALISED_NOVELS = {'Second Ending', 'Open Prison', 'All Judgement Fled', 'Dark Inferno', 'The Dream Millennium'}
STORY_TITLE_FIXES = {
    'Visitor At Large': 'Visitor at Large', 'Something Of Value': 'Something of Value',
    'Trouble With Emily': 'Trouble with Emily', 'Answer Came There None': 'Answer Came There None',
}
# Collections the old site mentions only in book summaries.
EXTRA_CONTENTS = {'spacebird.html': ['futurespast.html']}
EXTRA_BOOKS = {'monstersmedics.html': ['secondending.html']}
STORY_ALT_TITLES = {'Medic': ["O'Mara's Orphan"], 'Dynasty of One': ['Long Will Live the King']}

MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december']
MONTH_RE = r'(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?'

# Words in old cover-image file names, and the publisher they stand for.
IMAGE_KEYS = [
    ('michaeljoesph', ['Michael Joseph']), ('mjoesph', ['Michael Joseph']),
    ('bookclub', ['Science Fiction Book Club', 'Book club']),
    ('ballantine', ['Ballantine Books, New York', 'Del Rey']), ('ballintine', ['Ballantine Books, New York', 'Del Rey']),
    ('delrey', ['Del Rey', 'Ballantine Books, New York']), ('delray', ['Del Rey', 'Ballantine Books, New York']),
    ('corgi', ['Corgi, London, UK']), ('orbit', ['Futura/Orbit, London']),
    ('mcdonald', ['Futura/Orbit, London', 'Macdonald, UK']), ('foursq', ['Four Square']),
    ('acedouble', ['Ace Books']), ('ace', ['Ace Books']), ('digit', ['Digit Books']), ('nel', ['New English Library']),
    ('oeb', ['Old Earth Books']), ('nesfa', ['NESFA Press']), ('bmsfg', ['Birmingham SF Group']), ('tor', ['Tor Books', 'Orb (Tor Books)']),
]
# Images whose file name doesn't say which edition they show.
IMAGE_OVERRIDES = {
    'darkinferno.jpg': ('lifeboat.html', 'Michael Joseph', None),
    'lifeboat.jpg': ('lifeboat.html', 'Ballantine Books, New York', 1972),
    'beginningoperations.jpg': ('beginningoperations.html', 'Orb (Tor Books)', None),
    'alienemergencies.jpg': ('alienemergencies.html', 'Orb (Tor Books)', None),
    'firstprotector.jpg': ('firstprotector.html', 'Tor Books', 2000),
    # The larger Ace Double scan shows the same first edition as secretvisitorsace57.
    'secretvisitorsacedouble.jpg': ('secretvisitors.html', 'Ace Books', 1957, 'replace'),
    # "escapeorbit" contains "orbit", but these are Ace editions of The Escape Orbit.
    'escapeorbitace65.jpg': ('openprison.html', 'Ace Books', 1964),
    'escapeorbitace83.jpg': ('openprison.html', 'Ace Books', 1983),
}
# Larger scans found elsewhere in the old site with no matching thumbnail.
EXTRA_LARGE = {
    'stories/france/hopital des etoiles.jpg': ('hospitalstation.html', 'Librairie Des Champs-Élysées', 1979),
    'stories/france/chirurgien galactique.jpg': ('starsurgeon.html', 'Librairie Des Champs-Élysées', 1981),
}

report = []


def note(section, message):
    report.append((section, message))


# ---------------------------------------------------------------------------
# Small parsers.

def isbn_check(digits):
    if len(digits) == 10 and re.fullmatch(r'\d{9}[\dX]', digits):
        total = sum((10 - i) * (10 if c == 'X' else int(c)) for i, c in enumerate(digits))
        return total % 11 == 0
    if len(digits) == 13 and digits.isdigit():
        return sum((1 if i % 2 == 0 else 3) * int(c) for i, c in enumerate(digits)) % 10 == 0
    return False


def isbn13(isbn10):
    core = '978' + isbn10[:9]
    check = (10 - sum((1 if i % 2 == 0 else 3) * int(c) for i, c in enumerate(core)) % 10) % 10
    return core + str(check)


def find_isbn(text):
    """Return (isbn13, matched text) for the first valid ISBN/SBN in text."""
    for m in re.finditer(r'(?i)(?:\bI?SBN:?|\bNo\.?)\s*(?:\([^)]*\)\s*)?([-0-9OoXx ]{9,22})', text):
        raw = m.group(1).strip()
        digits = re.sub(r'[^0-9Xx]', '', raw.replace('O', '0').replace('o', '0')).upper()
        # An exact 10 or 13 digit number first. Otherwise read the first nine
        # digits as an SBN: old Ballantine numbers append a price code
        # (345-28353-8-195), and the first ten digits can pass the check
        # digit by coincidence.
        candidates = [digits] if len(digits) in (10, 13) else ['0' + digits[:9], digits[:10]]
        for c in candidates:
            if len(c) == 10 and isbn_check(c):
                return isbn13(c), m.group(0)
            if len(c) == 13 and isbn_check(c):
                return c, m.group(0)
    return None, None


def find_catalogue(text):
    # "Nos 33, 34" lists several issues; "No 595" is a single stock number.
    # \b stops "No" matching inside Nova, November, Nord or not.
    m = re.search(r'(?i)\bNos\b\.?\s*(\d+(?:\s*(?:&|-)\s*\d+|,\s*\d+(?=\s*[,&]|\s*$|,\s*[A-Z]))*)', text)
    if m:
        return m.group(1).strip(' ,')
    m = re.search(r'(?i)\bNo\b\.?\s*([A-Z]{0,2}[-/0-9A-Z]*\d[-/0-9A-Z]*)', text)
    return m.group(1).strip(' ,') if m else None


def find_price(text):
    m = re.search(r"(?i)(\$\s?\d+(?:\.\d\d)?|\d+\s?cents|£\s?\d+[-.]\d\d|\b\d{1,2}/\d{1,2}d?\b|\b\d{1,2}/-|\d+p\b|lire?\s?\d+|lira\s?\d+|DM\s?\d+(?:\.\d\d)?|\d+\s?phg|cena\s+z[lł]\s?\d+)", text)
    if not m:
        return None
    price = m.group(1)
    # A date such as 3/6/74 is not a price.
    if re.fullmatch(r'\d+/\d+', price) and re.search(re.escape(price) + r'/\d', text):
        return None
    return price


def find_date(text):
    """Return (year, display) for the first date in text."""
    t = re.sub(r'(?i)\b(?:I?SBN:?|Nos?\.?)\s*[-0-9OoXx/ ]{4,22}', ' ', text)
    m = re.search(r'\b(\d{1,2})/(\d{1,2})/(\d{2,4})\b', t)
    if m:
        d, mo, y = int(m.group(1)), int(m.group(2)), int(m.group(3))
        y = y + 1900 if y < 100 else y
        if 1 <= mo <= 12:
            return y, f'{d} {MONTHS[mo - 1].title()} {y}'
    m = re.search(r'(?i)\b((?:' + MONTH_RE + r'[\s,&/-]*(?:and|to|-)?\s*)+)\s*(\d{4})\b', t)
    if m:
        months = re.findall(r'(?i)' + MONTH_RE, m.group(1))
        names = [next(n for n in MONTHS if n.startswith(x.lower().rstrip('.')[:3])).title() for x in months]
        year = int(m.group(len(m.groups())))
        sep = ' to ' if re.search(r'(?i)\bto\b', m.group(1)) else ' & '
        return year, f'{sep.join(names)} {year}'
    m = re.search(r'(?i)\b(spring|summer|autumn|winter|early|mid|late|sometime)[\s-]*(\d{4})\b', t)
    if m:
        year = int(m.group(2))
        word = m.group(1).lower()
        return year, (f'{year}' if word == 'sometime' else f'{word.title()} {year}' if word not in ('mid',) else f'Mid-{year}')
    m = re.search(r'\b(19[4-9]\d|20[0-2]\d)\b', t)
    if m:
        return int(m.group(1)), m.group(1)
    return None, None


def find_printing(text):
    m = re.search(r'(?i)\b(\d+(?:st|nd|rd|th)|first|second|third|fourth)\s+(printing|ed(?:ition)?\b)', text)
    if m:
        return f'{m.group(1).lower()} {"printing" if m.group(2).lower().startswith("print") else "edition"}'.capitalize()
    for pat, label in [(r'book club', 'Book club edition'), (r'reissue', 'Reissue'), (r'reprint', 'Reprint'), (r'sf collectors', "SF Collectors' edition"), (r'shortened', 'Shortened version')]:
        if re.search(r'(?i)' + pat, text):
            return label
    return None


def find_format(text, publisher):
    if publisher in SERIAL_PUBLISHERS or re.search(r'(?i)\bNos? \d+\s*[-,&]\s*\d+|\bNos\b', text) and publisher in ('La Tribuna',):
        return 'Magazine serial'
    for pat, fmt in [(r'hard\s?(cover|back)|\bhc\b', 'Hardback'), (r'trade', 'Trade Paperback'), (r'paperback|\bpb\b|pocketbook', 'Paperback'), (r'ace double', 'Paperback')]:
        if re.search(r'(?i)' + pat, text):
            return fmt
    return None


PRIORITY_PUBLISHERS = (r'tales of sector general', r'book club', r'del ?rey')


def match_publisher(text):
    """The publisher named earliest in the line, ignoring parenthetical notes.

    Book clubs and Del Rey win over "BB"/"Ballantine" in combined names such
    as "BB/Del Rey", matching the terms already used in Drupal.
    """
    head = text.split('(')[0].strip() or text
    for priority in PRIORITY_PUBLISHERS:
        for pattern, publisher, series, language in PUBLISHERS:
            if pattern == priority and re.search(r'(?i)' + pattern, head):
                return publisher, series, language
    best = None
    for pattern, publisher, series, language in PUBLISHERS:
        m = re.search(r'(?i)' + pattern, head)
        if m and (best is None or m.start() < best[0]):
            best = (m.start(), publisher, series, language)
    return best[1:] if best else (None, None, None)


# ---------------------------------------------------------------------------
# Editions.

def edition_lines(book):
    """All publication lines for a book, tagged by section, cleaned up."""
    lines = []
    for section in ('first_publication', 'history', 'foreign'):
        for line in book[section]:
            lines.append([section, line])
    merged = []
    for section, line in lines:
        # Navigation text that leaked into the last section.
        line = re.sub(r'\s*(Back to Books|Next:).*$', '', line).strip()
        if not line:
            continue
        if merged and merged[-1][1].rstrip().endswith(','):
            merged[-1][1] += ' ' + line
            continue
        if any(line == m[1] for m in merged):
            continue
        merged.append([section, line])
    return merged


def parse_edition(book_file, title, section, line):
    flags = []
    if re.match(r'(?i)extract|.*\bcontract\b', line):
        note('Skipped lines', f'{title}: "{line}" (not a publication)')
        return None
    special = next((v for k, v in SPECIAL_EDITIONS.items() if line.upper().startswith(k)), None)
    publisher, series, language = match_publisher(line)
    if special:
        publisher = special['publisher']
    if not language:
        language = 'English' if section != 'foreign' else None
        flags.append('publisher not recognised')
    if section == 'foreign' and language == 'English':
        flags.append('listed under foreign publication but language looks English')

    ed_title = title
    # "... 1975 as Hospital no Espaco"; not "as well as".
    as_match = re.search(r'\bas\s+(?!well\b)(.+?)\s*(?:\(.*)?\.?$', line)
    as_title = as_match.group(1).split('. ')[0].strip(' .') if as_match else None
    if section == 'foreign' and as_match:
        ed_title = as_title
    elif as_match and language == 'English' and re.match(r'^[A-Z]', as_title):
        ed_title = as_title
    if re.search(r'US title The Escape Orbit|\(E\.?O\.?\)', line):
        ed_title = 'The Escape Orbit'
    if book_file == 'lifeboat.html' and publisher in ('Michael Joseph', 'Corgi, London, UK'):
        ed_title = 'Dark Inferno'
    if book_file == 'secretvisitors.html' and publisher == 'New Worlds':
        ed_title = 'Tourist Planet'
    if special:
        ed_title = special['title']
    ed_title = ed_title.replace('\u2019', "'")
    if section == 'foreign' and not as_match:
        flags.append('no translated title given')
    if ed_title in TITLE_FIXES:
        note('Corrected translated titles', f'{title}: "{ed_title}" → "{TITLE_FIXES[ed_title]}"')
        ed_title = TITLE_FIXES[ed_title]
    if any(bad in ed_title for bad in UNREADABLE_TITLES):
        flags.append(f'translated title "{ed_title}" is a garbled transliteration; book title used instead')
        ed_title = title

    isbn, isbn_text = find_isbn(line)
    rest = line.replace(isbn_text, ' ') if isbn_text else line
    catalogue = None if (isbn_text and isbn_text.lower().startswith('no')) else find_catalogue(rest)
    if catalogue:
        rest = rest.replace(catalogue, ' ')
    if re.search(r'(?i)\bI?SBN\b', line) and not isbn:
        flags.append('ISBN on old site fails its check digit; kept in notes only')
    year, date_display = find_date(line)
    if not year:
        flags.append('no date found')
    printing = special['printing'] if special else find_printing(line)
    fmt = special['format'] if special else find_format(line, publisher)
    pages = re.search(r'(\d+)\s*pages', line)
    return {
        'book': book_file,
        'books': special['books'] if special else [book_file],
        'section': section,
        'raw': line,
        'title': ed_title,
        'language': language,
        'publisher': publisher,
        'publisher_series': series,
        'catalogue': catalogue,
        'isbn': isbn,
        'price': find_price(rest),
        'printing': printing,
        'format': fmt,
        'year': year,
        'date_display': date_display,
        'pages': int(pages.group(1)) if pages else None,
        'flags': flags,
    }


def edition_label(ed):
    pub = ed['publisher'] or 'Unknown publisher'
    short = SHORT_PUBLISHER.get(pub, pub.split(',')[0])
    return ', '.join(str(x) for x in [ed['title'], short, ed['year']] if x)


# ---------------------------------------------------------------------------
# Images.

def signature(path):
    raw = subprocess.run(['convert', f'{path}[0]', '-resize', '12x18!', '-colorspace', 'Gray', '-depth', '8', 'gray:-'],
                         capture_output=True, check=True).stdout
    v = list(raw)
    mean = sum(v) / len(v)
    v = [x - mean for x in v]
    norm = sum(x * x for x in v) ** 0.5 or 1
    return [x / norm for x in v]


def size(path):
    out = subprocess.run(['identify', '-format', '%w %h', f'{path}[0]'], capture_output=True, text=True, check=True).stdout
    w, h = out.split()
    return int(w), int(h)


def best_versions(root):
    """Map each small old-site image to the largest near-identical copy."""
    small = sorted(p for d in ('images/books', 'images/collections', 'images/magazines') for p in (root / d).glob('*.jpg'))
    large = sorted(p for p in (root / 'stories').rglob('*.jpg'))
    sigs = {p: signature(p) for p in small + large}
    best = {}
    for p in small:
        best[p.name] = p
        for q in large:
            score = sum(a * b for a, b in zip(sigs[p], sigs[q]))
            if score > 0.93 and size(q)[0] > size(best[p.name])[0]:
                best[p.name] = q
    return best


def image_record(root, path, alt, source='James White Information (original site)'):
    w, h = size(path)
    return {'path': str(path.relative_to(root)), 'alt': alt, 'width': w, 'height': h, 'source': source}


# ---------------------------------------------------------------------------

def main(root, books_json, stories_json, out_dir):
    books_raw = {b['file']: b for b in json.loads(Path(books_json).read_text())}
    stories_raw = json.loads(Path(stories_json).read_text())
    versions = best_versions(root)
    used_large = {str(v.relative_to(root)) for v in versions.values()}

    books, editions = {}, []
    for file, cur in BOOKS.items():
        raw = books_raw[file]
        summary = raw['index_text']
        # Drop the "Title. Publisher: year." prefix from the books.html blurb.
        summary = re.sub(r'^.*?\b(19|20)\d\d\.?\s*', '', summary, count=1).strip()
        credit = raw['synopsis_credit']
        credit = credit[:1].upper() + credit[1:] if credit else ''
        synopsis = [p for p in raw['synopsis'] if not re.match(r'(?i)^(full synopsis|click here)', p)]
        books[file] = {
            'file': file,
            'existing_nid': EXISTING_BOOKS.get(file),
            'title': cur['title'],
            'type': cur['type'],
            'year': cur['year'],
            'series': cur.get('series'),
            'alt_titles': cur.get('alt', []),
            'editors': cur.get('editors', []),
            'contains': cur.get('contains', []) + EXTRA_BOOKS.get(file, []),
            'words': raw['words'],
            'summary': summary,
            'synopsis': synopsis,
            'synopsis_credit': credit,
            'buy': raw['buy'],
            'contents': [],
        }
        if raw['title'] != cur['title']:
            note('Corrected book titles', f'"{raw["title"]}" → "{cur["title"]}"')
        lines = edition_lines(raw)
        if not lines and file in MINIMAL_FIRST:
            lines = [['first_publication', MINIMAL_FIRST[file]]]
            note('Editions created from the page summary', f'{cur["title"]}: {MINIMAL_FIRST[file]} (the old page had no publication history)')
        prev = None
        for section, line in lines:
            ed = parse_edition(file, cur['title'], section, line)
            if not ed:
                continue
            # "Second printing published May 1998" continues the line above.
            if not ed['publisher'] and prev and re.match(r'(?i)^(second|third|\d+(st|nd|rd|th))\s+printing', line):
                ed.update(publisher=prev['publisher'], language=prev['language'],
                          flags=[f for f in ed['flags'] if f != 'publisher not recognised'])
            ed['label'] = edition_label(ed)
            editions.append(ed)
            prev = ed

    # Cover images on book pages → editions.
    media = []
    attached = set()

    def attach(ed, img_path, alt, replace=False):
        if ed.get('image'):
            if not replace or ed['image']['width'] >= size(img_path)[0]:
                return False
            # Keep the smaller scan in the library rather than losing it.
            media.append(ed['image'])
        ed['image'] = image_record(root, img_path, alt)
        attached.add(str(img_path.relative_to(root)))
        return True

    for file, raw in books_raw.items():
        for img in raw['images']:
            name = img['src'].split('/')[-1]
            path = versions.get(name, root / img['src'].replace('../', ''))
            if not path.exists():
                note('Missing images', f'{raw["title"]}: {img["src"]}')
                continue
            replace = False
            if name in IMAGE_OVERRIDES:
                target_book, publisher, year, *flag = IMAGE_OVERRIDES[name]
                publishers = [publisher]
                replace = flag == ['replace']
            else:
                if name.startswith(('beginningoperations', 'alienemergencies')) and file not in ('beginningoperations.html', 'alienemergencies.html'):
                    continue
                target_book = file
                key = next((k for k, _ in IMAGE_KEYS if k in name), None)
                publishers = next((p for k, p in IMAGE_KEYS if k == key), [])
                yy = re.search(r'(\d\d)(?:\D*)\.jpg$', name)
                year = 1900 + int(yy.group(1)) if yy and int(yy.group(1)) > 40 else (2000 + int(yy.group(1)) if yy else None)
            candidates = [e for e in editions if e['book'] == target_book and e['language'] == 'English' and e['format'] != 'Magazine serial']
            if publishers:
                candidates = [e for e in candidates if e['publisher'] in publishers or (e['printing'] or '').startswith('Book club') and 'Book club' in publishers]
            if year:
                # A dated file name only fits that year, or an undated edition.
                candidates = [e for e in candidates if e['year'] == year] or [e for e in candidates if not e['year']]
            if '3rd' in name:
                candidates = [e for e in candidates if '3' in (e['printing'] or '') or 'third' in (e['printing'] or '').lower()]
            alt = img['alt'] or f'{BOOKS.get(target_book, {}).get("title", raw["title"])} cover'
            for ed in sorted(candidates, key=lambda e: (e['year'] or 9999)):
                if attach(ed, path, alt, replace):
                    break
            else:
                if publishers and target_book == file:
                    # The cover proves an edition exists that the old history omits.
                    pub = publishers[0]
                    ed = {
                        'book': file, 'books': [file], 'section': 'image', 'raw': f'Edition known only from the cover image {name}',
                        'title': BOOKS[file]['title'], 'language': 'English', 'publisher': pub, 'publisher_series': None,
                        'catalogue': None, 'isbn': None, 'price': None, 'printing': '3rd printing' if '3rd' in name else None, 'format': None,
                        'year': year, 'date_display': str(year) if year else None, 'pages': None,
                        'flags': ['created from a cover image only; details unknown'],
                    }
                    ed['label'] = edition_label(ed)
                    editions.append(ed)
                    attach(ed, path, alt)
                    note('Editions created from cover images', f'{BOOKS[file]["title"]}: {pub}{", " + str(year) if year else ""} ({name})')
                elif str(path.relative_to(root)) not in attached:
                    media.append(image_record(root, path, alt))
                    attached.add(str(path.relative_to(root)))
                    note('Covers not matched to an edition', f'{raw["title"]}: {name} (added to the media library only)')

    for rel, (book, publisher, year) in EXTRA_LARGE.items():
        ed = next((e for e in editions if e['book'] == book and e['publisher'] == publisher and e['year'] == year), None)
        if ed:
            ed['image'] = image_record(root, root / rel, f'{ed["title"]} cover')
        else:
            note('Covers not matched to an edition', f'{rel}: no {publisher} {year} edition of {book}')

    # Magazine serial editions get the first issue's cover, if we have it.
    for ed in editions:
        if ed['format'] == 'Magazine serial' and ed['publisher'] == 'New Worlds' and not ed.get('image'):
            m = re.search(r'(\d{2,3})', ed['raw'])
            if m and (root / f'images/magazines/newworlds{m.group(1)}.jpg').exists():
                path = versions.get(f'newworlds{m.group(1)}.jpg')
                ed['image'] = image_record(root, path, f'New Worlds {m.group(1)} cover')

    # An ISBN belongs to one book; the same ISBN on two books is an old-site error.
    by_isbn = {}
    for ed in editions:
        if ed['isbn']:
            by_isbn.setdefault(ed['isbn'], set()).add(ed['book'])
    for ed in editions:
        if ed['isbn'] and len(by_isbn[ed['isbn']]) > 1:
            ed['flags'].append(f'ISBN {ed["isbn"]} is also given for another book on the old site')

    # Flag possible duplicates for the books that already have editions.
    for ed in editions:
        if ed['book'] in EXISTING_BOOKS:
            ed['match_existing'] = True
        for flag in ed['flags']:
            note('Editions to check', f'{ed["label"]}: {flag} — "{ed["raw"]}"')

    # -----------------------------------------------------------------------
    # Stories, magazine issues and anthologies.
    issues, anthologies, stories = {}, {}, []
    book_by_image = {}
    for file, raw in books_raw.items():
        for img in raw['images']:
            book_by_image.setdefault(img['src'].split('/')[-1], file)

    for entry in stories_raw:
        title = STORY_TITLE_FIXES.get(entry['title'], entry['title'])
        if title in SERIALISED_NOVELS and not entry['page']:
            continue
        page = entry['page'] or {}
        key = (entry['file'] or title)
        if any(s['key'] == key for s in stories):
            continue
        # Clean the page text: drop title repeats and word-count debris.
        norm = lambda s: re.sub(r'[^a-z]', '', s.lower())
        text = [t for t in page.get('text', []) if norm(t) != norm(title) and not re.match(r'^[,(]?\s*approx', t) and norm(t) not in (norm(title + ' / ' + x) for x in STORY_ALT_TITLES.get(title, []))]
        text = [t for t in text if not re.match(r"(?i)^o.mara.s orphan\s*/\s*medic$", t)]
        opening = text[0] if len(text) > 1 else ''
        synopsis = text[1:] if len(text) > 1 else text
        notes = ' '.join(page.get('first_publication', []) + page.get('history', []))
        notes = re.sub(r'\s*\(see under Books\)\.?', '', notes).lstrip(': ')
        notes = re.sub(r'(Publication History:|Foreign Publication:)', r'\n\1', notes).strip()
        credit = page.get('synopsis_credit', '')
        credit = credit[:1].upper() + credit[1:] if credit else ''

        story = {
            'key': key, 'file': entry['file'], 'title': title, 'order': entry['order'],
            'alt_titles': STORY_ALT_TITLES.get(title, []),
            'words': page.get('words'), 'opening': opening, 'synopsis': synopsis, 'synopsis_credit': credit,
            'publication_notes': notes, 'first_appearance_text': entry['first_appearance'], 'year': None,
        }
        stories.append(story)

        # First appearance.
        first = entry['first_appearance'].split('. ')[0].strip()
        if entry['written_for']:
            story['year'] = BOOKS[entry['written_for']]['year']
        elif first:
            anth_key = next((k for k in sorted(ANTHOLOGIES, key=len, reverse=True) if first.lower().startswith(k.lower())), None)
            mag = next((name for pat, name in MAGAZINES if re.match(r'(?i)' + pat, first)), None)
            year, date_display = find_date(first)
            story['year'] = year
            if anth_key:
                a = anthologies.setdefault(anth_key, dict(ANTHOLOGIES[anth_key], contents=[]))
                a['contents'].append(key)
            elif mag:
                nums = re.findall(r'(?<![\d/])(\d{1,3})(?=[,\s]|$)', re.sub(r'\d{4}', '', first))
                months = re.findall(r'(?i)\b' + MONTH_RE, first)
                if len(nums) > 1 and len(nums) == len(months):
                    parts = [(n, f'{next(x for x in MONTHS if x.startswith(mo.lower()[:3])).title()} {year}') for n, mo in zip(nums, months)]
                else:
                    parts = [(nums[0] if nums else None, date_display)]
                for num, disp in parts:
                    ikey = (mag, num or disp)
                    issue = issues.setdefault(ikey, {
                        'key': f'{mag}|{num or disp}', 'magazine': mag, 'issue': num, 'date': disp, 'year': year,
                        'title': f'{mag} {num}, {disp}' if num else f'{mag}, {disp}', 'contents': [],
                    })
                    issue['contents'].append(key)
            else:
                note('Stories to check', f'{title}: first appearance "{first}" not recognised')

        # Collections: index links, plus "Collected in <title>" without a link.
        collected = list(entry['books'])
        for file, cur in BOOKS.items():
            if re.search(r'(?i)collected in\s+' + re.escape(cur['title']), 'Collected in ' + entry['collected_text']) and file not in collected:
                collected.append(file)
        if entry['written_for'] and entry['written_for'] not in collected:
            collected.append(entry['written_for'])
        collected += [f for f in EXTRA_CONTENTS.get(entry['file'], []) if f not in collected]
        if title == 'Tourist Planet':
            collected = []  # Expanded into The Secret Visitors rather than reprinted.
        inferred = []
        if not collected:
            for img in page.get('images', []):
                f = book_by_image.get(img['src'].split('/')[-1])
                if f and f not in collected and BOOKS[f]['type'] in ('collection', 'novel', 'omnibus'):
                    collected.append(f)
                    inferred.append(BOOKS[f]['title'])
        if inferred:
            note('Collections inferred from cover images', f'{title}: {", ".join(inferred)} (the index names no collection; the story page shows these covers)')
        for f in dict.fromkeys(collected):
            books[f]['contents'].append(key)

        # Covers shown on the story page: magazine issues and reprint anthologies.
        for img in page.get('images', []):
            name = img['src'].split('/')[-1]
            if name in REPRINT_ANTHOLOGIES and REPRINT_ANTHOLOGIES[name]['story'] == entry['file']:
                r = REPRINT_ANTHOLOGIES[name]
                a = anthologies.setdefault(name, {k: v for k, v in r.items() if k != 'story'} | {'image': name, 'contents': []})
                a['contents'].append(key)
            elif name in REPRINT_ISSUES and REPRINT_ISSUES[name]['story'] == entry['file']:
                r = REPRINT_ISSUES[name]
                ikey = (r['magazine'], r['issue'])
                issues.setdefault(ikey, {'key': f'{r["magazine"]}|{r["issue"]}', 'magazine': r['magazine'], 'issue': r['issue'], 'date': r['date'],
                                         'year': r['year'], 'title': f'{r["magazine"]} {r["issue"]}, {r["date"]}', 'contents': [], 'image_name': name})['contents'].append(key)

    # Magazine covers → issues, matching by number or by month and year.
    for path in sorted((root / 'images/magazines').glob('*.jpg')):
        name = path.name
        m = re.match(r'newworlds(\d+)', name) or re.match(r'(?:nebula|nebula-sf)-(\d+)', name) or re.match(r'sceincefantasy(\d+)', name)
        mag = 'New Worlds' if name.startswith('newworlds') else 'Nebula' if name.startswith('nebula') else 'Science Fantasy' if name.startswith('sceince') else None
        issue = None
        if m and mag:
            issue = issues.get((mag, m.group(1)))
        else:
            dm = re.match(r'(analog|fantasy-and-sf|galaxy)-([a-z]+)-?(\d{2,4})', name)
            if dm:
                mag = {'analog': 'Analog Science Fiction and Fact', 'fantasy-and-sf': 'The Magazine of Fantasy & Science Fiction', 'galaxy': 'Galaxy'}[dm.group(1)]
                yy = int(dm.group(3))
                year = yy if yy > 1000 else 1900 + yy
                month = next(x for x in MONTHS if x.startswith(dm.group(2)[:3])).title()
                issue = issues.get((mag, f'{month} {year}'))
        if issue and not issue.get('image'):
            best = versions.get(name, path)
            issue['image'] = image_record(root, best, f'{issue["title"]} cover')
        elif not issue and not any(e.get('image', {}).get('path', '').endswith(name) for e in editions):
            note('Magazine covers with no matching issue', f'{name} (added to the media library only)')
            media.append(image_record(root, versions.get(name, path), f'{name.rsplit(".", 1)[0]} cover'))
    for issue in issues.values():
        if issue.get('image_name') and not issue.get('image'):
            p = root / 'images/collections' / issue['image_name']
            issue['image'] = image_record(root, versions.get(issue['image_name'], p), f'{issue["title"]} cover')
    for a in anthologies.values():
        if a.get('image'):
            name = a['image']
            a['image'] = image_record(root, versions.get(name, root / 'images/collections' / name), f'{a["title"]} cover')
        if a.get('unverified'):
            note('Anthology editors added from general knowledge (please verify)', f'{a["title"]}: {", ".join(a["editors"])}')
    note('Anthologies to check', 'New Writings in SF 16: the index says 1969, the "Meatball" page says Dobson, January 1970; 1970 used.')

    # Series membership for stories collected in series books.
    for s in stories:
        s['series'] = None
    for b in books.values():
        if b['series']:
            for key in b['contents']:
                next(s for s in stories if s['key'] == key)['series'] = b['series'][0]

    for b in books.values():
        if len(b['contents']) > 1:
            note('Contents ordered by first publication (reorder to match the table of contents)', b['title'])

    data = {
        'books': list(books.values()),
        'editions': editions,
        'stories': stories,
        'issues': list(issues.values()),
        'anthologies': list(anthologies.values()),
        'media': media,
    }
    out = Path(out_dir)
    (out / 'import.json').write_text(json.dumps(data, indent=1, ensure_ascii=False))

    lines = ['# Old site import: items to review', '']
    lines.append(f'{len(books)} books, {len(editions)} editions, {len(stories)} short stories, {len(issues)} magazine issues, '
                 f'{len(anthologies)} anthologies, {len(media)} unattached covers.')
    for section in dict.fromkeys(s for s, _ in report):
        lines += ['', f'## {section}', '']
        lines += [f'- {m}' for s, m in report if s == section]
    (out / 'import-report.md').write_text('\n'.join(lines) + '\n')
    print(lines[2])


if __name__ == '__main__':
    main(Path(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4])
