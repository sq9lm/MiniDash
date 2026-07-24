# MiniDash — naprawa alertu prędkości i jednostek transferu

Data: 2026-07-24

## Problem

Alert „Wzrost transferu" nie wystrzeliwał nigdy, niezależnie od ustawionego progu.
Złożyły się na to trzy niezależne usterki.

### 1. Bajty porównywane z bitami

`detect_known_devices()` czytał `rx_bytes-r` z traditional API, czyli **bajty na
sekundę**, a próg liczony był jako `Mbps × 1 000 000`, czyli bity na sekundę.
Alert wymagał ośmiokrotności tego, co pokazywał suwak: przy ustawieniu 100 Mbps
potrzeba było realnych 800 Mbps.

### 2. Prędkość linku brana za transfer

Łańcuch fallbacków kończył się na `rx_rate`, a to w traditional API
**wynegocjowana prędkość linku Wi-Fi w Kbps**, nie ruch. Odczyt na żywo pokazał
klienta „MiSiU NestHub" z `rx_rate = 72 000` (link 72 Mbps) przy faktycznym
transferze 366 B/s, oraz drukarkę raportującą 39 Mbps przy zerowym ruchu.

Ten sam błędny łańcuch był powielony w czterech miejscach: `functions.php`,
`index.php` (dwa razy) i `monitored.php`.

### 3. Dwie rozjeżdżające się kopie triggera

Poza wersją w `cron_triggers.php` istniała druga w `index.php`, która patrzyła
wyłącznie na download, obejmowała wszystkich klientów zamiast monitorowanych
i odpalała się tylko przy otwartym dashboardzie, bo cooldown trzymała
w `$_SESSION` — niedostępnej dla crona.

## Rozwiązanie

**Jedna funkcja `client_rate_bps($client, $kierunek)`** w `functions.php`
normalizuje wszystkie warianty do bitów na sekundę: `rxRateBps`/`txRateBps`
z Integration API bierze wprost, `rx_bytes-r` i warianty `wired-` mnoży przez 8,
a pól `rx_rate`/`tx_rate` nie używa wcale. Wszystkie cztery miejsca wołają teraz
tę funkcję.

Wzbogacanie z Integration API w `cron_triggers.php` przenosi wartości pod
oryginalnymi nazwami `rxRateBps`/`txRateBps` zamiast wpisywać je do
`rx_rate` — inaczej mieszałyby się z prędkością linku pod tym samym kluczem.

Kopia triggera w `index.php` została usunięta. Zmienne `$top_downloader`,
`$max_rx_rate` i pokrewne zostają, bo zasilają kafelki dashboardu.

## Zakres alertu

Trigger obejmuje **wszystkich aktywnych klientów**, nie tylko listę
monitorowanych — urządzenie zapychające łącze zwykle nie jest tym, które ktoś
wcześniej dodał do obserwowanych. Status i historia urządzeń monitorowanych
działają jak dotąd, w osobnej pętli.

Próg pozostaje jeden, wspólny dla obu kierunków (`max(rx, tx)`), zgodnie
z założeniem „endpoint przekracza 100 Mbps". Treść alertu podaje teraz również
kierunek — pobieranie albo wysyłanie.

## Kompromisy

- `last_speeds.json` zapisuje tylko klientów widzianych w danym cyklu. Plik nie
  puchnie o MAC-i urządzeń, które zniknęły z sieci, ale klient nieobecny przez
  jeden cykl i wracający nadal powyżej progu wywoła alert ponownie.
- Zbocze narastające zostawiono bez histerezy. Urządzenie oscylujące wokół progu
  może zaalarmować kilka razy; jeśli okaże się to uciążliwe, naturalnym krokiem
  jest próg powrotu niższy od progu wyzwolenia.
- Alert obejmuje teraz 30 klientów zamiast 6, więc ruch w topiku Ostrzeżenia
  wzrośnie.

## Weryfikacja

Sucha próba na żywych danych z kontrolera, bez wysyłki i bez zapisu stanu:
30 klientów objętych, żaden powyżej progu, najwyższy odczyt to DN-VoIP
0,570 Mbps w górę — wartości zgodne z rzeczywistością. Przed poprawką ten sam
klient raportował 6 065 (bajty czytane jako bity), a drukarka 39 000 000.
