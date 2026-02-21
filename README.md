# Jessica Weekly Tracker

## Overview

- `index.html`: Main tracker UI (stores weekly progress, can send manual emails).
- `tracker-data.php`: Server persistence for tracker data (`GET`/`POST` JSON).
- `reminder-cron.php`: Automated reminder sender (week-aware, reads saved tracker data).
- `reminder.html`: Thin browser bridge that calls `reminder-cron.php`.
- `send-email.php`: API used by `index.html` for manual email sending.
- `mailer.php`: Shared SMTP mail logic.
- `secrets.json`: Single source of SMTP + default reminder recipients.

## 1) Configure SMTP Once

Edit `secrets.json`:

```json
{
  "smtpHost": "smtp.gmail.com",
  "smtpPort": 587,
  "smtpSecure": "tls",
  "smtpUser": "you@gmail.com",
  "smtpPass": "YOUR_APP_PASSWORD",
  "fromEmail": "you@gmail.com",
  "fromName": "Jessica Tracker",
  "cronSecret": "",
  "defaultJessicaEmail": "jessica@example.com",
  "defaultAdminEmail": "admin@example.com"
}
```

Notes:
- For Gmail, use an App Password (not account password).
- Leave `cronSecret` empty if you do not want secret-based protection.

## 2) Nginx + PHP-FPM

Allow these PHP endpoints:
- `/tracker-data.php`
- `/send-email.php`
- `/reminder-cron.php` (+ optional path day style `/reminder-cron.php/monday`)

Example Nginx block:

```nginx
server {
    listen 80;
    server_name localhost;
    root /var/www/jessica-tracker;
    index index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location = /tracker-data.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location = /send-email.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ ^/reminder-cron\.php(?:/.*)?$ {
        fastcgi_split_path_info ^(.+?\.php)(/.*)$;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

Then:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 3) Data Persistence Permission

Create writable data folder:

```bash
sudo mkdir -p /var/www/jessica-tracker/data
sudo chown -R www-data:www-data /var/www/jessica-tracker/data
sudo chmod -R 775 /var/www/jessica-tracker/data
```

## 4) Running the App

Open:
- `http://your-host/index.html` (tracker)

Save progress from UI using **Save Progress**.
Data is persisted server-side by `tracker-data.php`.

## 5) Manual Email Sends from UI

From `index.html`:
- **Email Status to Admin** -> calls `send-email.php`
- **Send Reminder to Jessica** -> calls `send-email.php`

Recipient addresses come from the in-page setup modal (`Jessica/Admin` fields).
SMTP credentials come from `secrets.json`.

## 6) Automated Reminder Trigger

Use either:
- `http://your-host/reminder-cron.php?day=monday`
- `http://your-host/reminder-cron.php?day=friday`

or path format:
- `http://your-host/reminder-cron.php/monday`
- `http://your-host/reminder-cron.php/friday`

`reminder-cron.php` reads server-saved tracker data for the current week and includes all task types in email:
- Discussion Post
- Discussion Response
- Assignment
- Quiz

Optional browser bridge:
- `http://your-host/reminder.html?day=monday`
- `http://your-host/reminder.html?day=friday`

## 7) Quick Tests

```bash
curl -G "http://127.0.0.1/reminder-cron.php" --data-urlencode "day=monday"
curl -X POST "http://127.0.0.1/send-email.php" \
  -H "Content-Type: application/json" \
  -d '{"to_email":"you@example.com","subject":"Test","message":"Hello"}'
```
