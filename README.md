# 🌐 UniFi MiniDash

**UniFi MiniDash** to lekki, nowoczesny i responsywny panel zarządzania dla systemów Ubiquiti UniFi. Został zaprojektowany, aby zapewnić szybki dostęp do kluczowych statystyk sieci, monitoringu kamer UniFi Protect oraz zaawansowanych logów systemowych w jednej, estetycznej konsoli.

Panel działa jako nakładka na oficjalne API kontrolera UniFi, oferując alternatywny, skondensowany widok najważniejszych parametrów (Single Pane of Glass).

---

## ✨ Kluczowe Funkcjonalności

### 🏠 Dashboard Główny (`index.php`)

Centrum dowodzenia Twoją siecią.

- **Obciążenie Łącza**: Bieżący uplaod/download w czasie rzeczywistym.
- **Status Sieci**: Liczba aktywnych klientów, urządzeń UniFi i status WAN (podwójny WAN supported).
- **Monitor Zasobów**: Wykresy użycia CPU i RAM kontrolera (UDM/UDR).
- **Ostatnie Zdarzenia**: Szybki podgląd logowań i alertów.

### 🎥 UniFi Protect Center (`protect.php`)

Pełnoprawny podgląd monitoringu wizyjnego.

- **Dynamiczny Grid Kamer**: Możliwość przełączania między widokami 1, 2, 4, 9 i 12 kamer jednym kliknięciem.
- **Live View**: Obsługa strumieniowania z kamer w czasie rzeczywistym.
- **Status NVR**: Informacje o zapełnieniu dysku, statusie nagrywania i estymowanej długości archiwum.
- **Statystyki**: Monitorowanie pasma zużywanego przez kamery.

### 🛡️ Audyt Bezpieczeństwa (`security.php`)

Panel dedykowany bezpieczeństwu sieci (IPS/IDS).

- **Security Score**: Dynamiczna ocena bezpieczeństwa sieci (0-100%) na podstawie konfiguracji firewalla i wykrytych zagrożeń.
- **Mapa Zagrożeń**: Lista zablokowanych adresów IP wraz z geolokalizacją i flagami krajów.
- **Zarządzanie IPS**: Status Intrusion Prevention System.
- **Honeypot**: Statystyki wykrytych skanowań sieci.

### 📊 Logi Systemowe (`logs.php`)

Zaawansowana przeglądarka zdarzeń systemowych.

- **Integracja API**: Pobieranie zdarzeń bezpośrednio z kontrolera (Firewall, Devices, Admin Actions).
- **Inteligentne Filtrowanie**: Filtrowanie po poziomach zagrożenia: `INFO`, `WARNING`, `ERROR`, `CRITICAL`.
- **Timeline**: Połączona oś czasu dla zdarzeń sieciowych i alertów bezpieczeństwa.
- **Paginacja**: Obsługa dużych zbiorów danych (stronicowanie po 25-500 wpisów).

### 📡 Monitoring Zasobów (`monitored.php`)

Szczegółowa lista kluczowych urządzeń.

- **Status Klientów**: Monitorowanie wybranych urządzeń (np. serwery, key devices) z podziałem na VLANy/grupy.
- **Uptime & Ping**: Wskaźniki dostępności najważniejszych zasobów.

---

## 🛠️ Technologie

Projekt zbudowany w oparciu o nowoczesny, lekki stack technologiczny:

- **Backend**: PHP 8.x (Brak frameworków - czysty, wydajny kod).
- **Frontend**: Tailwind CSS (Styling), JavaScript (Vanilla ES6+).
- **Design**: Glassmorphism UI, responsywność (Mobile-first), Ikony Lucide.
- **Komunikacja**: Bezpośrednie zapytania cURL do UniFOS API (Port 443).

## 🚀 Instalacja

1.  Wgraj pliki na serwer WWW z obsługą PHP (lokalny serwer lub bezpośrednio na UDM Pro/SE via SSH/Container).
2.  Skonfiguruj połączenie w pliku `data/config.json` lub `config.php`:
    - Adres IP Kontrolera
    - Dane logowania (zalecane utworzenie lokalnego administratora "Read-Only").
3.  Uruchom w przeglądarce - gotowe!

---

_(c) 2026 Łukasz Misiura | lm-network_
