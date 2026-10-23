# SPMS AWS Deployment & Operational Guide

## 1. Executive Summary & Architecture Overview

SPMS is engineered for a lightweight, cost-effective single-instance deployment on **Amazon Web Services (AWS)** that runs comfortably within an existing **$70 AWS Account Credit** budget with **₹0 personal spending**.

### Architecture: Single-Instance Monolith
```
                      Internet (Users / SEMS / Open-Meteo)
                                       │
                               HTTPS (Port 443)
                                       ▼
             ┌──────────────────────────────────────────────────┐
             │               AWS Security Group                 │
             │   Allow 80/443 (All), Allow 22 (Admin IP only)   │
             │             Block 3306 (External)                │
             └─────────────────────────┬────────────────────────┘
                                       │
                    Amazon EC2 (Ubuntu 24.04/22.04 LTS)
            ┌──────────────────────────┴─────────────────────────┐
            │ Nginx Web Server (Reverse Proxy, SSL, Static Assets)│
            │  ├── /           -> /var/www/spms/frontend/dist    │
            │  └── /api/*      -> FastCGI Unix Socket            │
            ├──────────────────────────┬─────────────────────────┤
            │                          ▼                         │
            │ PHP 8.4-FPM Engine (/var/www/spms/public/index.php)│
            │  ├── REST API Controllers & Middleware             │
            │  └── GoodWe SEMS & Weather Domain Services         │
            ├──────────────────────────┬─────────────────────────┤
            │                          ▼                         │
            │ MySQL 8.0 Server (127.0.0.1:3306 local only)      │
            │  └── Database: spms_real (25 tables, zero demo)    │
            └────────────────────────────────────────────────────┘
```

---

## 2. AWS Credit Budget & Cost Analysis (Mumbai: `ap-south-1`)

| Component | Specification | Hourly Cost | Monthly Cost (30.5 days) |
| :--- | :--- | :--- | :--- |
| **EC2 Instance** | `t4g.small` (2 vCPU, 2 GB RAM, ARM64) | $0.0168 / hr | ~$12.26 / mo |
| **EBS Storage** | 25 GB gp3 General Purpose SSD | — | ~$2.20 / mo |
| **Public IPv4** | In-use public IPv4 address | $0.0050 / hr | ~$3.65 / mo |
| **Data Transfer** | Outbound internet (first 100 GB/mo free) | — | $0.00 |
| **Total Estimated Cost** | | | **~$18.11 / month** |

### Runway with $70 Credits:
- **`t4g.small` (Recommended):** ~$18.11/mo → **3.85 months** (116+ days) of 24/7 continuous operation.
- **`t4g.micro` (1 GB RAM + 1 GB swap):** ~$11.54/mo → **6.06 months** (180+ days) of continuous operation.
- **Personal Out-of-Pocket Expense:** **₹0** (100% absorbed by credits).

---

## 3. Pre-Flight Deployment Checklist

- [x] Backend test suite passed: **833/833 tests passed (0 failures)**.
- [x] Frontend test suite passed: **498/498 tests passed (0 failures)**.
- [x] Frontend production bundle built: `dist/index.html` (single-file production bundle verified).
- [x] Database integrity verified: `spms_real` contains **41,884 ACTUAL records, 0 SIMULATED records**.
- [x] Environment isolation active: `GoodWeSemsPortalAdapter` enforced, simulated snapshots blocked.
- [x] Security verified: credentials encrypted with AES-256-GCM, zero secrets in source control.

---

## 4. Step-by-Step EC2 Provisioning Guide

### Step 4.1: Launch EC2 Instance (AWS Console)
1. Sign in to AWS Console and select region: **Asia Pacific (Mumbai) `ap-south-1`**.
2. Navigate to **EC2** → **Launch Instance**:
   - **Name:** `spms-production-server`
   - **OS Image:** **Ubuntu Server 24.04 LTS (HVM)** or **Ubuntu 22.04 LTS**.
   - **Architecture:** `Arm64` (for `t4g.small` / `t4g.micro`) or `64-bit (x86)` (for `t3.small` / `t3.micro`).
   - **Instance Type:** `t4g.small` (Recommended: 2 vCPU, 2 GiB RAM).
   - **Key Pair:** Create or select an existing `.pem` key pair (e.g., `spms-key.pem`).
