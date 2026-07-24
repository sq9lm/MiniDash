# MiniDash — routing powiadomień Telegrama do topików grupy

Data: 2026-07-24

## Problem

MiniDash wysyłał wszystkie powiadomienia jednym strumieniem na czat prywatny
(`chat_id = 806689635`). Przy trzech botach piszących do Łukasza (MiniDash, SMS
z centrali, LastPour) alerty krytyczne mieszały się z informacyjnymi i nie dało
się wyciszyć samego szumu.

## Rozwiązanie

Osobna grupa Telegrama dla MiniDasha, z topikami po poziomie ważności.
Grupa `-1004308586996`, wątki: `3` = 🔴 Krytyczne, `4` = 🟠 Ostrzeżenia,
`5` = 🟢 Info. General zostaje celowo pusty.

Rozważana była alternatywa — podział po typie zdarzenia (WAN / Urządzenia / VPN /
Zagrożenia / Wi-Fi). Odrzucona, bo wymagałaby dopisania kategorii do 17 wywołań
`sendAlert()`, podczas gdy severity jest już parametrem tej funkcji.

## Normalizacja severity

Po kodzie krąży siedem nazw severity: `sendAlert()` dokumentuje trzy (`info`,
`warning`, `critical`), a doszły `attack` (mapa ikon), `alert`
(`config.php`, wykrycie zagrożeń) oraz `medium` i `high` (`functions.php`,
severity liczone z `inner_alert_action`). Mapa `$icons` zna tylko cztery — reszta
lądowała na ikonie 🔔.

Routing sprowadza je do trzech topików:

| Topik | Zbiera |
|---|---|
| 🔴 Krytyczne | `critical`, `attack`, `alert`, `high` |
| 🟠 Ostrzeżenia | `warning`, `medium` |
| 🟢 Info | `info` + każda nieznana wartość |

Nieznane severity celowo trafia do Info, a nie jest odrzucane — nowy poziom
dodany w przyszłości nigdy nie zgubi powiadomienia.

**Składnia komunikatów pozostaje bez zmian.** Prefiks `"$icon MiniDash:\n"`,
`parse_mode: Markdown` i mapa ikon nie są ruszane; dokładany jest wyłącznie
parametr `message_thread_id`. Niespójność mapy ikon (🔔 dla `medium`/`high`/
`alert`) zostaje jak była — do ewentualnego uporządkowania osobno.

## Zmiany w kodzie

- `config.php` — trzy nowe klucze w `telegram_notifications`
  (`thread_critical`, `thread_warning`, `thread_info`), funkcja
  `telegramThreadForSeverity()` i doklejenie `message_thread_id` w
  `sendTelegramNotification()`.
- `api_save_settings.php` — przyjęcie pól `tg_thread_critical`,
  `tg_thread_warning`, `tg_thread_info`.
- `functions.php` — trzy pola w modalu ustawień powiadomień. JS nie wymaga
  zmian, bo `saveNotifSettings()` wysyła `new FormData(form)`.

Token i `chat_id` są w `data/config.json` (token szyfrowany kluczem z
`data/.encryption_key`), nie w `.env` — przełączenie na grupę robi się przez UI.

## Kompatybilność wsteczna

Puste ID topiku = brak `message_thread_id` = zachowanie sprzed zmiany. Instalacja
z `chat_id` wskazującym czat prywatny działa dalej bez żadnej konfiguracji —
istotne, bo Telegram odrzuciłby `message_thread_id` wysłany na czat prywatny.

## Weryfikacja

1. Wysyłka testowa do wątków 3/4/5 — wszystkie przyjęte, ID potwierdzone
   (Telegram odrzuca nieistniejący `message_thread_id` błędem).
2. Test tablicy routingu dla ośmiu wartości severity plus regresja pustych ID.
3. Wysyłka end-to-end przez `sendTelegramNotification()` dla `critical`, `high`,
   `medium`, `info` — potwierdzone wizualnie w odpowiednich topikach.

## Poza zakresem

- Bot SMS z centrali (`dongle0`/`dongle1`) — świadomie nietykany.
- Asystent 24/7 czytający Telegram. Uwaga na przyszłość: **bot Telegrama nie
  dostaje wiadomości wysłanych przez innego bota**, więc wspólna grupa nigdy nie
  zadziała jako wspólny inbox dla asystenta. Dane trzeba brać u źródła.
