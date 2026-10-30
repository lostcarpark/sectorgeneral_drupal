# Likely duplicates after the ISFDB import

Each pair is existing content (kept) and imported content (merged into it). A merge keeps the first item's values.

## High confidence (0)

Merge these together with `drush sg-isfdb:merge-confident` (try `--dry-run` first), or one by one.


## Medium confidence (34)

Probably the same, but check each before merging.

- magazine: keep 30 "Science Fantasy" ← merge 1073 "Science Fantasy / Impulse". *names 80% alike, 1 and 0 uses.* `drush sg-isfdb:merge-terms 30 1073`
- magazine: keep 31 "Astounding Science Fiction" ← merge 1072 "Astounding / Analog (1937-1971)". *names 76% alike, 1 and 1 uses.* `drush sg-isfdb:merge-terms 31 1072`
- magazine: keep 32 "Analog Science Fiction and Fact" ← merge 1071 "Analog". *names 63% alike, 6 and 0 uses.* `drush sg-isfdb:merge-terms 32 1071`
- magazine: keep 103 "Science Fiction Adventures" ← merge 1107 "Venture Science Fiction". *names 82% alike, 2 and 1 uses.* `drush sg-isfdb:merge-terms 103 1107`
- magazine_issue: keep 317 "Nebula 35, October 1958" (1958) ← merge 763 "Nebula Science Fiction (US), February 1959" (1959). *same story, same magazine, adjacent year.* `drush sg-isfdb:merge-nodes 317 763`
- magazine_issue: keep 319 "New Worlds 81, March 1959" (1959) ← merge 752 "New Worlds Science Fiction (US), April 1960" (1960). *same story, same magazine, adjacent year.* `drush sg-isfdb:merge-nodes 319 752`
- magazine_issue: keep 321 "New Worlds 84, June 1959" (1959) ← merge 751 "New Worlds Science Fiction (US), March 1960" (1960). *same story, same magazine, adjacent year.* `drush sg-isfdb:merge-nodes 321 751`
- magazine_issue: keep 322 "New Worlds 87, October 1959" (1959) ← merge 753 "New Worlds Science Fiction (US), May 1960" (1960). *same story, same magazine, adjacent year.* `drush sg-isfdb:merge-nodes 322 753`
- magazine_issue: keep 323 "New Worlds 88, November 1959" (1959) ← merge 754 "New Worlds Science Fiction (US), June 1960" (1960). *same story, same magazine, adjacent year.* `drush sg-isfdb:merge-nodes 323 754`
- magazine_issue: keep 335 "The Magazine of Fantasy & Science Fiction, April 1963" (1963) ← merge 775 "The Magazine of Fantasy and Science Fiction (UK), August 1963" (1963). *same story, same magazine, same year.* `drush sg-isfdb:merge-nodes 335 775`
- magazine_issue: keep 336 "Galaxy, January 1974" (1974) ← merge 762 "Galaxy Science Fiction (UK), January 1974" (1974). *same story, same magazine, same year.* `drush sg-isfdb:merge-nodes 336 762`
- people: keep 39 "Brian Aldiss" ← merge 1540 "Brian W. Aldiss". *names 95% alike, 18 and 27 uses.* `drush sg-isfdb:merge-terms 39 1540`
- people: keep 111 "Tom Boardman" ← merge 603 "Tom Boardman, Jr.". *same name once reduced, 1 and 1 uses.* `drush sg-isfdb:merge-terms 111 603`
- people: keep 121 "Ronald M. Hahn" ← merge 1545 "Ronald Hahn". *names 95% alike, 2 and 1 uses.* `drush sg-isfdb:merge-terms 121 1545`
- people: keep 132 "Terry Carr" ← merge 335 "Terry". *names 71% alike, 8 and 5 uses.* `drush sg-isfdb:merge-terms 132 335`
- people: keep 133 "Michael Parry" ← merge 1543 "Michel Parry". *names 95% alike, 1 and 1 uses.* `drush sg-isfdb:merge-terms 133 1543`
- people: keep 140 "John-Henry Holmberg" ← merge 1132 "John-Henri Holmberg". *names 94% alike, 1 and 1 uses.* `drush sg-isfdb:merge-terms 140 1132`
- publisher: keep 1 "Ballantine Books, New York" ← merge 1025 "Ballantine Bal-Hi". *names 80% alike, 32 and 0 uses.* `drush sg-isfdb:merge-terms 1 1025`
- publisher: keep 1 "Ballantine Books, New York" ← merge 1026 "Del Rey / Ballantine / SFBC". *names 66% alike, 32 and 0 uses.* `drush sg-isfdb:merge-terms 1 1026`
- publisher: keep 2 "Corgi, London, UK" ← merge 1023 "Corgi Books / Transworld Publishers". *names 50% alike, 18 and 2 uses.* `drush sg-isfdb:merge-terms 2 1023`
- publisher: keep 16 "Del Rey" ← merge 1026 "Del Rey / Ballantine / SFBC". *names 46% alike, 36 and 0 uses.* `drush sg-isfdb:merge-terms 16 1026`
- publisher: keep 54 "Erich Pabel Verlag" ← merge 1053 "Pabel". *names 66% alike, 3 and 4 uses.* `drush sg-isfdb:merge-terms 54 1053`
- publisher: keep 76 "Ronald Whiting & Wheaton" ← merge 1030 "Whiting & Wheaton". *names 85% alike, 1 and 0 uses.* `drush sg-isfdb:merge-terms 76 1030`
- publisher: keep 80 "Wahlströms" ← merge 1062 "B. Wahlström". *names 90% alike, 2 and 1 uses.* `drush sg-isfdb:merge-terms 80 1062`
- publisher: keep 122 "Ensslin" ← merge 1398 "Ensslin & Laiblin". *names 58% alike, 1 and 0 uses.* `drush sg-isfdb:merge-terms 122 1398`
- publisher: keep 143 "Macmillan" ← merge 1231 "Macmillan UK". *names 90% alike, 1 and 0 uses.* `drush sg-isfdb:merge-terms 143 1231`
- publisher: keep 148 "Futura" ← merge 1021 "Orbit / Futura". *names 70% alike, 2 and 0 uses.* `drush sg-isfdb:merge-terms 148 1021`
- publisher_series: keep 70 "Urania" ← merge 1047 "Urania Collezione". *names 54% alike, 9 and 2 uses.* `drush sg-isfdb:merge-terms 70 1047`
- publisher_series: keep 70 "Urania" ← merge 1066 "Coleção Urania". *names 57% alike, 9 and 1 uses.* `drush sg-isfdb:merge-terms 70 1066`
- publisher_series: keep 74 "Terra" ← merge 1046 "Terra Taschenbuch". *names 47% alike, 3 and 3 uses.* `drush sg-isfdb:merge-terms 74 1046`
- publisher_series: keep 74 "Terra" ← merge 1058 "Terra Sonderband". *names 50% alike, 3 and 0 uses.* `drush sg-isfdb:merge-terms 74 1058`
- publisher_series: keep 88 "Goldmann Science Fiction" ← merge 1050 "Goldmann Science Fiction der Chef-Auswahl". *names 75% alike, 4 and 1 uses.* `drush sg-isfdb:merge-terms 88 1050`
- publisher_series: keep 89 "Utopia" ← merge 235 "Utopia Classics". *names 60% alike, 1 and 4 uses.* `drush sg-isfdb:merge-terms 89 235`
- series: keep 139 "Isaac Asimov Presents The Great SF Stories" ← merge 1175 "The Great SF Stories". *names 56% alike, 1 and 0 uses.* `drush sg-isfdb:merge-terms 139 1175`

