# Naprawa statystyk i powiadomień w MiniDash

### 1. Poprawka statystyk transferu i zużycia danych
- **Mnożnik BPS:** Skorygowano mnożnik z `* 1000` na `* 8` we wszystkich kluczowych miejscach (lista klientów, modal VLAN, modal szczegółów klienta), aby poprawnie przeliczać bajty na bity na sekundę (UniFi API -> bps).
- **Statystyki per User:** Dodano wyświetlanie całkowitego zużycia danych (Download/Upload) obok bieżącego transferu w głównej tabeli klientów.
- **Segmentacja VLAN:** Rozszerzono listę VLAN o zagregowany ruch w czasie rzeczywistym oraz sumaryczne zużycie danych dla całego segmentu.
- **Modal VLAN:** Zaktualizowano tabelę w modalu VLAN, aby pokazywała statystyki "Live / Suma" dla każdego podłączonego urządzenia.

### 2. Naprawa powiadomień systemowych
- **Rozszerzenie zdarzeń:** Do funkcji `get_recent_events()` dodano obsługę nowych typów zdarzeń z UniFi API:
  - `EVT_WC_Discovered` / `EVT_WD_Discovered` (Wykrycie nowych urządzeń)
  - `EVT_WU_Roam` / `EVT_WU_RoamRadio` (Roaming między punktami dostępowymi)
  - `EVT_WU_Connected` / `EVT_LU_Connected` (Połączenia WiFi i LAN)
- **Wizualizacja:** Poprawiono ikony i etykiety w pasku powiadomień. Nowe urządzenia są teraz oznaczane purpurową ikoną `plus-circle` i etykietą "Nowy Klient".

### 3. Stabilizacja kodu
- Naprawiono błąd w szablonie JavaScript modalu klienta, gdzie omyłkowo wstrzyknięto tagi PHP.
- Zapewniono spójność danych między backendem (PHP) a frontendem (JS) poprzez poprawne przekazywanie sumarycznych statystyk VLAN.
