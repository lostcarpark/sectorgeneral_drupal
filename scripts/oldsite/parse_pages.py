"""Convert the old site's articles and other pages to clean HTML.

Usage: python3 -I parse_pages.py <old site dir> <output.json>

Layout tables, fonts, inline styles and the old Amazon widgets are dropped;
links to old pages are kept as old paths for the Drupal import to rewrite.
"""

import html
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from parse_books import clean  # noqa: E402

PAGES = {
    'index.html': {'slug': 'home', 'title': 'Information on James White', 'front': True},
    'articles.html': {'slug': 'articles', 'title': 'Articles', 'listing': True},
    'articleslocusinterview.html': {'slug': 'articles/full-time-hobbyist', 'title': 'Full Time Hobbyist', 'byline': 'James White interview, Locus, March 1993'},
    'articlestimeline.html': {'slug': 'articles/sector-general-timeline', 'title': 'Sector General Timeline', 'byline': 'By Gary Louie'},
    'articlesclassification.html': {'slug': 'articles/classification-system', 'title': 'The Classification System', 'byline': 'By Gary Louie'},
    'articleslangford.html': {'slug': 'articles/james-white-and-sector-general', 'title': 'James White and Sector General', 'byline': 'By Dave Langford'},
    'articlesresnick.html': {'slug': 'articles/introduction-white-papers', 'title': 'Introduction from The White Papers', 'byline': 'By Mike Resnick'},
    'interviewsjeeves.html': {'slug': 'articles/terry-jeeves-interview', 'title': 'Terry Jeeves interviews James White', 'byline': 'From ERG Quarterly 62, April 1978 (edited and published by Terry Jeeves)'},
    'biobiblio.html': {'slug': 'bio-bibliography', 'title': 'Dr. Kilcasey in Space: A Bio-bibliography of James White', 'byline': 'By Graham Andrews'},
}
ALLOWED = {'p', 'br', 'h2', 'h3', 'h4', 'a', 'strong', 'em', 'blockquote', 'ul', 'ol', 'li', 'dl', 'dt', 'dd'}
RENAME = {'b': 'strong', 'i': 'em', 'h1': 'h2'}


def main_region(raw):
    m = re.search(r'(?s)<!-- #BeginEditable "Main" -->(.*?)<!-- #EndEditable -->', raw)
    return m.group(1) if m else raw


def classification(body):
    """Gary Louie's species list: one heading and details per species."""
    out = []
    rows = [[clean(td) for td in re.findall(r'(?is)<td[^>]*>(.*?)</td>', tr)] for tr in re.findall(r'(?is)<tr[^>]*>(.*?)</tr>', body)]
    entry = None
    for cells in rows:
        cells = [c for c in cells if c]
        if not cells:
            continue
        if len(cells) == 2 and cells[0].rstrip(':').lower() == 'classification':
            if entry:
                out.append(entry)
            entry = {'code': cells[1], 'facts': [], 'text': []}
        elif entry and len(cells) == 2 and cells[0].endswith(':'):
            entry['facts'].append((cells[0].rstrip(':'), cells[1]))
        elif entry:
            entry['text'].append(' '.join(cells))
    if entry:
        out.append(entry)
    parts = []
    for e in out:
        esc = html.escape
        parts.append(f'<h3>{esc(e["code"])}</h3>')
        if e['facts']:
            parts.append('<p>' + '<br>'.join(f'<strong>{esc(k)}:</strong> {esc(v)}' for k, v in e['facts']) + '</p>')
        parts += [f'<p>{esc(t)}</p>' for t in e['text']]
    return '\n'.join(parts), len(out)


