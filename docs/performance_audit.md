# MiniDash Performance Audit
**Data:** 2026-04-11

## Zewnetrzne zasoby CDN (blokujace render)

| Zasob | Ilosc | Pliki | Wplyw |
|-------|-------|-------|-------|
| cdn.tailwindcss.com | 11 | wszystkie strony | KRYTYCZNY - blokuje caly layout |
| unpkg.com/lucide | 11 | wszystkie strony | KRYTYCZNY - ikony |
| fonts.googleapis.com (Inter) | 11 | wszystkie strony | KRYTYCZNY - fonty |
| flagcdn.com | 33 | security.php, functions.php | WYSOKI - flagi krajow |
| cdn.jsdelivr.net/chart.js | 1 | index.php | SREDNI - wykres WAN |
| ip-api.com | 4 | functions.php | NISKI - GeoIP (cachowane) |

## API calls per page

| Strona | API calls | Czas |
|--------|-----------|------|
| index.php | 5-6 | ~2-3s |
| security.php | 3-4 (cachowane) | ~1s |
| monitored.php | 4 | ~1.5s |
| stalker.php | 3 (AJAX) | ~3.3s poll |
| protect.php | 2 | ~1s |
| logs.php | 1 | szybko |
| devices.php | 2 + N db queries | ~0.5s |

## Duze pliki

| Plik | Rozmiar | Linie |
|------|---------|-------|
| functions.php | 218 KB | 3821 |
| security.php | 130 KB | 1947 |
| index.php | 120 KB | 2059 |

## Plan optymalizacji

### Faza 1: Zasoby lokalne (natychmiastowy efekt)
- [ ] Tailwind CSS → build + minify do dashboard.min.css
- [ ] Lucide icons → lokalna kopia lucide.min.js
- [ ] Google Fonts Inter → lokalne pliki woff2
- [ ] Flagi krajow → img/flags/*.png (pobranie ~250 flag)
- [ ] Chart.js → lokalna kopia

### Faza 2: Optymalizacja API
- [ ] Cache agresywny (navbar stats, sysinfo)
- [ ] Reduce duplicate calls (stat/sta wywoływane wielokrotnie)
- [ ] Lazy load dla modalow (nie laduj danych do momentu otwarcia)

### Faza 3: Kod
- [ ] Podzial functions.php na moduly
- [ ] Wydzielenie inline JS do plikow .js
- [ ] Defer/async na skryptach

### Faza 4: Serwer
- [ ] gzip compression w nginx
- [ ] Cache headers (etag, expires)
- [ ] HTTP/2 push dla krytycznych zasobow

## Do zrobienia przed repo public
- [ ] Usunac pliki debug (api_debug_*.php, scratch/)
- [ ] Sprawdzic .gitignore (brak sekretow w historii)
- [ ] README.md z instrukcja instalacji
- [ ] LICENSE (MIT?)
- [ ] Usunac hardcoded dane z security.php
- [ ] Audyt bezpieczenstwa sesji
