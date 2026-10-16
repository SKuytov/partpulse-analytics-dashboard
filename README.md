# PartPulse Intelligence

A self-hosted PHP/MySQL analytics dashboard for **PartPulse Orders**, built for SuperHosting (cPanel, `public_html`, phpMyAdmin).

Each morning the administrator signs in and uploads one file. Every upload is stored on the server as a dated **snapshot**. The dashboard rebuilds each order's full timeline and shows **what changed** between any two dates. The COO opens a public link, with no password by default.

## What it shows

**Period analysis** for today, yesterday, this week, last week, this month, last 30/90 days, year-to-date, all time, or any custom range. Each period can be compared with the previous period, the same period last year, or a custom range.

- **KPIs with deltas and sparklines:** new orders, completed (delivered), cancelled, backlog change, open orders at period end, median lead time (with P90 and average), delivered value, orders placed, and on-time rate by needed date.
- **Order flow:** incoming vs completed per day, week or month; status mix at period start vs period end; the most frequent status transitions; aging of open orders; lead-time histogram.
- **Work log** ("who moved orders"): actions, orders touched, orders created, status moves, ordered, delivered and price updates per person. Click a name to filter the page to that person. Also includes an activity chart and a weekday × hour heatmap.
- **Allocation:** spend, new, done and open orders by building; suppliers delivered in the period (value, lead time, on-time); categories; requesters.
- **SLA watch:** orders that have stayed in a status longer than the configured threshold. **Late deliveries:** orders past their needed date.
- **What changed:** every logged change in the period (new, status changes, prices, suppliers, dates…), filterable by type, exportable to CSV.
- **Snapshot diff:** compare any two uploads. Each order is classified as new / completed / cancelled / reopened / updated / removed, with a CSV export.
- **Order drawer:** click any order to see its full timeline across all history and snapshots.
- Filters (building, priority, supplier, requester, category, person, free-text search), shareable URLs, light/dark theme, and a mobile layout.

- **Built-in guide** (`help.php`): the **Guide** button and the small “i” links on every card and panel open a full explanation of each metric: what it shows, how it is calculated and how to read it. It includes search, light/dark mode and print/PDF, and uses this server's SLA limits and time zone. A Markdown copy lives in [docs/COO_GUIDE.md](docs/COO_GUIDE.md).

The original v1 Excel workbook view is preserved at `workbook.php`.

## Accepted upload types

| File | What happens |
|---|---|
| `.sql` — phpMyAdmin export of the PartPulse Orders database | Parsed on the server **without executing any SQL**. Only reporting fields are kept. A sanitized ZIP is archived and the **raw dump is deleted** right after import. |
| `.zip` — package from the PartPulse Normalizer | Checksums are validated, then the package is imported. Use this when the raw dump should never leave your PC. |
| `.xlsx` — the earlier daily analysis workbook | Summary-only snapshot, shown in the legacy workbook view. |

Identical files are rejected (SHA-256). The snapshot date comes from the export header ("Generation Time") or the filename. You can override it on the upload form.

What is removed from `.sql` uploads: password hashes, usernames, e-mails, phones, order notes, supplier contacts, sessions, login logs, documents and invoices. Free-text history values (notes, cancellation reasons…) are replaced with `[text]`. See [docs/NORMALIZED_FORMAT.md](docs/NORMALIZED_FORMAT.md).

## Requirements

- PHP 8.2+ with PDO MySQL, zip, mbstring, fileinfo. Also XML/DOM, GD and iconv for `.xlsx` uploads (PhpSpreadsheet).
- MySQL 5.7+/8 or MariaDB 10.4+, InnoDB, `utf8mb4`.
- HTTPS strongly recommended.

## Fresh install on SuperHosting

1. **cPanel → Select PHP Version:** PHP 8.2 or newer. Enable `pdo_mysql`, `zip`, `mbstring`, `fileinfo`.
2. **cPanel → MySQL Databases:** create a database and a dedicated user, then grant ALL PRIVILEGES. The app adds its own tables when it upgrades, so it needs CREATE/ALTER.
3. **phpMyAdmin:** select the new database → **Import** → `sql/schema.sql`.
4. On your PC, run `composer install --no-dev --optimize-autoloader` in the project folder. This creates `vendor/`, which is only needed for `.xlsx` uploads.
5. Copy `config.example.php` to `config.php` and fill in the database credentials. Then generate the admin password hash:
   ```
   php -r "echo password_hash('YOUR-LONG-UNIQUE-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
   ```
