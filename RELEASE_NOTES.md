# MiniDash — Release Notes

## v2.3.0 (2026-04-15)

Docker fixes & Setup Wizard — rozwiązanie problemów z instalacją Docker i nowy kreator konfiguracji.

### Setup Wizard (new)
- Kreator pierwszej konfiguracji — formularz zamiast ręcznej edycji .env
- Automatyczne wykrywanie pierwszego uruchomienia (marker data/.installed)
- Pola: Controller URL, API Key, Site ID, Admin login/hasło, imię, email
- Walidacja wymaganych pól i minimalnej długości hasła
- Zapis konfiguracji do .env z odpowiednimi uprawnieniami
- Redirect z login.php i index.php na setup.php gdy brak konfiguracji
- Istniejące instalacje (z poprawnym API key) automatycznie pomijają wizard

### Docker
- Fix: biała strona po instalacji — baza danych tworzona jako root, PHP-FPM (www-data) nie mógł pisać
- Fix: dodany drugi chown w start.sh po migracji bazy danych
- Fix: dodane brakujące PHP curl extension (wymagane przez requirements)
- Dodany bash do Alpine — terminal w Synology Container Manager teraz działa
- Dodany mc (Midnight Commander) — łatwiejsza nawigacja po kontenerze
- config.php: wartości z .env nadpisują zmienne Docker (setup wizard ma priorytet)

### VPN Triggers
- Poprawiony endpoint API: stat/event zamiast rest/alarm (kompatybilność z UDR)
- Ulepszone dopasowanie kluczy VPN (case-insensitive, connect/disconnect)
- Severity w alertach VPN (info/warning)
- Odblokowany trigger VPN w UI (usunięty opacity-50)

---

## v2.2.0 (2026-04-12)

Threat Watch — przebudowany moduł Security z zakładkami i live IDS/IPS monitoring.

### Threat Watch (IDS/IPS)
- Nowa zakładka "Zagrożenia" w Security — live monitoring IDS/IPS z auto-refresh co 60s
- Kaskadowy API fallback: V2 traffic-flows → stat/ips/event → rest/alarm (kompatybilność z UDR, UDM Pro, UDM SE, UCG, UXG)
- Pre-loaded dane z PHP — natychmiastowe przełączanie między zakładkami
- Path memory (24h cache) — zapamiętuje który endpoint działa, pomija niedziałające
- Paginacja listy zagrożeń (25/50/100) z nawigacją < >
- Filtry: zakres czasu (1h/24h/7d), ryzyko (high/medium/low), akcja (blocked/alert), wyszukiwarka
- Modal szczegółów zdarzenia z GeoIP (kraj, miasto, ISP) dla source i destination
- Sidebar: top kraje źródłowe, rozkład ryzyka, top kategorie zagrożeń
- Eksport CSV z filtrowanych danych
- Ignorowanie IP z listy zagrożeń (integracja z threat_ignore)

### Security Overview
- Zakładka "Przegląd" z Security Score (SVG), statusem IPS/Honeypot/Ad-block
- Wyświetlanie trybu IPS: IDS (detekcja), IPS (detekcja + blokowanie), IPS Inline (pełna ochrona)
- Karty konfiguracji: IPS mode, Ad Blocking, Honeypot, Geo-blocking
- Protection pillars, modal reguł firewalla, modal geo-blocking z flagami krajów
- Przebudowany security score modal z rozbiciem na czynniki

### Backend
- Nowa funkcja `fetch_api_post()` — POST z API key dla V2 i legacy endpointów
- Nowa funkcja `fetch_threat_events()` — kaskadowy fallback z normalizacją danych
- Normalizatory: `normalize_v2_threat()`, `normalize_legacy_threat()`, `normalize_alarm_threat()`
- Nowy endpoint `api_threats.php` — AJAX z filtrami, statystykami, top countries/categories
- Dodany `ips_mode` do `get_unifi_security_settings()`

### UI/CSS
- Nowe glow: `stat-glow-orange`, `stat-glow-red`, `stat-glow-rose` w dashboard.css
- Style paginacji `.page-size-btn` z aktywnym stanem (orange)

### i18n (PL/EN)
- 43 nowe klucze `threats.*` (PL + EN) — eventy, filtry, paginacja, modal, sidebar
- 6 kluczy `threats.mode_*` — tryby IPS/IDS
- 2 klucze `security.tab_overview`, `security.tab_threats`

## v2.1.1 (2026-04-11)

Internationalization, security audit, notification fixes.

### i18n (PL/EN)
- Full English translation of the entire interface
- __() function with parameter support and dot-notation
- Language files: lang/pl.json (~520 keys), lang/en.json
- Language switcher in Personal settings modal (saved to config.json)
- All pages translated: dashboard, security, stalker, monitored, devices, events, history, logs, protect, login, footer, navbar, notification settings

### Notifications
- Device names now visible in Telegram and all other channels (subject + message)
- Severity correctly saved in SQLite events (instead of hardcoded WARNING)
- Bell panel — colors based on severity (critical=red, warning=amber, info=green)
- Alert clicks navigate to events.php (instead of history.php with empty MAC)

### Security Audit
- Auth check added to api_ping.php, api_clear_history.php, api_save_settings.php
- display_errors=0 in production (config.php, api_user_settings.php)
- error_reporting(0) in update_wan.php replaced with proper logging
- Avatar upload: MIME type validation + 5MB size limit + random filename + old file cleanup

