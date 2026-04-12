# MiniDash — Installation Guide

## Requirements

- **PHP 8.1+** with extensions: `pdo_sqlite`, `curl`, `sodium`
- **Web server**: nginx, Apache, or Synology Web Station
- **UniFi Controller** with API key (UniFi OS 3.x+ / Network 8.x+)

### Getting a UniFi API Key

1. Log into your UniFi Controller
2. Go to **Settings > Admins & Users > API Keys**
3. Click **Create API Key**
4. Set permissions to **Read-Only** (recommended)
5. Copy the key — you'll need it during setup

---

## Option 1: Docker (recommended)

The fastest way to get MiniDash running. Works on any Docker host (Linux, Synology, QNAP, Windows, Mac).

### Step 1: Clone the repository

```bash
git clone https://github.com/sq9lm/MiniDash.git
cd MiniDash
```

### Step 2: Configure environment

```bash
cp .env.example .env
```

Edit `.env` with your values:

```env
# UniFi Controller address (IP or hostname with https)
UNIFI_CONTROLLER_URL=https://192.168.1.1

# API key from UniFi Controller (see above)
UNIFI_API_KEY=your-api-key-here

# UniFi site ID (default for most setups)
UNIFI_SITE=default

# Dashboard login credentials (choose your own)
ADMIN_USERNAME=admin
ADMIN_PASSWORD=your-secure-password

# Your name and email (displayed in profile)
ADMIN_FULL_NAME=Your Name
ADMIN_EMAIL=admin@example.com

# Set to true only for debugging
DEBUG=false
```

### Step 3: Build and start

```bash
docker-compose up -d
```

### Step 4: Open in browser

```
http://your-server-ip:8080
```

To change the port, set `MINIDASH_PORT` in `.env`:

```env
MINIDASH_PORT=3000
```

### Updating

```bash
cd MiniDash
git pull
docker-compose up -d --build
```

### Docker Compose reference

```yaml
version: '3.8'
services:
  minidash:
    build: .
    container_name: minidash
    restart: unless-stopped
    ports:
      - "${MINIDASH_PORT:-8080}:80"
    environment:
      - UNIFI_CONTROLLER_URL=${UNIFI_CONTROLLER_URL:-https://192.168.1.1}
      - UNIFI_API_KEY=${UNIFI_API_KEY}
      - UNIFI_SITE=${UNIFI_SITE:-default}
      - ADMIN_USERNAME=${ADMIN_USERNAME:-admin}
      - ADMIN_PASSWORD=${ADMIN_PASSWORD:-admin}
      - ADMIN_FULL_NAME=${ADMIN_FULL_NAME:-Admin}
      - ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}
      - DEBUG=${DEBUG:-false}
    volumes:
      - minidash_data:/var/www/html/data
      - minidash_logs:/var/www/html/logs

volumes:
  minidash_data:
  minidash_logs:
```

Data and logs are stored in Docker volumes and persist across container restarts.

---

## Option 2: Synology NAS (Container Manager — GUI)

The easiest way to run MiniDash on a Synology NAS with DSM 7.2+.

### Step 1: Upload project to NAS

Copy the MiniDash folder to your Synology, e.g. via File Station or SCP:

```
/volume1/docker/minidash/
```

Make sure the folder contains `docker-compose.yml`, `Dockerfile`, and all project files.

### Step 2: Configure environment

Create a `.env` file in the project folder (next to `docker-compose.yml`):

```bash
# Via SSH:
cd /volume1/docker/minidash
cp .env.example .env
nano .env
```

Fill in your values:

```env
UNIFI_CONTROLLER_URL=https://10.0.0.1
UNIFI_API_KEY=your-api-key-here
UNIFI_SITE=default
ADMIN_USERNAME=admin
ADMIN_PASSWORD=your-secure-password
ADMIN_FULL_NAME=Your Name
ADMIN_EMAIL=admin@example.com
DEBUG=false
MINIDASH_PORT=8080
```

### Step 3: Create project in Container Manager

