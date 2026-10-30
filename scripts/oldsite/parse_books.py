"""Parse the old static site's book pages into JSON.

Usage: python3 -I parse_books.py <old site dir> <output.json>
"""

import html
import json
import re
import sys
from pathlib import Path


def clean(fragment):
    """Strip tags and collapse whitespace."""
    text = re.sub(r'(?i)<br\s*/?>', ' ', fragment)
    text = re.sub(r'<[^>]+>', '', text)
    text = html.unescape(text).replace('\xa0', ' ')
    return re.sub(r'\s+', ' ', text).strip()


def paragraphs(fragment):
    """Split an HTML fragment into cleaned paragraph texts."""
    parts = re.split(r'(?i)<p[^>]*>|</p>|<br\s*/?>\s*<br\s*/?>', fragment)
    return [t for t in (clean(p) for p in parts) if t]


def links(fragment):
    return [(href, clean(text)) for href, text in re.findall(r'(?is)<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>', fragment)]


def parse_book(path):
    raw = path.read_text(encoding='cp1252', errors='replace')
    main = re.search(r'(?s)<!-- #BeginEditable "Main" -->(.*?)<!-- #EndEditable -->', raw)
    main = main.group(1) if main else raw
    title = clean(re.search(r'(?is)<h1[^>]*>(.*?)</h1>', main).group(1))

    images = [
        {'src': src, 'alt': clean(alt) if alt else ''}
        for src, alt in re.findall(r'(?is)<img[^>]+src="(\.\./images/(?:books|collections)/[^"]+)"(?:[^>]*alt="([^"]*)")?', main)
    ]

    # The right-hand column holds an <h3> title, then sections headed by <h3>.
    sections = re.split(r'(?is)<h3[^>]*>(.*?)</h3>', main)
    intro = sections[2] if len(sections) > 2 else ''
    named = {}
    for i in range(3, len(sections) - 1, 2):
        heading = clean(sections[i]).rstrip(':').lower()
        if heading:
            named[heading] = sections[i + 1]

    intro_paras = paragraphs(intro)
    summary_line = intro_paras.pop(0) if intro_paras else ''
    credit = ''
    synopsis = []
    for p in intro_paras:
        m = re.match(r'(?i)synopsis by (.+?)\.?$', p)
        if m:
            credit = m.group(1)
        else:
            synopsis.append(p)
    words = re.search(r'(?i)words:?\s*([\d,]{4,})|([\d,]{4,})\s*words', summary_line)

    def section(*names):
        # Some pages have both "First publication" and "First book
        # publication"; gather every matching section, in page order.
        return ''.join(body for heading, body in named.items() if heading.startswith(names))

    history = section('publication history')
    collected = [
        {'href': href, 'title': text}
        for href, text in links(history)
        if href.endswith('.html') and not href.startswith(('http', '..'))
    ]
    buy = [{'url': href, 'title': text} for href, text in links(section('buy this book')) if href.startswith('http')]

    return {
        'file': path.name,
        'title': title,
        'summary_line': summary_line,
        'words': int((words.group(1) or words.group(2)).replace(',', '')) if words else None,
        'synopsis': synopsis,
        'synopsis_credit': credit,
        'images': images,
        'first_publication': paragraphs(section('first publication', 'first book publication', 'frist book publication', 'first published')),
        'first_book_publication': paragraphs(section('first book publication', 'frist book publication')),
        'history': [p for p in paragraphs(history) if not p.lower().startswith('collected in')],
        'collected_in': collected,
        'foreign': paragraphs(section('foreign publication')),
        'contents_links': [href for href, _ in links(intro) if 'shortstories/' in href],
        'buy': buy,
        'other_sections': [h for h in named if not h.startswith(('first publication', 'first book publication', 'frist book publication', 'first published', 'publication history', 'foreign publication', 'buy this book'))],
    }


def parse_index(path):
    """books.html: one-line description per book, in publication order."""
    raw = path.read_text(encoding='cp1252', errors='replace')
    entries = []
    blocks = re.split(r'(?i)(?=<a href="books/[^"]+\.html">)', raw)
    for block in blocks[1:]:
        href = re.match(r'(?i)<a href="books/([^"]+\.html)">', block).group(1)
        if any(e['file'] == href for e in entries):
            continue
        text = clean(block.split('Click here')[0])
        entries.append({'file': href, 'text': text})
    return entries


if __name__ == '__main__':
    root, out = Path(sys.argv[1]), Path(sys.argv[2])
    books = [parse_book(p) for p in sorted((root / 'books').glob('*.html'))]
    index = parse_index(root / 'books.html')
    order = {e['file']: i for i, e in enumerate(index)}
    for b in books:
        b['index_order'] = order.get(b['file'])
        b['index_text'] = next((e['text'] for e in index if e['file'] == b['file']), '')
    out.write_text(json.dumps(books, indent=1, ensure_ascii=False))
    print(f'{len(books)} books written to {out}')