### Code Cleanup
- Removed ~370 lines of dead code (old _disabled_render_* functions)
- Removed 20+ debug files from data/ and logs/
- WAN Link card no longer stretches to VLAN height (self-start)

### Docker
- Docker files ready: Dockerfile, docker-compose.yml, nginx.conf, start.sh

---

## v2.1.0 (2026-04-10)

Dashboard expansion, security improvements, system settings and optimizations.

### Dashboard
- Dynamic WAN sessions from API (replaced hardcoded data)
- VLAN detection from UniFi API networkconf (replaced hardcoded IP map)
- VLAN detail modal — click VLAN to see clients with transfer stats
- AP drilldown — wired and wireless clients via sw_mac/ap_mac
- Navbar upload color changed to amber (consistent with Egress)
- formatBps supports Tbps/Gbps
- WAN units fix (rx_bytes-r * 8 for bps)
- Stalker widget shows active WiFi sessions count

### Security
- IPS config dropdown with clickable modals (rules, threat intel, geo-blocking)
- Geo-blocking modal with country flags and block counts (from IPS events)
- Security score fix — blocked threats count as positive, not penalty
- VPN detection from networkconf (+10 pts score)
- Firewall rules detection fix (meta.rc replaced with !empty data check)
- MongoDB ObjectId support in get_trad_site_id
- Blocked IPs country code from srcipCountry
- Security events pagination (MiniPagination)
- Cache TTL increased (5min settings, 2min events)

### Smart Triggers
- New device — alert on unknown MAC (with learning phase + cooldown)
- IPS Alert — notification on blocked attack
- High latency — configurable threshold alert
- VPN connection — connect/disconnect alerts
- Speed spike — traffic threshold per device

### System Settings
- Data retention — per-table sliders (7-730 days)
- Session security — timeout, max login attempts, lock duration
- Dashboard refresh — configurable polling interval
- Database management — size, records, VACUUM, config/DB export

### About System
- CPU, RAM, Disk, Uptime in modal
- Update channel info
- Dynamic detection of installed apps (Network, Protect, Talk, Access)
- VPN list from networkconf (OpenVPN + WireGuard)

### Notifications
- Alerts visible in bell panel (sendAlert -> SQLite events -> notification panel)
- Process Manager — real data from API
- WAN health — latency, packet_loss from gateway device stats

### Other
- Global pagination component (MiniPagination) — reusable
- Removed fake progress bars from Ingress/Egress
- Removed hardcoded Process Manager data
- monitored.php — detail modal fixed, add device button
- protect.php — improvements

---

## v2.0.0 (2026-04-10)

Major update: SQLite migration, Wi-Fi Stalker, enhanced Threat Watch, new notification channels, credential encryption.

### SQLite Database
- Migration from JSON files to SQLite as central storage
- Automatic migration system (migrations/) — new schema versions applied on startup
- Auto-purge of old data (configurable day thresholds per table)
- migrate_json.php script for one-time migration of existing data

### Wi-Fi Stalker (new)
- Real-time WiFi session tracking
- Roaming detection between Access Points
- Roaming history with RSSI, channel and timing
- Device watchlist with roaming alerts
- CSV export
- Filters: time range (1h/24h/7d/30d), band (2.4/5/6 GHz), search
- Dashboard widget with active session count
- 30s polling with automatic change detection

### Threat Watch Enhancements
- IP Ignore List — whitelist of IPs excluded from threat analysis
- Time range filters (1h/24h/7d/30d) on security events
- Auto-purge of old events (30 days)

### AP Drilldown
- Click on infrastructure device to expand client list
- WiFi clients (by ap_mac) and wired clients (by sw_mac with port number)
- Data from UniFi Traditional API (RSSI, network, speed, IP)

### New Notification Channels
- Discord — webhook with rich embeds
- n8n — generic webhook for automation
- Configuration panels in notification settings
- Test endpoint api_test_alert.php

### Credential Encryption
- Sensitive config.json fields encrypted with sodium_crypto_secretbox
- Automatic encryption on save, decryption on read
- Key auto-generated (data/.encryption_key)
- ENC: prefix on encrypted values

### Security & Structure
- Secrets moved from hardcoded values to .env
- Git initialized with .gitignore blocking sensitive data
- Removed UTF-8 BOM from all PHP files
- Debug/test files moved to _old/

### Footer
- App version + git hash (clickable changelog)
- LM-Networks branding with links (lm-ads.com, dev.lm-ads.com)
- Copyright 2025-2026

### Favicon
- SVG favicon on all pages

---

## v1.5.0 (2026-02-06)

### System Logs (logs.php)
- API-driven Event System instead of file parsing
- Severity filters (INFO/WARNING/ERROR/CRITICAL)
- Pagination: 25, 50, 100, 500 per page
- Modal with raw JSON event preview

### UniFi Protect Dashboard (protect.php)
- Dynamic camera grid: 1, 2, 4, 9, 12 views
- Interactive slots for camera source selection
- NVR status, disk usage, recording status and estimated archive length
- Bandwidth monitoring for cameras

### General
- Layout standardized to max-w-7xl
- Logs icon added to navigation

---

Created by Lukasz Misiura | dev.lm-ads.com
