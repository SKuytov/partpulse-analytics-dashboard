# PartPulse normalized package (format v1)

The PartPulse Normalizer (`tools/normalize.php`, `tools/PartPulse-Normalizer.bat`) and the server-side `.sql` import both produce a ZIP named `partpulse-normalized-YYYY-MM-DD.zip`. The dashboard imports these packages, and you can also open them in Excel or Power BI.

- Encoding: UTF-8 CSV with header row, comma separated, RFC 4180 quoting, `\n` line endings. Line breaks inside text are flattened to spaces.
- All timestamps are local time (`Europe/Sofia` by default), formatted as `YYYY-MM-DD HH:MM:SS`. Dates are `YYYY-MM-DD`.
- Empty cell = NULL.
- Template orders (`is_template = 1`) are excluded.

## manifest.json

| Key | Meaning |
|---|---|
| `format`, `version` | `partpulse-normalized`, `1` |
| `tool_version` | Normalizer version |
| `snapshot_date` | Date the snapshot represents (header "Generation Time", filename, or `--date`) |
| `source_generated_at` | Export time from the phpMyAdmin header, converted to local time |
| `normalized_at` | When the package was produced |
| `timezone`, `source_timezone` | Target and source time zones |
| `source_database`, `source_file`, `source_sha256` | Origin of the data |
| `counts` | Rows per CSV file |
| `removed` | Categories of data that were stripped |
| `checksums` | SHA-256 per CSV file; the importer rejects packages whose files do not match |

## orders.csv — one row per order, state at export time

`order_id, submitted_at, updated_at, status, priority, building_code, cost_center_id, category, item_description, part_number, quantity, unit_price, total_price, supplier_id, supplier_name, requester_id, requester_name, assigned_user_id, date_needed, expected_delivery_date, delivery_confirmed_at, approval_status, po_number`

`requester_name` comes from the user directory when available. `supplier_name` is resolved from `supplier_id`.

## order_history.csv — every logged change

`source, source_id, order_id, field_name, old_value, new_value, changed_by, changed_at, date_only`

- `source`: `history` (PartPulse `order_history`) or `audit` (`orders_audit_log`, for example automatic cancellations). `(source, source_id)` is unique, so daily uploads merge without duplicates.
- `changed_by`: the display name. Audit rows are resolved from the user id.
- Free-text fields (`supplier_notes`, `notes`, `cancellation_reason`, `alternative_product_description`, `assignment_notes`) have their values replaced with `[text]`.
- Date fields written as JavaScript strings ("Fri Oct 16 2026 00:00:00 GMT…") are normalized to `YYYY-MM-DD`.
- `date_only = 1` when the source only stored a date.

## assignments.csv

`source_id, order_id, from_user_id, to_user_id, by_user_id, assignment_type, created_at`

## Dimension files

- `suppliers.csv`: `supplier_id, name, country, is_eu, active` (no contacts)
- `users.csv`: `user_id, display_name, role, building_code, active` (no usernames, e-mails, phones or password hashes)
- `buildings.csv`: `building_code, name, active`
- `cost_centers.csv`: `cost_center_id, building_code, code, name, active`

## Never included

Password hashes, usernames, e-mails, phones, order notes, supplier contacts, sessions, login logs, documents, invoices and any table not listed above.
