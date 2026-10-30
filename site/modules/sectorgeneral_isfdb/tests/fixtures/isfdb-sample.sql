-- Invented sample data in the shape of the ISFDB tables the import reads.
-- None of it comes from ISFDB; it exists to exercise the migrations:
-- matching by ISBN, by book/year/language and by story, conflicts,
-- new records, new terms, HTML entities and notes.

CREATE TABLE authors (author_id INT PRIMARY KEY, author_canonical VARCHAR(128), author_birthdate DATE) DEFAULT CHARSET=latin1;
CREATE TABLE titles (title_id INT PRIMARY KEY, title_title VARCHAR(255), title_copyright DATE, title_ttype VARCHAR(16), title_parent INT DEFAULT 0, title_language INT, series_id INT, title_seriesnum INT, note_id INT) DEFAULT CHARSET=latin1;
CREATE TABLE canonical_author (ca_id INT PRIMARY KEY, title_id INT, author_id INT, ca_status INT) DEFAULT CHARSET=latin1;
CREATE TABLE pubs (pub_id INT PRIMARY KEY, pub_title VARCHAR(255), pub_year DATE, pub_ptype VARCHAR(32), pub_ctype VARCHAR(16), pub_isbn VARCHAR(32), pub_price VARCHAR(32), pub_pages VARCHAR(32), pub_catalog VARCHAR(64), pub_series_id INT, pub_series_num VARCHAR(32), publisher_id INT, pub_frontimage VARCHAR(255), note_id INT) DEFAULT CHARSET=latin1;
CREATE TABLE pub_content (pubc_id INT PRIMARY KEY, pub_id INT, title_id INT, pubc_page VARCHAR(16)) DEFAULT CHARSET=latin1;
CREATE TABLE publishers (publisher_id INT PRIMARY KEY, publisher_name VARCHAR(255)) DEFAULT CHARSET=latin1;
CREATE TABLE pub_series (pub_series_id INT PRIMARY KEY, pub_series_name VARCHAR(255)) DEFAULT CHARSET=latin1;
CREATE TABLE series (series_id INT PRIMARY KEY, series_title VARCHAR(255)) DEFAULT CHARSET=latin1;
CREATE TABLE languages (lang_id INT PRIMARY KEY, lang_name VARCHAR(64)) DEFAULT CHARSET=latin1;
CREATE TABLE notes (note_id INT PRIMARY KEY, note_note TEXT) DEFAULT CHARSET=latin1;
CREATE TABLE identifier_types (identifier_type_id INT PRIMARY KEY, identifier_type_name VARCHAR(64)) DEFAULT CHARSET=latin1;
CREATE TABLE identifiers (identifier_id INT PRIMARY KEY, identifier_type_id INT, identifier_value VARCHAR(64), pub_id INT) DEFAULT CHARSET=latin1;

INSERT INTO authors VALUES
  (9001, 'James White', '1928-04-07'),
  (9002, 'Ann Example', NULL),
  (9003, 'Ed Itor', NULL),
  (9004, 'Art Ist', NULL),
  (9006, 'James White', '1990-01-01');

INSERT INTO languages VALUES (17, 'English'), (22, 'German');
INSERT INTO series VALUES (501, 'Sector General'), (502, 'New Worlds Science Fiction'), (503, 'Example Anthology Series');
INSERT INTO publishers VALUES (3001, 'Del Rey / Ballantine'), (3002, 'Ullstein'), (3003, 'Example Ebooks'), (3004, 'Example House'), (3005, 'Example Paperbacks');
INSERT INTO pub_series VALUES (4001, 'Ullstein Science Fiction &amp; Fantasy');
INSERT INTO notes VALUES (701, 'Translated by Tom Translator.<br>Abridged.'), (702, 'Third printing.');
INSERT INTO identifier_types VALUES (1, 'OCLC/WorldCat'), (2, 'Open Library');

