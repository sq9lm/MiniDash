# MiniDash — Release Notes

## v2.1.0 (2026-04-10)

Rozbudowa dashboardu, security, ustawien systemowych i optymalizacje.

### Dashboard
- Dynamiczne sesje WAN z API (zamiast hardcoded)
- VLAN detection z UniFi API networkconf (zamiast hardcoded mapy IP)
- VLAN detail modal — klik na VLAN pokazuje klientow z transferem
- AP drilldown — klienci wired i wireless z sw_mac/ap_mac
- Navbar upload kolor amber (spojny z Egress)
- formatBps obsluguje Tbps/Gbps
- WAN units fix (rx_bytes-r * 8 dla bps)
- Stalker widget pokazuje aktywne sesje WiFi

### Security
- IPS config dropdown — klikalne modale (rules, threat intel, geo-blocking)
- Geo-blocking modal z flagami krajow + liczba blokad (z IPS events)
- Security score fix — blocked threats to plus, nie minus
- VPN detection z networkconf (+10 pkt score)
- Firewall rules detection fix (meta.rc → !empty data)
- MongoDB ObjectId w get_trad_site_id
- Blocked IPs country code z srcipCountry
- Security events paginacja (MiniPagination)
- Cache TTL zwiekszony (5min settings, 2min events)

### Inteligentne Wyzwalacze
- Nowe urzadzenie — alert przy nieznanym MAC (z learning phase)
- IPS Alert — powiadomienie o zablokowanym ataku
- Wysoki ping — latency > konfigurowalny prog

### Ustawienia Systemu
- Retencja danych — suwaki per tabela (7-730 dni)
- Sesja i bezpieczenstwo — timeout, max prob logowania, lock duration
- Odswiezanie dashboardu — konfigurowalny interwal pollingu
- Baza danych — rozmiar, rekordy, VACUUM, eksport config/DB

### O Systemie
- CPU, RAM, Disk, Uptime w modalu
- Kanal aktualizacji
- Dynamiczne wykrywanie zainstalowanych aplikacji (Network, Protect, Talk, Access)
- VPN lista z networkconf (OpenVPN + WireGuard)

### Powiadomienia
- Alerty widoczne w panelu dzwonka (sendAlert → SQLite events)
- Zarządca Procesow — prawdziwe dane z API
- WAN health — latency, packet_loss z gateway device stats

### Inne
- Globalna paginacja (MiniPagination) — reusable komponent
- Usunięto fake progress bars z Ingress/Egress
- Usunięto hardcoded Zarządca Procesow
- Monitored.php — modal szczegółow naprawiony
- Protect.php — poprawki

---

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
