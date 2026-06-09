-- Anti-flapping: pending OFFLINE grace tracking
-- Trzyma moment pierwszego zniknięcia urządzenia z sieci. Jeśli wróci przed
-- upływem progu (triggers.offline_grace_sec), wpis jest kasowany i alert OFFLINE
-- nie powstaje. Patrz saveDeviceHistory() w functions.php.
CREATE TABLE IF NOT EXISTS device_offline_pending (
    mac           TEXT PRIMARY KEY,
    missing_since TEXT NOT NULL
);