6. Upload everything, including `vendor/` and `config.php`, to `public_html/partpulse/` (or a subdomain root). Make sure `uploads/` is writable (0755).
7. **PHP limits** (cPanel → Select PHP Version → Options, or MultiPHP INI Editor):

   | Setting | Value |
   |---|---|
   | `upload_max_filesize` | `64M` |
   | `post_max_size` | `64M` |
   | `memory_limit` | `512M` |
   | `max_execution_time` | `300` |

   The admin page shows the active limits under the upload box.
8. Open `https://YOUR-DOMAIN/partpulse/login.php`, sign in and upload today's export. Share `https://YOUR-DOMAIN/partpulse/` with the COO.

## Upgrading from v1

1. Back up the database (phpMyAdmin → Export) and `uploads/`.
2. Upload the new files over the old ones. Keep your `config.php`, then copy the new keys from `config.example.php` into it. They are all optional and have safe defaults.
3. Open `admin.php`. The schema upgrades automatically and shows a "Database upgraded" notice. If your database user may not ALTER tables, import `sql/migrations/002_order_level_history.sql` in phpMyAdmin instead.

Existing workbook snapshots stay available under **Workbooks**.

## Daily routine

1. In the PartPulse Orders phpMyAdmin: select the database → **Export** → Quick → SQL → **Go**.
2. Open `login.php` → choose the `.sql` file → **Import and publish**.
3. The snapshot list shows the change summary vs. the previous upload (for example "+3 new, 5 completed, 2 updated").

**Alternative — normalize on your PC first.** Drag the `.sql` file onto `tools/PartPulse-Normalizer.bat`. It creates `partpulse-normalized-YYYY-MM-DD.zip` next to the file. Upload that ZIP instead. The tool needs PHP on Windows: it uses `php` from PATH or `C:\xampp\php\php.exe`. Command-line usage:

```
php tools/normalize.php partpulse_orders.sql [--date=2026-10-16] [--out=folder] [--csv]
```

`--csv` also writes the plain CSV files into a folder, which is handy for Excel or Power BI.

## Configuration (`config.php`)

| Key | Default | Purpose |
|---|---|---|
| `allow_sql_upload` | `true` | Accept raw `.sql` exports. Set `false` to accept only normalized ZIPs. |
| `source_timezone` | `UTC` | Time zone of PartPulse timestamps. Converted to `timezone` (Europe/Sofia). |
| `public_access_key` | `''` | Optional key for the COO link (`/?key=…`, remembered for one year). Empty = open link. |
| `actor_aliases` | `admin → Admin Salim Kuytov` | Merge different spellings of the same person in the work log. |
| `sla_thresholds` | New 2, Pending 3, Quote Requested 5, Quote Received 3, Quote Under Approval 2, Approved 3, Ordered 14, In Transit 7 | Days per status before an order enters SLA watch. |
| `change_log_limit` | `3000` | Maximum rows in "What changed" for one period. |
| `max_upload_bytes` | 64 MB | Application upload limit. |

## Security notes

- The public dashboard exposes operational order data (items, suppliers, prices, requester names). Use `public_access_key` if the link may travel further than the COO.
- `config.php`, `vendor/`, `uploads/`, raw `.sql` dumps and normalized ZIPs are git-ignored. **Never commit a real PartPulse export.**
- `app/`, `sql/`, `tools/`, `tests/` and `uploads/` are blocked from the web by `.htaccess`. Uploaded raw dumps are deleted after parsing.
- Admin actions are protected by sessions and CSRF tokens. Database errors are logged, not shown.
- Back up the database and `uploads/` regularly.

## Development and tests

```bash
php tests/run.php                       # parser + normalizer unit tests
PP_TEST_DSN="mysql:host=127.0.0.1;dbname=pp_test;charset=utf8mb4" \
PP_TEST_USER=root PP_TEST_PASS= php tests/run.php   # + database integration (drops tables in that DB)
php -S 127.0.0.1:8080                   # local server (needs config.php)
```

Tests use only the synthetic fixture `tests/fixtures/sample_partpulse.sql`.

## Project layout

```
index.php            COO dashboard (public)        api.php           dashboard JSON API
help.php             COO guide & metric definitions (app/help_content.php)
workbook.php         legacy workbook view          api_workbook.php  legacy API (xlsx only)
admin.php            upload centre & snapshots     upload.php / delete.php
app/SqlDumpReader.php  safe phpMyAdmin dump parser (never executes SQL)
app/Normalizer.php     .sql → sanitized normalized package
app/OrderImporter.php  snapshot import, history merge, change classification
app/Analytics.php      period metrics, timelines, SLA, comparisons
app/Schema.php         idempotent auto-migration
tools/               Windows/CLI normalizer       sql/  schema + migrations
```
