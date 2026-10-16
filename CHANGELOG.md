# Changelog

## 2.0.0 — Order-level history

- Upload the raw PartPulse Orders `.sql` export directly. It is parsed safely without executing SQL; only a sanitized ZIP is archived and the raw dump is deleted.
- PartPulse Normalizer for Windows/CLI (`tools/`) that produces the same sanitized package on your PC, with an optional CSV folder.
- Daily snapshots per order, merged change history (`order_history` + `orders_audit_log`), assignments, and dimension tables.
- Snapshot diff: new / completed / cancelled / reopened / updated / removed orders between uploads.
- New COO dashboard: period presets and custom ranges, comparison periods, KPIs with deltas, order flow, status mix, transitions, aging, lead time, work log per person, activity heatmap, buildings, suppliers, categories, requesters, SLA watch, late deliveries, "What changed" log, CSV exports, order timeline drawer, shareable URLs, dark mode.
- Built-in COO guide (`help.php`): every card, chart and table explained, linked from the Guide button and the “i” links on each card and panel, with search and print. Markdown copy in `docs/COO_GUIDE.md`.
- Optional `public_access_key` for the COO link.
- Automatic, idempotent database upgrade from v1, plus `sql/migrations/002_order_level_history.sql`.
- Legacy workbook dashboard kept at `workbook.php` (xlsx snapshots only).
- Test suite (`php tests/run.php`) with a synthetic fixture.

## 1.0.0

- XLSX workbook upload, archive and public dashboard.
