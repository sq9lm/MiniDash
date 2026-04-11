# MiniDash

Lightweight, modern dashboard for Ubiquiti UniFi networks. Single pane of glass for your network stats, security monitoring, device tracking and alerts.

Built with PHP 8.x, SQLite, Tailwind CSS and vanilla JavaScript. No frameworks, no bloat.

![License](https://img.shields.io/badge/license-MIT-blue)
![PHP](https://img.shields.io/badge/PHP-8.2+-purple)

---

## Features

### Dashboard
- Real-time WAN throughput (upload/download) with live chart
- Active clients count, WiFi networks, UniFi devices overview
- VLAN segmentation with per-network traffic stats
- Network latency (ping) monitoring to custom hosts
- CPU/RAM usage of the UniFi gateway
- Client detail modal with search, filters (WiFi/Wired/VPN), per-device stats

### Security (IPS/IDS)
- Security score (0-100%) based on 7 factors
- IPS events timeline with severity filters and pagination
- Geo-blocking overview with country flags and block counts
- Blocked IPs list with geolocation
- IPS rules browser (Critical/High/Medium/Low)
- Threat Intelligence and IP ignore list

### Wi-Fi Stalker
- Real-time WiFi session tracking
- Roaming detection between Access Points (with RSSI history)
- Watchlist with alerts on roaming events
- Filters by band (2.4/5/6 GHz) and time range
- CSV export

### Monitored Resources
- Track selected devices (servers, cameras, IoT) with online/offline status
- Status change alerts via all notification channels
- Device detail modal with live transfer stats and history

### UniFi Protect
- Camera grid (1/2/4/9/12 views)
- NVR status, storage, active sessions
- Camera VLAN traffic monitoring

### Notifications & Alerts
- 8 channels: Telegram, Discord, Slack, Email (SMTP), ntfy, WhatsApp, SMS, n8n
- Intelligent triggers: new device, IPS blocked, high latency, VPN connect/disconnect, speed spike
- In-app notification bell with event history

### Settings
- Controller connection config
- Custom ping hosts
- Module toggles (Protect, Monitoring)
- Data retention sliders (per table, 7-730 days)
- Session security (timeout, max attempts, lock duration)
- Dashboard refresh interval
- Database management (size, records, VACUUM, export)

### Internationalization
- Full PL/EN support with language switcher
- Translation system with `lang/pl.json` and `lang/en.json`

---

## Requirements

- PHP 8.1+ with extensions: `pdo_sqlite`, `curl`, `sodium`
- Web server (nginx or Apache)
- UniFi Controller/Gateway with API access (API key)

---

## Installation

### Manual (nginx + PHP-FPM)

1. Clone the repo:
   ```bash
   git clone https://github.com/your-repo/minidash.git /var/www/minidash
   ```

2. Copy and edit the environment file:
   ```bash
   cp .env.example .env
   nano .env
   ```

3. Set permissions:
   ```bash
   chown -R www-data:www-data data/ logs/
   chmod 600 .env data/.encryption_key
   ```

4. Configure nginx (see `docker/nginx.conf` for reference).

5. Open in browser — first run creates the SQLite database automatically.

### Docker

```bash
# Copy and edit env
cp .env.example .env
nano .env

# Build and run
docker-compose up -d
```

The dashboard will be available at `http://localhost:8080` (configurable via `MINIDASH_PORT`).

### Synology NAS (Web Station)

1. Upload files to a shared folder (e.g., `/web/minidash/`)
2. Set up PHP 8.2 profile in Web Station with `pdo_sqlite`, `curl`, `sodium`
3. Create `.env` with your UniFi credentials
4. Point a virtual host or reverse proxy to the folder

---

## Configuration

### UniFi API Key

Generate an API key in your UniFi Controller:
1. Go to **Settings > Admins & Users > API Keys**
2. Create a new key with **Read-Only** access
3. Paste the key in `.env` as `UNIFI_API_KEY`

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `UNIFI_CONTROLLER_URL` | `https://192.168.1.1` | UniFi Controller URL |
| `UNIFI_API_KEY` | — | API key (required) |
| `UNIFI_SITE` | `default` | Site ID |
| `ADMIN_USERNAME` | `admin` | Dashboard login |
| `ADMIN_PASSWORD` | — | Dashboard password |
| `ADMIN_FULL_NAME` | — | Display name |
| `ADMIN_EMAIL` | — | Email address |
| `DEBUG` | `false` | Enable debug mode |

All other settings (notifications, triggers, retention, modules) are configured via the web UI and stored in `data/config.json` (encrypted where applicable).

---

## Project Structure

```
minidash/
  index.php            # Dashboard
  security.php         # Security/IPS module
  stalker.php          # Wi-Fi Stalker
  monitored.php        # Monitored resources
  protect.php          # UniFi Protect
  logs.php             # Controller logs
  events.php           # Event history
  history.php          # Device history
  devices.php          # System settings
  login.php            # Authentication
  config.php           # Configuration & i18n
  functions.php        # Core functions
  db.php               # SQLite layer + migrations
  crypto.php           # Credential encryption
  update_wan.php       # Background data poller
  api_*.php            # AJAX API endpoints
  lang/
    pl.json            # Polish translations
    en.json            # English translations
  migrations/
    001_initial.sql    # Database schema
  includes/
    footer.php         # Footer + toast + pagination
    navbar_stats.php   # Navbar stats helper
  docker/
    nginx.conf         # Nginx config
    start.sh           # Docker entrypoint
  data/                # Runtime data (gitignored)
  logs/                # Application logs (gitignored)
```

---

## Security

- Session security: httponly cookies, fingerprint (SHA256 UA+IP), CSRF tokens, rate limiting
- Credential encryption: `sodium_crypto_secretbox` with auto-generated key
- All API endpoints require authentication
- Nginx blocks access to `.env`, `data/`, `logs/`, `.git`
- Input sanitization and `htmlspecialchars()` on all output
- File upload validation (MIME type, size limit, random filenames)

---

## Tech Stack

- **Backend**: PHP 8.x (no framework)
- **Database**: SQLite (PDO)
- **Frontend**: Tailwind CSS, Vanilla ES6+ JavaScript
- **Charts**: Chart.js
- **Icons**: Lucide
- **Container**: Docker (PHP 8.2-fpm-alpine + nginx)

---

## License

MIT License - see [LICENSE](LICENSE)

---

Created by Lukasz Misiura | [LM-Networks](https://www.lm-ads.com) | [dev.lm-ads.com](https://dev.lm-ads.com)