3. **Network Settings (Security Group):**
   - Create Security Group `spms-web-sg`:
     * Rule 1: **SSH (22)** — Source: `My IP` (for administrator access).
     * Rule 2: **HTTP (80)** — Source: `Anywhere (0.0.0.0/0)`.
     * Rule 3: **HTTPS (443)** — Source: `Anywhere (0.0.0.0/0)`.
     * **CRITICAL:** Do NOT add rule for MySQL (3306).
4. **Storage:**
   - 25 GiB gp3 root volume (3,000 IOPS, 125 MB/s throughput default).
5. Click **Launch Instance**. Note the assigned **Public IPv4 Address** (e.g. `13.233.X.X`).

---

### Step 4.2: Connect and Run Provisioning
From your local terminal (Windows PowerShell):
```powershell
ssh -i "path/to/spms-key.pem" ubuntu@YOUR_EC2_PUBLIC_IP
```

On the EC2 server, execute:
```bash
# Clone the repository
sudo git clone https://github.com/YOUR_USER/SPMS.git /var/www/spms
cd /var/www/spms

# Run automated host provisioning
sudo bash deploy/scripts/setup-ec2.sh
```

---

### Step 4.3: Configure Production Database
```bash
sudo mysql
```
```sql
CREATE DATABASE spms_real CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'spms_app_user'@'127.0.0.1' IDENTIFIED BY 'GENERATE_A_STRONG_PASSWORD_HERE';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, REFERENCES ON spms_real.* TO 'spms_app_user'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

---

### Step 4.4: Configure Production Secrets
```bash
sudo cp deploy/env/production.env.example /var/www/spms/.env
sudo nano /var/www/spms/.env
```
Populate:
- `DB_PASSWORD`: Set the password created above.
- `APP_KEY`: Generate a 64-character hex key:
  ```bash
  php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
  ```
- `APP_URL`: `http://YOUR_EC2_PUBLIC_IP` (or your domain).
- Verify `SPMS_DATA_ENVIRONMENT=real`.

---

### Step 4.5: Deploy Application & Build
```bash
sudo bash /var/www/spms/deploy/scripts/deploy-app.sh
```
This script will:
1. Verify `.env` configuration and permissions.
2. Install production Composer packages (`--no-dev`).
3. Set directory permissions on `storage/`.
4. Apply database migrations to `spms_real`.
5. Configure Nginx and PHP-FPM.
6. Run an automated health check probe against `/api/health`.

---

### Step 4.6: Configure HTTPS / SSL
If using a custom domain (e.g., `spms.yourdomain.com`):
```bash
sudo apt-get install -y certbot python3-certbot-nginx
sudo certbot --nginx -d spms.yourdomain.com
```

If testing without a custom domain, use a free dynamic DNS service like [DuckDNS](https://www.duckdns.org/) or [nip.io](https://nip.io) (e.g., `13-233-X-X.nip.io`) with Let's Encrypt, or operate over HTTP for initial assessment.

---

## 5. Scheduled Telemetry Collection & Monitoring

Enable the automated systemd timer only when you are ready to enable background synchronization:
```bash
# Enable and start the 5-minute timer
sudo cp deploy/systemd/spms-collector.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now spms-collector.timer

# Check timer status
systemctl list-timers | grep spms

# View collection logs
tail -f /var/log/spms/collector.log
```

To stop or disable collection at any time:
```bash
sudo systemctl stop spms-collector.timer
sudo systemctl disable spms-collector.timer
```

---

## 6. Backup, Restoration & Maintenance

### Automated Nightly Backup
Add to root crontab (`sudo crontab -e`):
```cron
0 2 * * * /bin/bash /var/www/spms/deploy/scripts/backup-db.sh >> /var/log/spms/backup.log 2>&1
```

### Manual Backup
```bash
sudo bash /var/www/spms/deploy/scripts/backup-db.sh
```

### Restoration
```bash
gunzip < /var/backups/spms/spms_real_TIMESTAMP.sql.gz | mysql -u spms_app_user -p spms_real
```