INSERT INTO titles VALUES
  (1001, 'Hospital Station', '1962-05-00', 'COLLECTION', 0, 17, 501, 1, NULL),
  (1002, 'Die Weltraum-Mediziner', '1977-00-00', 'COLLECTION', 1001, 22, NULL, NULL, 701),
  (1003, 'Sector General', '1957-11-00', 'SHORTFICTION', 0, 17, 501, NULL, NULL),
  (1004, 'Visitor at Large', '1959-06-00', 'SHORTFICTION', 0, 17, 501, NULL, NULL),
  (1005, 'An Invented Test Story', '1980-00-00', 'SHORTFICTION', 0, 17, NULL, NULL, NULL),
  (1007, 'Visitor at Large (alternate)', '1980-00-00', 'SHORTFICTION', 1004, 17, NULL, NULL, NULL),
  (1010, 'Hospital Station', '1980-08-00', 'COVERART', 0, NULL, NULL, NULL, NULL),
  (1020, 'New Worlds Science Fiction - 1957', '1957-11-00', 'EDITOR', 0, 17, 502, NULL, NULL),
  (1030, 'Example Anthology of Healers', '1980-00-00', 'ANTHOLOGY', 0, 17, 503, 2, NULL),
  (1031, 'Some Other Story', '1980-00-00', 'SHORTFICTION', 0, 17, NULL, NULL, NULL),
  (1040, 'Hospital Station', '2010-00-00', 'COLLECTION', 1001, 17, NULL, NULL, NULL);

INSERT INTO canonical_author VALUES
  (1, 1001, 9001, 1), (2, 1002, 9001, 1), (3, 1003, 9001, 1), (4, 1004, 9001, 1), (5, 1005, 9001, 1),
  (6, 1007, 9001, 1), (7, 1010, 9004, 1), (8, 1020, 9003, 1), (9, 1030, 9003, 1), (10, 1031, 9002, 1),
  (11, 1040, 9001, 1);

INSERT INTO pubs VALUES
  -- Matches the existing Del Rey edition by ISBN; its year differs, so a conflict is logged.
  (2001, 'Hospital Station', '1980-08-00', 'pb', 'COLLECTION', '0345283538', '$1.95', '[8]+190', '28353', NULL, NULL, 3001, NULL, 702),
  -- No ISBN: matches the existing German Ullstein 1977 edition by book, year and language.
  (2002, 'Die Weltraum-Mediziner', '1977-03-00', 'pb', 'COLLECTION', '', 'DM 4,80', '157', '3331', 4001, '31010', 3002, NULL, NULL),
  -- New: an ebook from a new publisher, with a cover served by this site.
  (2003, 'Hospital Station', '2010-00-00', 'ebook', 'COLLECTION', '9780306406157', '', '', '', NULL, NULL, 3003, 'http://sectorgeneral.ddev.site/core/misc/druplicon.png', NULL),
  -- Matches the existing New Worlds 65 issue by story and year.
  (2010, 'New Worlds Science Fiction, #65', '1957-11-00', 'digest', 'MAGAZINE', '', '2/-', '128', '', NULL, NULL, NULL, NULL, NULL),
  -- New anthology, two printings.
  (2020, 'Example Anthology of Healers', '1980-00-00', 'tp', 'ANTHOLOGY', '', '', '256', '', NULL, NULL, 3004, NULL, NULL),
  (2021, 'Example Anthology of Healers', '1982-06-00', 'pb', 'ANTHOLOGY', '', '', '256', '', NULL, NULL, 3005, NULL, NULL);

INSERT INTO pub_content VALUES
  (1, 2001, 1001, ''), (2, 2001, 1003, '7'), (3, 2001, 1004, '60'), (4, 2001, 1010, 'fc'),
  (5, 2002, 1002, ''),
  (6, 2003, 1040, ''),
  (7, 2010, 1020, ''), (8, 2010, 1003, '4'),
  (9, 2020, 1030, ''), (10, 2020, 1007, '11'), (11, 2020, 1031, '40'), (12, 2020, 1005, '80'),
  (13, 2021, 1030, ''), (14, 2021, 1004, '9');

INSERT INTO identifiers VALUES (1, 1, '12345678', 2001);

