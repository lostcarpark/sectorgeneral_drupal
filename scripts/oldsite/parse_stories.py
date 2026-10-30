"""Parse the old static site's short story index and pages into JSON.

Usage: python3 -I parse_stories.py <old site dir> <output.json>
"""

import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from parse_books import clean  # noqa: E402


def parse_index(path):
    """shortstories.html: title, first appearance and collections, by date.

    Each table cell lists one or more bold titles (linked when the story has
    its own page), then where it first appeared, then "Collected in" or
    "Written for" a book.
    """
    raw = path.read_text(encoding='cp1252', errors='replace')
    main = re.search(r'(?s)<!-- #BeginEditable "Main" -->(.*?)<!-- #EndEditable -->', raw)
    main = main.group(1) if main else raw
    entries = []
    for cell in re.findall(r'(?is)<td[^>]*>(.*?)</td>', main):
        if 'Click on the title' in cell or not re.search(r'(?i)<b>', cell):
            continue
        parts = re.split(r'(?i)(Collected\s+in|Written\s+for|Republished)', cell, maxsplit=1)
        head, keyword, tail = (parts + ['', ''])[:3]
        # Titles are the bold names at the start of the cell; later bold text
        # ("Originally published as <b>...</b>") is a reference, not a title.
        titles = []
        pos = 0
        for m in re.finditer(r'(?is)(?:<a[^>]*href="shortstories/([^"]+\.html)"[^>]*>\s*)?<b>(.*?)</b>(?:\s*</a>)?', head):
            if titles and re.search(r'\w', clean(head[pos:m.start()])):
                break
            link = m.group(1) or (re.search(r'(?i)href="shortstories/([^"]+\.html)"', m.group(2)) or [None, None])[1]
            title = clean(m.group(2)).rstrip('.')
            if title:
                titles.append((title, link))
            pos = m.end()
        head = head[pos:]
        first = clean(head).strip(' .')
        books = re.findall(r'(?i)href="books/([^"]+\.html)"', tail)
        keyword = re.sub(r'\s+', ' ', keyword.lower())
        for title, file in titles:
            entries.append({
                'file': file,
                'title': title,
                'first_appearance': first if keyword != 'written for' else '',
                'written_for': books[0] if keyword == 'written for' and books else None,
                'collected_text': clean(tail),
                'books': books,
            })
    return entries


def parse_story(path):
    raw = path.read_text(encoding='cp1252', errors='replace')
    main = re.search(r'(?s)<!-- #BeginEditable "Main" -->(.*?)<!-- #EndEditable -->', raw)
    main = main.group(1) if main else raw
    h1 = re.search(r'(?is)<h1[^>]*>(.*?)</h1>', main)
    images = [
        {'src': src, 'alt': clean(alt or '')}
        for src, alt in re.findall(r'(?is)<img[^>]+src="(\.\./images/[^"]+)"(?:[^>]*alt="([^"]*)")?', main)
    ]
    # Drop the navigation footer, then work with text segments.
    body = re.split(r'(?i)<a href="\.\./shortstories\.html"', main)[0]
    body = re.sub(r'(?is)<h1.*?</h1>', '', body)
    segments = [s for s in (clean(x) for x in re.split(r'(?i)<p[^>]*>|</p>|<br\s*/?>', body)) if s]

    words = None
    credit = ''
    first_publication = []
    history = []
    text = []
    section = 'text'
    for seg in segments:
        low = seg.lower()
        m = re.search(r'([\d,]{4,})\s*words', seg, re.I)
        if words is None and m:
            words = int(m.group(1).replace(',', ''))
            # The word count line sometimes repeats the title; anything else stays.
            seg = re.sub(r"(?i)^.*?[\d,]{4,}\s*words\.?\)?\.?", '', seg).strip()
            if not seg:
                continue
        if low.startswith('synopsis by'):
            # The credit often runs straight on into "First Publication: ...".
            credit, _, rest = re.sub(r'(?i)^synopsis by\s*', '', seg).partition('First Publication')
            credit = credit.rstrip('. ').strip()
            if not rest:
                continue
            seg = 'First Publication' + rest
            low = seg.lower()
        if low.startswith('first publication'):
            section = 'first'
            seg = re.sub(r'(?i)^first publication:?', '', seg).strip()
        elif low.startswith('publication history'):
            section = 'history'
            seg = re.sub(r'(?i)^publication history:?', '', seg).strip()
        if not seg:
            continue
        {'text': text, 'first': first_publication, 'history': history}[section].append(seg)

    return {
        'file': path.name,
        'title': clean(h1.group(1)) if h1 else '',
        'words': words,
        'text': text,
        'synopsis_credit': credit,
        'first_publication': first_publication,
        'history': history,
        'images': images,
    }


if __name__ == '__main__':
    root, out = Path(sys.argv[1]), Path(sys.argv[2])
    index = parse_index(root / 'shortstories.html')
    pages = {p.name: parse_story(p) for p in sorted((root / 'shortstories').glob('*.html'))}
    for i, entry in enumerate(index):
        entry['order'] = i
        entry['page'] = pages.pop(entry['file'], None) if entry['file'] else None
    stories = index + [{'file': f, 'title': p['title'], 'order': None, 'first_appearance': '', 'written_for': None, 'collected_text': '', 'books': [], 'page': p} for f, p in pages.items()]
    out.write_text(json.dumps(stories, indent=1, ensure_ascii=False))
    print(f'{len(stories)} stories ({len(pages)} with no index entry) written to {out}')