1. Open **Container Manager** on your Synology
2. Go to **Project** → **Create**
3. Set a project name, e.g. `minidash`
4. Set the path to `/volume1/docker/minidash`
5. Container Manager will detect `docker-compose.yml` automatically
6. Click **Next**, review the settings, then click **Done**

The container will build and start automatically.

### Step 4: Open in browser

```
http://YOUR-NAS-IP:8080
```

Log in with the credentials from your `.env` file.

### Updating

1. Upload the new version of MiniDash to the same folder
2. In Container Manager → **Project** → select `minidash`
3. Click **Action** → **Build** (rebuild)
4. The container restarts with updated code; data in volumes is preserved

### Notes

- Data (SQLite database, avatars) and logs are stored in Docker volumes — they survive container rebuilds
- To change the port, edit `MINIDASH_PORT` in `.env` and rebuild
- Container Manager reads `.env` automatically — no need to enter variables manually in the GUI

---

## Option 3: Synology NAS (Web Station — manual)

### Step 1: Install PHP 8.2

1. Open **Package Center** on your Synology
2. Install **Web Station** (if not already)
3. Install **PHP 8.2** package
4. In Web Station, go to **Script Language Settings > PHP 8.2**
5. Enable extensions: `pdo_sqlite`, `curl`, `sodium`, `json`

### Step 2: Upload files

1. Download or clone MiniDash to your computer
2. Upload the files to a shared folder, e.g.: `/web/minidash/`
   - Use File Station or SSH/SCP
   - Do NOT upload `node_modules/`, `_old/`, `.git/`

### Step 3: Configure environment

Create `.env` in the MiniDash root folder:

```bash
# Via SSH:
cd /volume1/web/minidash
cp .env.example .env
nano .env
# Fill in your UniFi controller URL, API key, and admin credentials
```

### Step 4: Set permissions

```bash
chown -R http:http /volume1/web/minidash/data
chown -R http:http /volume1/web/minidash/logs
chmod 600 /volume1/web/minidash/.env
chmod 600 /volume1/web/minidash/data/.encryption_key
```

### Step 5: Configure Web Station

**Option A: Virtual Host (recommended)**

1. In Web Station, go to **Web Service Portal**
2. Create a new portal:
   - Type: **Name-based**
   - Hostname: e.g., `unifi.yourdomain.com`
   - Document root: `/volume1/web/minidash`
   - PHP version: **PHP 8.2**

**Option B: Reverse Proxy (with existing domain)**

1. In **Control Panel > Login Portal > Advanced > Reverse Proxy**
2. Create a new rule:
   - Source: `https://yourdomain.com/minidash`
   - Destination: `http://localhost:80` (where Web Station serves the files)

### Step 6: Open in browser

Navigate to your configured hostname or IP.
First visit creates the SQLite database automatically — no manual setup needed.

---

## Option 4: Any Linux Server (nginx + PHP-FPM)

### Step 1: Install dependencies

**Ubuntu/Debian:**
```bash
apt update
apt install php8.2-fpm php8.2-sqlite3 php8.2-curl php8.2-sodium nginx git
```

**CentOS/RHEL:**
```bash
dnf install php82-php-fpm php82-php-pdo php82-php-sodium php82-php-curl nginx git
```

### Step 2: Clone and configure

```bash
cd /var/www
git clone https://github.com/sq9lm/MiniDash.git minidash
cd minidash

cp .env.example .env
nano .env
# Fill in your values

chown -R www-data:www-data data/ logs/
chmod 600 .env
```

### Step 3: Configure nginx

Create `/etc/nginx/sites-available/minidash`:

```nginx
server {
    listen 80;
    server_name unifi.yourdomain.com;
    root /var/www/minidash;
    index index.php;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml image/svg+xml;
    gzip_min_length 256;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options SAMEORIGIN;
    add_header X-XSS-Protection "1; mode=block";

    # Cache static assets
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff2|woff)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    # Block access to sensitive files
    location ~ /\.env { deny all; }
    location ~ /data/ { deny all; }
    location ~ /logs/ { deny all; }
    location ~ /tests/ { deny all; }
    location ~ /docs/ { deny all; }
    location ~ /migrations/ { deny all; }
    location ~ /node_modules/ { deny all; }
    location ~ /\.git { deny all; }

    # Allow avatars
    location /data/avatars/ {
        alias /var/www/minidash/data/avatars/;
    }

    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 30;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

Enable and restart:

```bash
ln -s /etc/nginx/sites-available/minidash /etc/nginx/sites-enabled/
nginx -t
systemctl restart nginx
```

### Step 4: Open in browser

Navigate to `http://unifi.yourdomain.com` — the database will be created on first visit.

---

## Option 5: Apache

### Step 1: Install dependencies

```bash
apt install php8.2 libapache2-mod-php8.2 php8.2-sqlite3 php8.2-curl php8.2-sodium git
a2enmod rewrite
```

### Step 2: Clone and configure

```bash
cd /var/www
git clone https://github.com/sq9lm/MiniDash.git minidash
cd minidash

cp .env.example .env
nano .env

chown -R www-data:www-data data/ logs/
chmod 600 .env
```

### Step 3: Apache VirtualHost

Create `/etc/apache2/sites-available/minidash.conf`:

```apache
<VirtualHost *:80>
    ServerName unifi.yourdomain.com
    DocumentRoot /var/www/minidash

    <Directory /var/www/minidash>
        AllowOverride All
        Require all granted
    </Directory>

    # Block sensitive directories
    <Directory /var/www/minidash/data>
        Require all denied
    </Directory>
    <Directory /var/www/minidash/logs>
        Require all denied
    </Directory>
    <Directory /var/www/minidash/tests>
        Require all denied
    </Directory>

    # Allow avatars
    <Directory /var/www/minidash/data/avatars>
        Require all granted
    </Directory>
</VirtualHost>
```

Enable and restart:

```bash
a2ensite minidash
systemctl restart apache2
```

---

## Post-Installation

### First login

Use the credentials you set in `.env` (`ADMIN_USERNAME` / `ADMIN_PASSWORD`).

### Configure notifications

After login, click your avatar (top right) > click the bell icon settings gear. Configure Telegram, Discord, or other channels.

### Configure triggers

In the notification settings modal, scroll to "Smart Triggers" to enable:
- New device alerts
- IPS/IDS blocked attack alerts
- High latency alerts
- Speed spike alerts

### Data retention

Go to **System Settings** (gear icon in user menu) > **Data Retention** to configure how long data is kept (7-730 days per table).

### Changing language

Go to **Personal** (user menu) > **Regional Settings** > select PL or EN.

---

## Troubleshooting

### "Database error" on first visit

Make sure the `data/` directory is writable by the web server:
```bash
chown -R www-data:www-data data/
chmod 770 data/
```

### Cannot connect to UniFi Controller

1. Verify the controller URL is correct (include `https://`)
2. Check that the API key is valid and has read access
3. If using self-signed certificates (default on UniFi), this is handled automatically
4. Verify the controller is reachable from the MiniDash server:
   ```bash
   curl -k https://192.168.1.1/proxy/network/api/s/default/stat/device
   ```

### Blank page / PHP errors

Enable error logging:
```bash
# Check PHP error log
tail -f /var/www/minidash/logs/php_errors.log

# Or check system log
tail -f /var/log/php8.2-fpm.log
```

### Session issues

If you get logged out frequently, check:
- PHP session directory is writable: `ls -la /var/lib/php/sessions/`
- Session timeout setting in System Settings

---

## Security Recommendations

1. **Use HTTPS** — set up Let's Encrypt or a reverse proxy with SSL
2. **Restrict access** — use firewall rules to limit access to trusted IPs
3. **Protect .env** — file permissions should be `600` (owner read/write only)
4. **Keep updated** — `git pull && docker-compose up -d --build`

---

Created by Lukasz Misiura | [LM-Networks](https://www.lm-ads.com) | [dev.lm-ads.com](https://dev.lm-ads.com)