-- Cases for the improved matching (also invented):
INSERT INTO publishers VALUES (3006, 'Futura Publications'), (3007, 'Ballantine Books'), (3008, 'Macdonald & Co');
INSERT INTO titles VALUES
  (1008, 'Resident Physician', '1961-09-00', 'SHORTFICTION', 0, 17, 501, NULL, NULL),
  (1009, 'Visitors at Large', '1959-06-00', 'SHORTFICTION', 0, 17, NULL, NULL, NULL),
  (1050, 'Galactic Empires, Volume One', '1976-00-00', 'ANTHOLOGY', 0, 17, NULL, NULL, NULL),
  (1060, 'Star Surgeon', '1963-00-00', 'NOVEL', 0, 17, 501, 2, NULL);
INSERT INTO canonical_author VALUES (12, 1008, 9001, 1), (13, 1009, 9001, 1), (14, 1050, 9003, 1), (15, 1060, 9001, 1);
INSERT INTO pubs VALUES
  -- Variant anthology title, same story and year as an existing anthology.
  (2030, 'Galactic Empires, Volume One', '1976-00-00', 'pb', 'ANTHOLOGY', '', '', '', '', NULL, NULL, 3006, NULL, NULL),
  -- Same publisher (under ISFDB's spelling) and year as an existing edition, but a different ISBN.
  (2004, 'Star Surgeon', '1981-01-00', 'pb', 'NOVEL', '0198534531', '$1.95', '', '', NULL, NULL, 3007, NULL, NULL),
  -- A near-duplicate publisher name and year: listed for review, not merged automatically.
  (2005, 'Hospital Station', '1987-00-00', 'hc', 'COLLECTION', '0140449116', '', '', '', NULL, NULL, 3008, NULL, NULL);
INSERT INTO pub_content VALUES
  (15, 2030, 1050, ''), (16, 2030, 1008, '100'),
  (17, 2004, 1060, ''),
  (18, 2005, 1001, ''),
  (19, 2021, 1009, '30');

-- A whole novel reprinted in an anthology (invented):
INSERT INTO publishers VALUES (3009, 'Blackie');
INSERT INTO titles VALUES
  (1061, 'Second Ending', '1961-06-00', 'NOVEL', 0, 17, NULL, NULL, NULL),
  (1070, 'Out of This World 8', '1970-00-00', 'ANTHOLOGY', 0, 17, NULL, NULL, NULL);
INSERT INTO canonical_author VALUES (16, 1061, 9001, 1), (17, 1070, 9003, 1);
INSERT INTO pubs VALUES
  (2040, 'Out of This World 8', '1970-04-00', 'hc', 'ANTHOLOGY', '', '', '', '', NULL, NULL, 3009, NULL, NULL);
INSERT INTO pub_content VALUES (20, 2040, 1070, ''), (21, 2040, 1061, '9');

-- A novel serialised in two magazine issues (invented):
INSERT INTO series VALUES (504, 'Fantastic');
INSERT INTO authors VALUES (9007, 'Ilsa Illustrator', NULL);
INSERT INTO titles VALUES
  (1080, 'Second Ending (Part 1 of 2)', '1961-06-00', 'SERIAL', 1061, 17, NULL, NULL, NULL),
  (1081, 'Second Ending (Part 2 of 2)', '1961-07-00', 'SERIAL', 1061, 17, NULL, NULL, NULL),
  (1082, 'Second Ending', '1961-06-00', 'INTERIORART', 0, NULL, NULL, NULL, NULL),
  (1083, 'A Different Tale', '1961-06-00', 'INTERIORART', 0, NULL, NULL, NULL, NULL),
  (1084, 'A Different Tale', '1961-06-00', 'SHORTFICTION', 0, 17, NULL, NULL, NULL),
  (1085, 'Fantastic - 1961', '1961-06-00', 'EDITOR', 0, 17, 504, NULL, NULL);
INSERT INTO canonical_author VALUES
  (18, 1080, 9001, 1), (19, 1081, 9001, 1), (20, 1082, 9007, 1), (21, 1083, 9004, 1), (22, 1084, 9002, 1), (23, 1085, 9003, 1);
INSERT INTO pubs VALUES
  (2050, 'Fantastic, June 1961', '1961-06-00', 'digest', 'MAGAZINE', '', '35 cents', '', '', NULL, NULL, NULL, NULL, NULL),
  (2051, 'Fantastic, July 1961', '1961-07-00', 'digest', 'MAGAZINE', '', '35 cents', '', '', NULL, NULL, NULL, NULL, NULL);
INSERT INTO pub_content VALUES
  (22, 2050, 1085, ''), (23, 2050, 1080, '6'), (24, 2050, 1082, '7'), (25, 2050, 1084, '90'), (26, 2050, 1083, '91'),
  (27, 2051, 1085, ''), (28, 2051, 1081, '8');

-- A serial part recorded against the book, in an issue the old site recorded
-- against the story (invented): should match New Worlds 54 by month.
INSERT INTO series VALUES (505, 'New Worlds Magazine');
INSERT INTO titles VALUES
  (1090, 'The Secret Visitors', '1957-00-00', 'NOVEL', 0, 17, NULL, NULL, NULL),
  (1091, 'Tourist Planet (Part 3 of 3)', '1956-12-00', 'SERIAL', 1090, 17, NULL, NULL, NULL),
  (1092, 'New Worlds Magazine - 1956', '1956-12-00', 'EDITOR', 0, 17, 505, NULL, NULL);
INSERT INTO canonical_author VALUES (24, 1090, 9001, 1), (25, 1091, 9001, 1), (26, 1092, 9003, 1);
INSERT INTO pubs VALUES (2060, 'New Worlds Magazine, December 1956', '1956-12-00', 'digest', 'MAGAZINE', '', '2/-', '', '', NULL, NULL, NULL, NULL, NULL);
INSERT INTO pub_content VALUES (29, 2060, 1092, ''), (30, 2060, 1091, '4');

-- A serial in an American magazine and its British edition (invented):
-- each month has a $ issue and a £ issue with the same contents.
INSERT INTO series VALUES (506, 'Galaxy Science Fiction');
INSERT INTO titles VALUES
  (1100, 'The Dream Millennium', '1974-00-00', 'NOVEL', 0, 17, NULL, NULL, NULL),
  (1101, 'The Dream Millennium (Part 1 of 2)', '1973-10-00', 'SERIAL', 1100, 17, NULL, NULL, NULL),
  (1102, 'The Dream Millennium (Part 2 of 2)', '1973-11-00', 'SERIAL', 1100, 17, NULL, NULL, NULL),
  (1103, 'Galaxy Science Fiction - 1973', '1973-10-00', 'EDITOR', 0, 17, 506, NULL, NULL);
INSERT INTO canonical_author VALUES (27, 1100, 9001, 1), (28, 1101, 9001, 1), (29, 1102, 9001, 1), (30, 1103, 9003, 1);
INSERT INTO pubs VALUES
  (2070, 'Galaxy Science Fiction, October 1973', '1973-10-00', 'digest', 'MAGAZINE', '', '$0.75', '180', '', NULL, NULL, NULL, NULL, NULL),
  (2071, 'Galaxy Science Fiction, November 1973', '1973-11-00', 'digest', 'MAGAZINE', '', '$0.75', '180', '', NULL, NULL, NULL, NULL, NULL),
  (2072, 'Galaxy Science Fiction, October 1973', '1973-10-00', 'digest', 'MAGAZINE', '', '£0.25', '180', '', NULL, NULL, NULL, NULL, NULL),
  (2073, 'Galaxy Science Fiction, November 1973', '1973-11-00', 'digest', 'MAGAZINE', '', '£0.25', '180', '', NULL, NULL, NULL, NULL, NULL);
INSERT INTO pub_content VALUES
  (31, 2070, 1103, ''), (32, 2070, 1101, '6'), (33, 2071, 1103, ''), (34, 2071, 1102, '6'),
  (35, 2072, 1103, ''), (36, 2072, 1101, '6'), (37, 2073, 1103, ''), (38, 2073, 1102, '6');
