# PartPulse Intelligence

A self-hosted PHP/MySQL dashboard for daily PartPulse Excel snapshots. The administrator logs in and uploads one `.xlsx` file each morning; the workbook is archived on the server, normalized into MySQL, and published immediately to a password-free executive dashboard.

## What it visualizes

- Total orders, total costs, SLA violations, and urgent violations
- Day-to-day trend of order volume and SLA violations
- Order-status distribution
- Cost allocation by building
- SLA pressure by requester
- Searchable/filterable SLA watchlist with CSV export
- Any earlier uploaded snapshot selectable from the public dashboard

The importer expects exactly these worksheets and headers:

- `Статус Разпределение`: `Статус`, `Брой`, `% от Общото`
- `SLA Нарушения`: `#`, `Артикул`, `Статус`, `Сграда`, `Приоритет`, `Дни в Статус`, `Праг (дни)`, `Заявител`
- `Разходи по Сграда`: `Код Сграда`, `Сграда`, `Разходи (EUR)`, `Поръчки`, `% от Общото`

## Server requirements

- PHP 8.2+
- MySQL/MariaDB with InnoDB and `utf8mb4`
- PHP extensions required by PhpSpreadsheet, including PDO MySQL, ZIP, XML/DOM, mbstring, fileinfo, GD, and iconv
- HTTPS strongly recommended

## SuperHosting deployment

1. In cPanel, choose PHP 8.2 or newer for the target domain/subdomain.
2. In **MySQL Databases**, create a database and a dedicated database user; assign **ALL PRIVILEGES**.
3. Open cPanel **phpMyAdmin**, select that database, choose **Import**, and import `sql/schema.sql`.
4. Download this repository, then run `composer install --no-dev --optimize-autoloader` on a computer with PHP/Composer. This creates `vendor/`.
5. Copy `config.example.php` to `config.php` and set the database credentials.
6. Generate the admin password hash:
   `php -r "echo password_hash('YOUR-LONG-UNIQUE-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"`
   Paste it into `config.php`; never put the plain password in the file.
7. Upload the project contents, including `vendor/`, to `public_html/partpulse/` (or the document root of a dedicated subdomain).
8. Confirm that `uploads/` is writable by PHP (typically permission 0750 or 0755 on shared hosting). Its `.htaccess` blocks direct web downloads.
9. Set PHP limits in cPanel/PHP Manager if necessary: `upload_max_filesize=25M`, `post_max_size=27M`, `memory_limit=256M`, `max_execution_time=120`.
10. Open `/login.php`, sign in, and upload the newest workbook. Share `/index.php` with the COO; it has no password.

## Daily use

1. Open `https://YOUR-DOMAIN/partpulse/login.php`.
2. Sign in.
3. Select the new dated `.xlsx` file and press **Import and publish**.
4. Open the public view to verify the new snapshot.

Identical workbooks are rejected by SHA-256 hash. Multiple uploads with different content and the same date are allowed, and the latest upload becomes the default public snapshot.

## Security notes

- Keep `config.php`, `vendor/`, and uploaded workbooks out of Git.
- Use a dedicated least-purpose database user, not the cPanel master user.
- The public dashboard intentionally exposes all imported operational data. Only deploy it on a URL/network appropriate for the COO and company policy.
- If `Options -Indexes` or Apache 2.4 directives are unsupported on the hosting account, adjust `.htaccess` with SuperHosting support rather than deleting upload protection.
- Back up both the MySQL database and the `uploads/` directory.

## Local validation

```bash
composer install
php -S 127.0.0.1:8080
```

A MySQL-compatible database and configured `config.php` are required.