def sanitise(fragment):
    s = re.sub(r'(?is)<(script|noscript|iframe|style|map)\b.*?</\1>', '', fragment)
    s = re.sub(r'(?is)<!--.*?-->', '', s)
    s = re.sub(r'(?is)<img[^>]*>', '', s)
    # Table cells become paragraphs; the tables themselves were layout.
    s = re.sub(r'(?is)</?(table|tbody|thead|tr|center|div|font|span|dfn|small)[^>]*>', '\n', s)
    s = re.sub(r'(?is)<td[^>]*>', '<p>', s)
    s = re.sub(r'(?is)</td>', '</p>', s)

    def tag(m):
        closing, name, attrs = m.group(1), m.group(2).lower(), m.group(3)
        name = RENAME.get(name, name)
        if name not in ALLOWED:
            return ''
        if name == 'a' and not closing:
            href = re.search(r'(?i)href\s*=\s*"([^"]*)"', attrs)
            if not href or href.group(1).startswith('#'):
                return '<a>'
            return f'<a href="{html.escape(href.group(1).strip())}">'
        return f'<{closing}{name}>'

    s = re.sub(r'(?s)<(/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>', tag, s)
    s = re.sub(r'<a>(.*?)</a>', r'\1', s, flags=re.S)
    s = s.replace('&nbsp;', ' ').replace('\xa0', ' ')
    # Unclosed <p> before a block element, empty paragraphs and headings.
    s = re.sub(r'(?s)<p>\s*(?=<(?:p|h\d|blockquote|ul|ol|dl)>)', '', s)
    s = re.sub(r'(?s)<p>\s*(?:<br>\s*)*</p>', '', s)
    s = re.sub(r'(?s)<(h\d)>\s*</\1>', '', s)
    s = re.sub(r'(?s)<(strong|em)>\s*</\1>', '', s)
    s = re.sub(r'[ \t]+', ' ', s)
    s = re.sub(r'\n\s*\n+', '\n', s)
    # Wrap stray text between blocks in paragraphs.
    blocks = re.split(r'(?s)(<(?:p|h\d|blockquote|ul|ol|dl)>.*?</(?:p|h\d|blockquote|ul|ol|dl)>)', s)
    out = []
    for b in blocks:
        if re.match(r'<(?:p|h\d|blockquote|ul|ol|dl)>', b):
            out.append(b.strip())
        elif clean(b):
            out.append('<p>' + b.strip() + '</p>')
    return '\n'.join(out)


def convert(root, file, meta):
    body = main_region((root / file).read_text(encoding='cp1252', errors='replace'))
    # Drop the "Back to Articles / Main" navigation at the end.
    body = re.split(r'(?i)<a href="(?:\.\./)?(?:articles|index)\.html"[^>]*>\s*(?:Back|Main)', body)[0]
    extra = ''
    if file == 'articlesclassification.html':
        intro, _, rest = body.partition('<table')
        extra, count = classification('<table' + rest)
        body = intro
    html_out = sanitise(body)
    # One block per line, with whitespace inside blocks collapsed.
    html_out = re.sub(r'\s+', ' ', html_out)
    html_out = re.sub(r'(</(?:p|h\d|ul|ol|dl|blockquote)>)\s*', r'\1\n', html_out)
    html_out = re.sub(r'<p>\s*(?:<br>\s*)+', '<p>', html_out)
    # The page's own title and byline become the node title and byline, so
    # drop the headings that repeat them at the top of the page.
    norm = lambda t: re.sub(r'[^a-z]', '', clean(t).lower())
    repeats = {norm(meta['title']), 'articles', 'interviews', norm(meta.get('byline', ''))}
    lines = html_out.split('\n')
    while lines and (m := re.fullmatch(r'\s*<h\d>(.*)</h\d>\s*', lines[0])):
        text = norm(m.group(1))
        if not (text in repeats or text[:12] == norm(meta['title'])[:12] or re.match(r'(?i)\s*(by|from|\()', clean(m.group(1)))
                or (text and norm(meta['title']).endswith(text))):
            break
        lines.pop(0)
    html_out = '\n'.join(lines)
    if meta.get('front'):
        html_out = html_out.split('<h3>Fan Activity')[0]
    # "Click here" paragraphs: move the link onto the nearest heading above.
    blocks = html_out.split('\n')
    kept = []
    for block in blocks:
        click = re.fullmatch(r'\s*<p><a href="([^"]+)">\s*Click[^<]*</a></p>\s*', block)
        if not click:
            kept.append(block)
            continue
        for i in range(len(kept) - 1, -1, -1):
            heading = re.fullmatch(r'<(h[34])>(.*)</\1>', kept[i].strip())
            if heading and heading.group(1) == 'h3':
                if '<a ' not in heading.group(2):
                    kept[i] = f'<h3><a href="{click.group(1)}">{heading.group(2)}</a></h3>'
                break
    html_out = '\n'.join(kept)
    if meta.get('front'):
        html_out = re.sub(r'(?s)<p><strong>New</strong>.*?</p>\s*', '', html_out)
    if extra:
        html_out += '\n' + extra
    if meta.get('byline'):
        html_out = f'<p><em>{html.escape(meta["byline"])}</em></p>\n' + html_out
    return dict(meta, file=file, html=html_out)


if __name__ == '__main__':
    root, out = Path(sys.argv[1]), Path(sys.argv[2])
    pages = [convert(root, f, m) for f, m in PAGES.items()]
    out.write_text(json.dumps(pages, indent=1, ensure_ascii=False))
    for p in pages:
        print(f"{p['file']:30} {len(p['html']):6} chars  {clean(p['html'])[:90]}")
