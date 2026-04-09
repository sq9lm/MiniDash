# UniFi MiniDash — Release Notes

## v2.0.0 (2026-04-10)

Duza aktualizacja: migracja na SQLite, Wi-Fi Stalker, ulepszenia Threat Watch, nowe kanaly powiadomien, szyfrowanie danych.

### Baza danych SQLite
- Migracja z plikow JSON na SQLite jako centralny storage
- Automatyczny system migracji (`migrations/`) — nowe wersje schematu aplikowane przy starcie
- Auto-purge starych danych (konfigurowalne progi dni per tabela)
- Skrypt `migrate_json.php` do jednorazowej migracji istniejacych danych

### Wi-Fi Stalker (nowe)
- Sledzenie aktywnych sesji WiFi w czasie rzeczywistym
- Wykrywanie roamingu miedzy Access Pointami
- Historia roamingu z RSSI, kanałem i czasem
- Watchlist urzadzen z powiadomieniami przy roamingu
- Eksport CSV
- Filtry: czas (1h/24h/7d/30d), pasmo (2.4/5/6 GHz), wyszukiwarka
- Widget na dashboardzie z liczba aktywnych sesji
- Polling co 30s z automatycznym wykrywaniem zmian

### Threat Watch ulepszenia
- IP Ignore List — whitelist adresow IP pomijanych w analizie zagrozen
- Filtry zakresu czasu (1h/24h/7d/30d) na zdarzeniach bezpieczenstwa
- Auto-purge starych zdarzen (30 dni)

### Drilldown AP -> klienci
- Klikniecie na urzadzenie w panelu infrastruktury rozwija liste klientow
- Obsluga klientow WiFi (po ap_mac) i wired (po sw_mac z numerem portu)
- Dane z UniFi Traditional API (RSSI, siec, predkosc, IP)

### Nowe kanaly powiadomien
- Discord — webhook z rich embeds
- n8n — generic webhook do automatyzacji
- Panele konfiguracji w ustawieniach powiadomien
- Endpoint testowy `api_test_alert.php`

### Szyfrowanie credentials
- Wrazliwe pola w config.json szyfrowane sodium_crypto_secretbox
- Automatyczne szyfrowanie przy zapisie, deszyfrowanie przy odczycie
- Klucz generowany automatycznie (`data/.encryption_key`)
- Prefix `ENC:` na zaszyfrowanych wartosciach

### Bezpieczenstwo i struktura
- Sekrety przeniesione z hardcoded do `.env`
- Git zainicjalizowany z `.gitignore` blokujacym dane wrazliwe
- Usunieto UTF-8 BOM z wszystkich plikow PHP
- Pliki debug/test przeniesione do `_old/`

### Footer
- Wersja aplikacji + git hash (klikalny changelog)
- Branding LM-Networks z linkami (lm-ads.com, dev.lm-ads.com)
- Copyright 2025-2026

### Favicon
- SVG favicon na wszystkich stronach

---

## v1.5.0 (2026-02-06)

### System Logs (`logs.php`)
- API-driven Event System zamiast parsowania plikow
- Filtry severity (INFO/WARNING/ERROR/CRITICAL)
- Paginacja: 25, 50, 100, 500 na strone
- Modal z podgladem raw JSON zdarzenia

### UniFi Protect Dashboard (`protect.php`)
- Dynamiczny grid kamer: 1, 2, 4, 9, 12 widokow
- Interaktywne sloty do wyboru zrodla kamery
- Status NVR, bandwidth kamer, online/offline

### Ogolne
- Standaryzacja layoutu na max-w-7xl
- Ikona Logs w nawigacji

---

Created by Lukasz Misiura | dev.lm-ads.com