## Low confidence (11)

Probably different; merge only if you know they are the same.

- anthology: keep 372 "Pilot durch Zeit und Raum" (1983) ← merge 805 "Terra Science Fiction" (1982). *same story, titles 20% alike, adjacent year.* `drush sg-isfdb:merge-nodes 372 805`
- anthology: keep 381 "Space 1" (1973) ← merge 817 "Weltraum Nr. 1" (1974). *same story, titles 23% alike, adjacent year.* `drush sg-isfdb:merge-nodes 381 817`
- magazine_issue: keep 293 "Astounding Science Fiction, October 1953" (1953) ← merge 750 "Astounding/Analog (UK), March 1954" (1954). *same story, different magazine (perhaps another country's edition), adjacent year.* `drush sg-isfdb:merge-nodes 293 750`
- magazine_issue: keep 305 "New Worlds 43, January 1956" (1956) ← merge 745 "I Romanzi del Cosmo 6, November 1957" (1957). *same story, different magazine (perhaps another country's edition), adjacent year.* `drush sg-isfdb:merge-nodes 305 745`
- magazine_issue: keep 329 "The Magazine of Fantasy & Science Fiction, January 1962" (1962) ← merge 846 "Fantasia e Fantascienza 3, February 1963" (1963). *same story, different magazine (perhaps another country's edition), adjacent year.* `drush sg-isfdb:merge-nodes 329 846`
- magazine_issue: keep 329 "The Magazine of Fantasy & Science Fiction, January 1962" (1962) ← merge 845 "Fantasia e Fantascienza 2, January 1963" (1963). *same story, different magazine (perhaps another country's edition), adjacent year.* `drush sg-isfdb:merge-nodes 329 845`
- magazine_issue: keep 334 "The Magazine of Fantasy & Science Fiction, February 1963" (1963) ← merge 758 "Venture Science Fiction, September 1963" (1963). *same story, different magazine (perhaps another country's edition), same year.* `drush sg-isfdb:merge-nodes 334 758`
- magazine_issue: keep 334 "The Magazine of Fantasy & Science Fiction, February 1963" (1963) ← merge 765 "Fiction 118, September 1963" (1963). *same story, different magazine (perhaps another country's edition), same year.* `drush sg-isfdb:merge-nodes 334 765`
- magazine_issue: keep 335 "The Magazine of Fantasy & Science Fiction, April 1963" (1963) ← merge 755 "Perry Rhodan 112, 25 October 1963" (1963). *same story, different magazine (perhaps another country's edition), same year.* `drush sg-isfdb:merge-nodes 335 755`
- magazine_issue: keep 335 "The Magazine of Fantasy & Science Fiction, April 1963" (1963) ← merge 766 "Fiction 130, September 1964" (1964). *same story, different magazine (perhaps another country's edition), adjacent year.* `drush sg-isfdb:merge-nodes 335 766`
- magazine_issue: keep 340 "Analog Science Fiction and Fact, February 1985" (1985) ← merge 761 "Urania (collana) 1012, 22 December 1985" (1985). *same story, different magazine (perhaps another country's edition), same year.* `drush sg-isfdb:merge-nodes 340 761`

## Printings sharing an ISBN (26)

ISFDB lists each printing separately; these are kept as separate editions. Merge only if one is a mistake.

- edition: keep 8 "Hospital Station, Heyne Verlag, 1993" (1993) ← merge 724 "Hospital Station, Heyne, 1994" (1994). *same ISBN.* `drush sg-isfdb:merge-nodes 8 724`
- edition: keep 10 "Hospital Station, Del Rey, 1979" (1979) ← merge 657 "Hospital Station, Del Rey / Ballantine, 1979" (1979). *same ISBN.* `drush sg-isfdb:merge-nodes 10 657`
- edition: keep 10 "Hospital Station, Del Rey, 1979" (1979) ← merge 686 "Hospital Station, Del Rey / Ballantine, 1979" (1979). *same ISBN.* `drush sg-isfdb:merge-nodes 10 686`
- edition: keep 14 "Beginning Operations, Orb, 2001" (2001) ← merge 669 "Beginning Operations, Orb". *same ISBN.* `drush sg-isfdb:merge-nodes 14 669`
- edition: keep 14 "Beginning Operations, Orb, 2001" (2001) ← merge 721 "Beginning Operations, Orb". *same ISBN.* `drush sg-isfdb:merge-nodes 14 721`
- edition: keep 14 "Beginning Operations, Orb, 2001" (2001) ← merge 668 "Beginning Operations, Orb". *same ISBN.* `drush sg-isfdb:merge-nodes 14 668`
- edition: keep 16 "Ambulance Ship, Del Rey, 1979" (1979) ← merge 664 "Ambulance Ship, Del Rey / Ballantine, 1980" (1980). *same ISBN.* `drush sg-isfdb:merge-nodes 16 664`
- edition: keep 92 "Star Surgeon, Ballantine, 1981" (1981) ← merge 710 "Star Surgeon, Del Rey / Ballantine, 1981" (1981). *same ISBN.* `drush sg-isfdb:merge-nodes 92 710`
- edition: keep 104 "Deadly Litter, Ballantine, 1981" (1981) ← merge 700 "Deadly Litter, Del Rey / Ballantine, 1981" (1981). *same ISBN.* `drush sg-isfdb:merge-nodes 104 700`
- edition: keep 122 "The Watch Below, Ballantine, 1972" (1972) ← merge 714 "The Watch Below, Ballantine Books, 1972" (1972). *same ISBN.* `drush sg-isfdb:merge-nodes 122 714`
- edition: keep 123 "The Watch Below, Del Rey, 1978" (1978) ← merge 708 "The Watch Below, Del Rey / Ballantine, 1978" (1978). *same ISBN.* `drush sg-isfdb:merge-nodes 123 708`
- edition: keep 135 "All Judgement Fled, Ballantine, 1970" (1970) ← merge 659 "All Judgment Fled, Ballantine Books, 1970" (1970). *same ISBN.* `drush sg-isfdb:merge-nodes 135 659`
- edition: keep 136 "All Judgement Fled, Del Rey, 1979" (1979) ← merge 693 "All Judgment Fled, Del Rey / Ballantine, 1979" (1979). *same ISBN.* `drush sg-isfdb:merge-nodes 136 693`
- edition: keep 149 "The Aliens Among Us, Ballantine, 1981" (1981) ← merge 683 "The Aliens Among Us, Del Rey / Ballantine, 1981" (1981). *same ISBN.* `drush sg-isfdb:merge-nodes 149 683`
- edition: keep 153 "Tomorrow Is Too Far, Ballantine, 1971" (1971) ← merge 701 "Tomorrow Is Too Far, Ballantine Books, 1971" (1971). *same ISBN.* `drush sg-isfdb:merge-nodes 153 701`
- edition: keep 158 "Lifeboat, Ballantine, 1980" (1980) ← merge 682 "Lifeboat, Del Rey / Ballantine, 1980" (1980). *same ISBN.* `drush sg-isfdb:merge-nodes 158 682`
- edition: keep 168 "The Dream Millennium, Ballantine, 1974" (1974) ← merge 692 "The Dream Millennium, Ballantine Books, 1974" (1974). *same ISBN.* `drush sg-isfdb:merge-nodes 168 692`
- edition: keep 169 "The Dream Millennium, Ballantine, 1982" (1982) ← merge 684 "The Dream Millennium, Del Rey / Ballantine, 1982" (1982). *same ISBN.* `drush sg-isfdb:merge-nodes 169 684`
- edition: keep 182 "Futures Past, Del Rey, 1982" (1982) ← merge 685 "Futures Past, Del Rey / Ballantine, 1982" (1982). *same ISBN.* `drush sg-isfdb:merge-nodes 182 685`
- edition: keep 184 "Sector General, Del Rey, 1983" (1983) ← merge 666 "Sector General, Del Rey / Ballantine, 1983" (1983). *same ISBN.* `drush sg-isfdb:merge-nodes 184 666`
- edition: keep 184 "Sector General, Del Rey, 1983" (1983) ← merge 648 "Sector General, Del Rey / Ballantine, 1983" (1983). *same ISBN.* `drush sg-isfdb:merge-nodes 184 648`
- edition: keep 185 "Sector General, Del Rey, 1987" (1987) ← merge 652 "Sector General, Del Rey / Ballantine, 1986" (1986). *same ISBN.* `drush sg-isfdb:merge-nodes 185 652`
- edition: keep 189 "Star Healer, Del Rey, 1985" (1985) ← merge 647 "Star Healer, Del Rey / Ballantine, 1985" (1985). *same ISBN.* `drush sg-isfdb:merge-nodes 189 647`
- edition: keep 190 "Star Healer, Futura/Orbit, 1987" (1987) ← merge 667 "Star Healer, Orbit, 1988" (1988). *same ISBN.* `drush sg-isfdb:merge-nodes 190 667`
- edition: keep 197 "Federation World, Del Rey, 1988" (1988) ← merge 649 "Federation World, Del Rey / Ballantine, 1988" (1988). *same ISBN.* `drush sg-isfdb:merge-nodes 197 649`
- edition: keep 200 "The Silent Stars Go By, Ballantine, 1991" (1991) ← merge 694 "The Silent Stars Go By, Del Rey / Ballantine, 1991" (1991). *same ISBN.* `drush sg-isfdb:merge-nodes 200 694`

