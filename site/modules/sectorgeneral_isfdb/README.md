# Sector General ISFDB import

Fills in the James White bibliography from a local copy of the
[ISFDB](https://www.isfdb.org) database, using Drupal's Migrate system.
ISFDB data is licensed CC BY 4.0: every imported or updated record links to
its ISFDB source, and a footer block credits ISFDB site-wide.

The import reads a **downloaded database backup**, the route ISFDB offers for
bulk use. It never fetches pages from isfdb.org. The optional cover-image step
downloads image files from wherever ISFDB says they are hosted.

## What it does

| Migration | Reads | Writes |
|---|---|---|
| `isfdb_stories` | James White's short fiction | Short story |
| `isfdb_books` | His novels, collections, omnibuses, chapbooks, non-fiction | Book |
| `isfdb_editions` | Every publication of those books, translations included, with cover and interior artists, translators (from the notes) and the writers of introductions, forewords and afterwords (essays so titled in the contents) | Edition |
| `isfdb_magazine_issues` | Magazine issues containing his stories or a serial instalment, with their cover artist, the illustrator of his work (interior art titled after it) and the other authors of fiction and poetry in the issue | Magazine issue |
| `isfdb_anthologies` | Anthologies containing his stories, or reprinting a whole book | Anthology |
| `isfdb_edition_covers`, `isfdb_issue_covers` (optional) | Cover image URLs | Cover image media |

Each record is matched to existing content first, by ISFDB ID and then:

- stories and books: the same title or alternative title;
- editions: the same ISBN; else the same book and language with the same
  year, or the same publisher and the same or an adjacent year (a differing
  ISBN is then reported as a conflict);
- magazine issues: the same story in the same year;
- anthologies: the same title, or the same story within a year, preferring
  the same year and then the closer title.

A match is only used when it is unambiguous. Publisher and magazine names are
compared without words such as "Books", "Press", "Ltd" or "Science Fiction",
and without a city after a comma, so "Ballantine Books" finds "Ballantine
Books, New York".

For matched content the import **fills empty fields and never overwrites**:

- empty fields take the ISFDB value;
- lists (contents, artists, editors, alternative titles) gain missing entries;
- the ISFDB attribution is added to "Data sources";
- any other difference is left as it is on the site and listed in the report
  as a conflict for an editor to decide.

Changes are saved as new revisions, so any node can be reverted.

## Running it

1. **Download the ISFDB database backup** from the "ISFDB Downloads" page on
   the ISFDB wiki.

2. **Create a database for it and load it** (from the project folder on your
   computer):

   ```sh
   ddev mysql -uroot -proot -e "CREATE DATABASE isfdb CHARACTER SET latin1; GRANT ALL ON isfdb.* TO 'db'@'%';"
   ddev import-db --database=isfdb --file=path/to/isfdb-backup.sql.gz
   ```

   The connection is defined in `site/settings.local.php` (see
   `site/example.settings.local.php`).

3. **Check the copy** has every table and column the import uses:

   ```sh
   ddev drush sg-isfdb:check
   ```

4. **Choose the author.** ISFDB has several James Whites; pick the one with
   the most titles and the right birth date (1928):

   ```sh
   ddev drush sg-isfdb:find-author "James White"
   ddev drush cset sectorgeneral_isfdb.settings author_id <ID>
   ```

5. **Back up the site**, then import. Always use `--update`: it re-checks
   rows already imported, which is safe here and picks up new data from a
   newer backup.

   ```sh
   ddev export-db --file=backups/before-isfdb.sql.gz
   ddev drush mim --tag=isfdb --update
   ```

6. **Write the review report** and work through it:

   ```sh
   ddev drush sg-isfdb:report --file=scripts/isfdb-report.md
   ```

   It lists what was created and filled in, every conflict, and every new
   term. If a new publisher, magazine or person duplicates an existing term
   under another spelling, add an alias under `publisher_aliases` or
   `magazine_aliases` in `sectorgeneral_isfdb.settings`, merge the terms, and
   re-run step 5. The aliases provided are a starting point.

7. **Cover images** (see below).

## Cover images

```sh
ddev drush sg-isfdb:covers --dry-run
ddev drush sg-isfdb:covers --limit=10
ddev drush sg-isfdb:covers
```

The image addresses come from the local ISFDB copy, so no ISFDB pages or
API are queried; only the image files are downloaded, from wherever ISFDB
says they are hosted, `image_delay` seconds apart (default 2) and with the
site's address and email in the User-Agent.

For each edition, magazine issue and anthology with an ISFDB image:

- with no cover, the image is added;
- with a cover, it is replaced only if the ISFDB image has at least 10% more
  pixels; the previous image stays in the media library;
- the same image is never added twice. An image already in the library is
  reused when its download address matches, or, once downloaded, when its
  SHA-1 fingerprint matches. Existing covers, including the old site's, are
  fingerprinted on the first run. Images found too small to use are
  remembered, so later runs don't download them again.

Books without a cover then take the cover of their earliest English edition.
New images are credited "Image: ISFDB" with a link to the publication record.
Take one down on request by unpublishing its media item.

### Open Library

```sh
ddev drush sg-isfdb:openlibrary --dry-run
ddev drush sg-isfdb:openlibrary --limit=5
ddev drush sg-isfdb:openlibrary
```

For each edition with a valid ISBN not looked up before, fetches Open
Library's edition record and fills in, where empty, the Open Library ID, page
count and OCLC number, adding "Open Library record" to Data sources (Open
Library's catalogue data is CC0). If the record has a cover, it goes through
the same rules as ISFDB covers: added if the edition has none, replacing a
smaller one, never duplicated, credited "Image: Open Library", undone with
`sg-isfdb:covers-undo`. ISBNs Open Library doesn't have are remembered and
skipped next time (`--recheck` asks again).

Requests are spaced `openlibrary_delay` seconds apart (default 10, as Open
Library's robots.txt asks), so a full run takes the best part of an hour.

### Larger copies of the same picture

```sh
ddev drush sg-isfdb:upgrade-covers --dry-run
ddev drush sg-isfdb:upgrade-covers
```

Compares the covers within each book's family (the book and its editions).
Pictures are compared after trimming plain borders and allowing for slightly
different framing, on two measures: the pattern of light and dark
(`--threshold`, default 0.75) and the edges (`--edge-threshold`, default 0.7),
which fading barely affects, so a faded old thumbnail still matches a fresh
scan while a different cover doesn't. Proportions must be similar and the
larger copy must have at least 10% more pixels.

A book's main image may take the largest copy from any of its editions. An
edition is only upgraded when its current cover is an old-site thumbnail; a
cover fetched for that publication shows that edition's own design and is
kept. The smaller image stays in the media library.

`ddev drush sg-isfdb:restore-edition-covers` gives editions back their own
covers if an earlier run (before this rule) replaced them.

`ddev drush sg-isfdb:covers-undo` puts back every cover `sg-isfdb:covers` or
`sg-isfdb:upgrade-covers` changed,
unless an editor has changed it since, and deletes the images it added that
nothing uses.

## National library catalogues

Translated editions come from national library catalogues, in three steps:
fetch, review, apply.

```sh
ddev drush sg-isfdb:library-fetch            # all libraries, or name one: nkp
ddev drush sg-isfdb:library                  # what applying each record would do
ddev drush sg-isfdb:library-decide approve sbn:3f2a1b4c5d6e
ddev drush sg-isfdb:library --apply          # apply the approved records
```

Or review at **Content → Library records** (`/admin/content/library-records`,
permission "Review and apply national library records"): approve or reject
records in bulk, or open one to point it at another edition, or at a book to
add a new edition to, and to see the raw record.

- **Fetch** is the only step that contacts a library. Each record is stored
  as the library sent it (MARC XML, or JSON for the Polish National Library)
  in `sectorgeneral_library_raw`. A record that has changed since is stored as
  a new version and the old one kept; nothing is reformatted.
- **Review** parses the stored records afresh every time, so a change to the
  parsing or matching code takes effect without fetching again. A record
  whose original title matches one of the site's books (or, failing that,
  whose translated title matches an edition in that language) fills in its
  edition, matched by ISBN, by an earlier import, or by book, language and
  year; otherwise it becomes a new edition. Only empty fields are filled.
  Decisions are kept in `sectorgeneral_library_review`, apart from the
  records, so re-parsing never loses them.
- **Apply** applies only approved records. Each edition credits every record
  it drew on in Data sources. Applying clears the approval, so anything new
  that a later fetch brings is reviewed again.

Statuses: *To review*, *Approved, to apply*, *Needs a match* (no book found:
choose one, or reject), *Rejected*, *Up to date* (nothing left to fill).

Libraries:

- `bn`: the Polish National Library's open data API (data.bn.org.pl, CC0).
- MARC 21 catalogues, one entry each in the module settings, under `z3950`
  or `sru` by how they are read; see `Library\MarcSource` and its subclasses
  for the keys. A library that speaks Z39.50 or SRU and MARC 21 needs only a
  new entry. Z39.50 needs YAZ's `zoomsh` (in the DDEV web image).
  - `sbn` (Z39.50): Italy's OPAC SBN (no licence stated).
  - `nkp` (Z39.50): the Czech National Library's catalogue (CC0).
  - `dnb` (SRU): the German National Library's catalogue (CC0). Its records
    name James White without his GND ID, so the search is by name and each
    record's language is checked; Heyne's Orbit-Hospital volumes name him
    only in the record for the whole series, so the volumes of his
    multi-part works are fetched too (`parts_query`).

Check a library's robots.txt and terms before adding it, and credit it: the
credit formatter in the sectorgeneral module must know its name
(`DataSourcesFormatter::RECORD`) for the credit to show as a link.

## Serial entries

The old site recorded each serialisation as one "Magazine serial" edition.
Once the import lists the instalments issue by issue, retire those entries:

```sh
ddev drush sg-isfdb:retire-serial-editions --dry-run
ddev drush sg-isfdb:retire-serial-editions
```

An entry is unpublished (not deleted) when issues of the same magazine
containing the same work exist within two years of it; entries with no such
issues stay. Retired entries keep their history, and their images stay in the
media library. Magazine-serial entries are never matched to ISFDB book
publications.

## Duplicates

Some imported items will still duplicate existing ones under a different
spelling. List the likely pairs; each comes with its reasons and a level:

```sh
ddev drush sg-isfdb:duplicates --file=scripts/isfdb-duplicates.md
```

- **High:** same publisher and year (editions), same magazine and issue
  number, or a closely matching title in the same year, with no other
  candidate on either side. Merge these together:

  ```sh
  ddev drush sg-isfdb:merge-confident --dry-run
  ddev drush sg-isfdb:merge-confident
  ```

- **Medium** and **low:** check each pair, then merge the real duplicates
  one at a time with the command shown beside it:

  ```sh
  ddev drush sg-isfdb:merge-nodes <keep> <duplicate>
  ddev drush sg-isfdb:merge-terms <keep> <duplicate>
  ```

- **Printings sharing an ISBN:** ISFDB records each printing separately, so
  these stay as separate editions unless one is a mistake.

Editions from different publishers are never listed: they are different
editions. People are never high confidence, as similar names can belong to
different people.

A merge keeps the first item and its values, fills its gaps from the
duplicate, moves every reference and the import's own tracking to it,
redirects the duplicate's address, and deletes the duplicate. Merged
publisher and magazine names are saved as aliases for the next import.
Re-run `sg-isfdb:duplicates` after merging to refresh the list.

## Undoing it, or running it again

```sh
ddev export-db --file=backups/before-isfdb-rollback.sql.gz
ddev drush sg-isfdb:covers-undo
ddev drush mr --tag=isfdb
ddev drush sg-isfdb:cleanup-terms
```

Rollback deletes only content the import created, never content the site
already had, including content that duplicates were merged into. Values
filled into existing content are kept; revert those through each node's
Revisions tab, or restore a backup. `sg-isfdb:cleanup-terms` then removes the
terms the import created that nothing uses any more.

To run it again after improving matching or aliases, roll back as above,
then repeat steps 5 and 6.

Restore backups with `ddev import-db`. (`drush sql:query --file` decompresses
a `.gz` backup in place, leaving a `.sql` file instead.)

## If something goes wrong

`drush sg-isfdb:check` names any table or column the ISFDB copy lacks.
`drush migrate:messages <migration>` shows row-level problems. These messages
and counts are enough to diagnose most issues without sharing the ISFDB data
itself.

## Testing

`tests/fixtures/isfdb-sample.sql` holds a few invented records in the shape of
the ISFDB tables (none of it is real ISFDB data). Load it into a scratch
database or under a table prefix, point `settings.isfdb.php` at it, and run
the steps above against a site backup.
