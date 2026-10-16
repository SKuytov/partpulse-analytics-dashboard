# PartPulse COO guide

> This is the Markdown copy of the in-app guide (**Guide** button on the dashboard, `help.php`). The in-app version shows the SLA limits and time zone configured on the server; this copy uses the defaults from `config.example.php`.

## What this dashboard is

PartPulse Intelligence turns the PartPulse Orders system into a daily operations view. Every morning the procurement team uploads a fresh export of the PartPulse database. The dashboard keeps every upload as a dated **snapshot**. It also merges the full PartPulse **change log**, so every status change, price update and delivery-date change has a timestamp and a person.

Because each order's history is rebuilt from that change log, the dashboard can answer questions for **any date range**, including periods before the first upload. For example: how many orders came in last week, how many were delivered, whether the backlog grew, and who did the work.

### Reading the view in 60 seconds

1.  Check **“Data as of …”** at the top right. This is when the PartPulse export behind the view was made. Anything that happened after that is not in the view yet.
2.  Pick a period: **Yesterday** for the daily check-in, **Last week** for the weekly review, or a custom range.
3.  Read the eight cards. The small **▲/▼** line compares with the previous period. Green means better, red means worse.
4.  Scroll to **SLA watch** for what needs action now, and to **What changed** for the detailed audit trail.
5.  Click any order number to open its complete timeline.

### Badges used in this guide

Each metric shows which controls affect it:

- **Period** — Changes with the selected From–To dates.
- **Compared** — Shows a ▲/▼ delta against the comparison period.
- **Filters** — Respects building, priority, supplier, requester, category and search.
- **Person filter** — Narrows to one person when “Everyone (work log)” is set to a name.
- **Latest snapshot** — Always reflects the latest upload, not the selected period.

## Periods, comparisons and filters

### Period presets and custom dates

A period always covers **whole calendar days in Europe/Sofia time**, from 00:00:00 on the From date to 23:59:59 on the To date. Presets are calculated from today's date on the viewer's computer. Weeks run Monday to Sunday.

| Preset | Covers |
|----|----|
| Today | Today only. Only as complete as the latest upload (see “Data as of”). |
| Yesterday | The previous calendar day. The recommended daily check-in. |
| This week | Monday of the current week up to today. |
| Last week | The previous full Monday–Sunday week. |
| This month / Last month | The 1st of the current month up to today / the whole previous calendar month. |
| 7, 30, 90 days | The last 7, 30 or 90 days including today. **7 days is the default view.** |
| Year to date | 1 January up to today. |
| All time | From the first order in PartPulse up to today. |

Changing the From or To date switches to a custom period. Charts are grouped automatically: **daily** for periods up to about three months, **weekly** (starting Mondays) up to about 18 months, and **monthly** beyond that.

### Compare with

| Option | Comparison period |
|----|----|
| Previous period (default) | The same number of days immediately before the selected period. A full calendar month is compared with the full previous calendar month. |
| Same period last year | The same dates one year earlier. |
| Custom period… | Any range you choose with “Compare from” / “Compare to”. |
| No comparison | Hides the deltas and the dashed comparison line. |

**Tip.** “Previous period” compares an equal number of days, not the same weekdays. With **This week** on a Thursday (Mon–Thu, 4 days), the comparison is the previous Thu–Sun. For a like-for-like comparison, use **Custom period** and select last Monday–Thursday.

Deltas are shown as **▲ / ▼ absolute change (percentage)**. The percentage is left out for Backlog change and Median lead time, where it would be misleading. Colours always mean better or worse, not up or down. For example, a falling lead time is shown in green.

### Filters

- **Building, Priority, Supplier, Requester, Category** restrict the dashboard to matching orders. Matching uses each order's **current** values in the latest snapshot. For example, an order that moved from supplier A to supplier B counts under B.
- **Search** matches the order number, article description, part number and PO number.
- **Everyone (work log)** limits only the activity-based parts to one person: Actions logged, the work log, activity chart, heatmap, top status moves and What changed. Order counts such as New, Completed and Backlog stay the same, because orders are not owned by one person.
- **Reset** clears all filters but keeps the selected period.

The line under the title shows how many orders are **in scope** after filtering (all orders in the latest snapshot except PartPulse templates).

### Sharing, theme and printing

- **Link button** (chain icon): copies a link to exactly this view, including the period, comparison and filters. Use it to send “Last week, building CT” to someone.
- **Theme button** (half circle): switches between light and dark mode. The choice is remembered in this browser.
- **Export CSV** buttons under the tables download what you see (with the current filters) in a format Excel opens directly.

## The eight headline cards

Each card shows the value for the selected period, the change against the comparison period, and a short supporting line. Cards with a sparkline (small line chart) show the same value per day, week or month across the period.

#### New orders

**Affected by:** Period, Compared, Filters

**What it shows:** How many orders were submitted in PartPulse during the period. This is incoming demand.

**How it is calculated:** Count of orders whose **submission time** falls in the period. The supporting line shows the number of days and the average per day (new orders ÷ days in period).

**How to read it:** Higher than before means more demand reached procurement. Read it together with Completed: if New consistently exceeds Completed, the backlog grows.

#### Completed (delivered)

**Affected by:** Period, Compared, Filters

**What it shows:** How many orders were delivered during the period. This is output.

**How it is calculated:** Count of orders whose **current status is Delivered** and whose delivery moment falls in the period. The delivery moment is the last time the change log moved the order to Delivered. If that entry is missing, the delivery confirmation time is used, then the last update time. “x% of new” = completed ÷ new orders in the same period. These are not necessarily the same orders. “Cancelled” counts orders whose current status is Cancelled and whose cancellation falls in the period.

**How to read it:** Above 100% of new means the team closed more than came in, so the backlog shrinks. Cancelled orders are counted separately and never as completed.

#### Backlog change

**Affected by:** Period, Compared, Filters

**What it shows:** Whether the open workload grew or shrank during the period.

**How it is calculated:** **Open orders at the end of the period − open orders at the start.** “Open” means any status other than Delivered or Cancelled. The start is the end of the day before From. The end is the end of the To date, or the export time if that is earlier. Open counts are rebuilt from each order's status history.

**How to read it:** Negative (green) means the team reduced the backlog. Positive (red) means work accumulated. The supporting line shows start → end.

#### Open orders at period end

**Affected by:** Period, Compared, Filters

**What it shows:** The size of the open workload at the end of the period.

**How it is calculated:** Number of orders that were open (not Delivered/Cancelled) at the end of the period, rebuilt from status history. The supporting line shows how many orders are **currently** over SLA and late. These two figures come from the latest snapshot (see SLA watch).

**How to read it:** Lower is better. Compare with the same period last year to see whether the workload level is structurally changing.

#### Median lead time

**Affected by:** Period, Compared, Filters

**What it shows:** How long it typically takes from request to delivery.

**How it is calculated:** For every order delivered in the period: **delivery moment − submission time**, in days, including all time spent waiting for quotes, approval or on hold. The card shows the **median** (half of the orders were faster). The supporting line shows **P90** (90% were delivered faster than this, linear interpolation) and the average.

**How to read it:** A falling median (green) means faster service. A large gap between median and P90 means a minority of slow orders, which you can find in the lead-time chart and the supplier table. The average is pulled up by a few very old orders, so the median is the fairer headline figure.

#### Delivered value

**Affected by:** Period, Compared, Filters

**What it shows:** The euro value of what was delivered in the period.

**How it is calculated:** Sum of **total price** of the orders counted as Completed, using each order's current total price. The supporting line shows the **on-time rate**: of the delivered orders that have a “needed by” date, the share delivered on or before that date (n = how many had a needed-by date).

**How to read it:** This is the value received, not money committed (see Orders placed). An on-time rate based on a small n should be read with care.

#### Orders placed

**Affected by:** Period, Compared, Filters

**What it shows:** How many orders procurement actually placed with suppliers in the period.

**How it is calculated:** Count of orders whose **first** move to status **Ordered** falls in the period. “€… committed” is the sum of their current total prices.

**How to read it:** This shows procurement actively converting requests into purchase orders. Placed orders typically turn into deliveries one to several weeks later.

#### Actions logged

**Affected by:** Period, Compared, Filters, Person filter

**What it shows:** How much work was recorded in PartPulse during the period.

**How it is calculated:** Number of change-log entries in the period: status changes, price updates, delivery-date changes, supplier changes, notes and order creations. The supporting line shows the status moves (status changes excluding creation) and the number of different orders touched.

**How to read it:** A measure of activity, not output. A busy day with few completions usually means quoting and chasing work. Use the person filter to see one person's contribution.

## Flow, pipeline and speed

#### Incoming vs completed

**Affected by:** Period, Filters

**What it shows:** Bars for new, completed and cancelled orders per day, week or month, plus a line for the open backlog.

**How it is calculated:** New, Completed and Cancelled use exactly the KPI definitions above, grouped by bucket. The backlog line is the number of open orders at the end of each bucket.

**How to read it:** Completed bars higher than New bars push the backlog line down. Look for weeks where the line rises: something slowed down or demand spiked.

#### Status: start vs end

**Affected by:** Period, Filters

**What it shows:** The pipeline at the start of the period next to the pipeline at the end, by status.

**How it is calculated:** For each order, its status at that moment is rebuilt from the status change log. Orders not yet submitted at that moment are left out. Delivered and Cancelled are included so the totals stay comparable.

**How to read it:** Growing “Quote Requested” or “Quote Under Approval” bars point to bottlenecks before ordering. Growing “Ordered” or “In Transit” bars point to supplier lead times.

#### Top status moves

**Affected by:** Period, Filters, Person filter

**What it shows:** The 16 most frequent status changes in the period, for example “Quote Received → Quote Under Approval”.

**How it is calculated:** Count of status changes in the change log, grouped by from → to. Order creation is not included.

**How to read it:** Shows where the team spent its effort and which steps happen most. Unexpected moves (for example back to “New”) deserve a look in What changed.

#### Open order age

**Affected by:** Period, Filters

**What it shows:** How old the open orders are at the end of the period, split by status.

**How it is calculated:** For every order open at the end of the period: days since submission, grouped as 0–7, 8–14, 15–30, 31–60, 61–90 and 90+ days.

**How to read it:** A healthy backlog is mostly young. Orders older than 60 days that are still in early stages (New, Quote Requested) are usually stuck or forgotten.

#### Lead time to delivery

**Affected by:** Period, Filters

**What it shows:** The spread of lead times for orders delivered in the period.

**How it is calculated:** Each order counted as Completed is placed in a bucket by its lead time (submission → delivery): 0–3, 4–7, 8–14, 15–30, 31–60, 61–90, 90+ days. The note shows the median.

**How to read it:** A tall left side means fast service. A long right tail explains a high P90 on the lead-time card.

## Who moved orders, and when

The work log is built from the person recorded on each PartPulse change-log entry. Names that refer to the same person are merged using the configured aliases (“admin” → “Admin Salim Kuytov”). Entries without a name appear as “system”, for example automatic cancellations.

| Column | Meaning |
|----|----|
| Actions | All change-log entries by this person in the period. |
| Orders touched | Number of different orders the person changed. |
| Created | Orders the person created (the first status entry of an order). |
| Status moves | Status changes made, excluding creation. |
| Ordered | Status changes the person made to “Ordered”. |
| Delivered | Status changes the person made to “Delivered”. |
| Price updates | Changes to unit price or total price. |

Click a name to apply the person filter. The KPI “Actions logged”, the activity chart, heatmap, top status moves and What changed then show only that person's work. Use **Reset** to clear it.

#### Actions per day / week / month

**Affected by:** Period, Compared, Filters, Person filter

**What it shows:** Logged actions over time. With a comparison selected, a dashed line shows the comparison period aligned by position (first day vs first day). The line appears when both periods have the same number of bars.

**How it is calculated:** Change-log entries per bucket. Same definition as “Actions logged”.

**How to read it:** Use it to see workload peaks and quiet days, and how this period compares with the last one.

#### When work happens

**Affected by:** Period, Filters, Person filter

**What it shows:** A weekday × hour grid of activity in local time, plus a list of the most frequently changed fields.

**How it is calculated:** Each change-log entry with a full timestamp is counted in its weekday and hour (Europe/Sofia). Entries that only store a date are left out. Darker cells have more actions, relative to the busiest cell. The field list counts entries per field (Status, Total price, Delivery date…) with their share.

**How to read it:** Shows the team's working rhythm, for example quotes handled in the morning and deliveries confirmed in the afternoon.

## Where the work and the money go

#### By building

**Affected by:** Period, Filters

**What it shows:** Delivered value per building (chart) and a table of activity per building.

**How it is calculated:** **New** = submitted in period. **Done** = delivered in period. **Open** = orders of that building that are open **now** (latest snapshot). **Value** = delivered value in period. **Lead** = median lead time of the building's deliveries in the period. Sorted by value.

**How to read it:** Compare spend with demand: a building with high New but low Done is waiting on procurement or suppliers.

#### Suppliers — delivered in period

**Affected by:** Period, Filters

**What it shows:** The 25 suppliers with the highest delivered value in the period.

**How it is calculated:** **Orders** = deliveries in period. **Value** = their total price. **Lead** = median lead time from request to delivery. **On time** = share delivered on or before the “needed by” date (— when none had one). “—” as a supplier name means no supplier was set.

**How to read it:** Lead time here is the full request-to-delivery time, so it includes internal quoting and approval steps, not only the supplier's transit time.

#### New orders by category

**Affected by:** Period, Filters

**What it shows:** The 15 categories with the most new orders in the period.

**How it is calculated:** Count of orders submitted in the period, grouped by their PartPulse category. Orders without a category appear as “Uncategorised”.

**How to read it:** Shows what kind of demand is coming in. A rising share of “Uncategorised” is a data-quality signal for requesters.

#### Requesters

**Affected by:** Period, Filters

**What it shows:** The 15 people who submitted the most orders in the period.

**How it is calculated:** **New in period** = orders they submitted in the period. **Still open** = how many of those are not Delivered or Cancelled now.

**How to read it:** Helps when talking to departments about demand and follow-ups.

## SLA watch and late deliveries

These two lists are an action queue. They always reflect the **latest upload** (the “Data as of” moment) and ignore the selected period, because they describe what needs attention now. Filters still apply.

#### Over SLA

**Affected by:** Latest snapshot, Filters

**What it shows:** Open orders that have stayed in their current status longer than allowed.

**How it is calculated:** Days in status = whole days between the order's last status change (or submission, if it never changed) and the export time. An order is listed when days in status is **greater than** the limit for its status. Urgent orders are listed first, then by days descending. A red number means more than three times the limit.

**How to read it:** Work the list top-down. Statuses without a limit (for example On Hold, Partially Delivered) are never listed.

#### Late deliveries

**Affected by:** Latest snapshot, Filters

**What it shows:** Orders that should already have arrived.

**How it is calculated:** Orders with status Approved, Ordered, In Transit or Partially Delivered whose expected delivery date is **before** the export date. Days late = export date − expected date. Sorted by days late.

**How to read it:** These are the orders to chase with suppliers. An expected date in the past also often means the date in PartPulse needs updating.

### Current SLA limits

Configured by the administrator (`sla_thresholds` in `config.php`):

| Status               | Allowed in status |
|----------------------|-------------------|
| New                  | 2 days            |
| Pending              | 3 days            |
| Quote Requested      | 5 days            |
| Quote Received       | 3 days            |
| Quote Under Approval | 2 days            |
| Approved             | 3 days            |
| Ordered              | 14 days           |
| In Transit           | 7 days            |

Use the search box and priority selector above the list to narrow it. **Export CSV** downloads the current list.

## What changed

A chronological list (newest first) of every recorded change in the period, with who made it. This is the detailed record of the work done.

| Type chip | Meaning |
|----|----|
| Created | A new order was submitted. Orders without a creation entry in the log are added from their submission time. |
| Completed | Status changed to Delivered. |
| Cancelled | Status changed to Cancelled, including automatic cancellations from the audit log. |
| Reopened | Status changed from Delivered or Cancelled back to an open status. |
| Status move | Any other status change. |
| Price | Unit or total price changed. |
| Delivery date | Expected delivery date changed. |
| Supplier | Supplier changed. |
| Other | Any other field (quote, alternative product, notes…). |

- Free-text values (notes, cancellation reasons, supplier notes) appear as `[text]`. Their content is removed for privacy before the data reaches this server. The fact that they changed is kept.
- Some PartPulse fields only store a date. Those entries show the date without a time.
- The table shows the first 1,500 rows. **Export CSV** contains all rows matching the chip and search. One period returns at most 3000 changes. Narrow the period if you see the “capped” note.

## Snapshot diff

Compares the complete order list of two uploads, order by order. By default it compares the latest upload with the one before it, which shows exactly what changed since yesterday's upload. Choose any two uploads under Earlier / Later and press **Compare**.

| Result | Rule |
|----|----|
| New | The order exists in the later upload but not in the earlier one. |
| Completed | The status changed to Delivered (from an open status). |
| Cancelled | The status changed to Cancelled. |
| Reopened | The order was Delivered/Cancelled and is open again. |
| Updated | Any other tracked field changed: status, priority, building, cost centre, category, description, quantity, unit/total price, supplier, requester, assignee, needed-by date, expected delivery date, approval status, PO number. |
| Removed | The order was in the earlier upload but not in the later one, for example deleted in PartPulse or turned into a template. Removed orders are never counted as completed. |

The “What changed” column lists each changed field as old → new. The admin snapshot list shows the same summary for every upload.

**Snapshot diff vs What changed.** “What changed” comes from the PartPulse change log and has exact times and names. The snapshot diff compares two complete pictures, so it also catches changes PartPulse does not log (for example a corrected description), but without a time or person.

## Order timeline

Click any order number anywhere on the dashboard to open its panel on the right:

- **Facts**: current status, priority, submission time, requester, supplier, category, quantity, prices, needed-by and expected dates, part number and PO.
- **Timeline**: every logged change, newest first, with time and person, ending with the submission.
- **In daily snapshots**: the order's status, total and expected date in each upload. Shown when the order appears in more than one upload.

Close with ✕, by clicking outside the panel, or with Esc.

## Where the data comes from

1.  **PartPulse Orders** (the procurement system) stores orders and a change log of every edit.
2.  Each morning the administrator exports its database from phpMyAdmin and uploads it on the admin page. Alternatively, the PartPulse Normalizer on the administrator's PC creates a cleaned package first.
3.  The server reads the export **without running it**, keeps only reporting fields and deletes the raw file. Removed before anything is stored: passwords, usernames, e-mail addresses, phone numbers, order notes, supplier contacts, sessions, login logs, documents and invoices.
4.  The upload becomes a **snapshot**: the state of every order at the export moment. New change-log entries are added to the permanent history, and duplicates are skipped automatically.
5.  When you open the dashboard, each order's timeline (submitted → status changes → delivered/cancelled) is rebuilt from the history, and all period figures are calculated from those timelines.

### Time

PartPulse stores times in UTC. The dashboard converts everything to **Europe/Sofia**, including summer and winter time. A period “day” is a local calendar day.

### Freshness

The dashboard is as current as the latest upload. Changes made in PartPulse after the export appear after the next upload. “Today” is therefore usually incomplete until the next morning. “Yesterday” is complete once today's export is uploaded.

### Which orders count

All orders in the latest upload except PartPulse templates. Each order uses its current building, supplier, category and price for filtering and value figures.

## Limitations and edge cases

- **Deleted orders** disappear from the latest upload, and with that from all period figures. They remain visible as “Removed” in the snapshot diff.
- **Values use the current price.** If a price was corrected after delivery, past periods show the corrected value.
- **Reopened orders.** An order counts as completed only if it is Delivered now. If it was delivered last week and reopened today, last week's Completed figure drops by one after today's upload.
- **Orders without status history** (for example very old ones) are reconstructed from their submission time, current status and last update. Their exact delivery moment may be approximate.
- **Lead time is request-to-delivery.** It includes internal steps (quotes, approvals, on hold), not only supplier transit.
- **Work log credit** goes to the name PartPulse recorded. Actions done outside PartPulse (phone calls, e-mails) are not visible.
- **SLA and late lists** always describe the latest upload, never a past period.

## Common questions

#### Why does “Today” show zero or very little?

The view only contains what was in the latest export. If today's export was made early in the morning or has not been uploaded yet, today has little data. Check “Data as of” at the top.

#### Why do the numbers differ from the old Excel workbook?

The workbook showed totals at one moment. This dashboard counts events within a period (submitted, delivered, cancelled) from the change log. All-time totals and SLA counts match the workbook for the same export.

#### Why is “x% of new” above 100%?

The team delivered more orders in the period than came in, for example by clearing older backlog. The orders delivered need not be the orders that came in.

#### Why didn't someone's action appear in the work log?

Only changes PartPulse writes to its change log are counted, under the name it recorded. If the same person appears under two names, the administrator can merge them with `actor_aliases`.

#### Can I see the state on a past day?

Yes. Set From and To to that day. The status mix “end” bar and Open orders at period end show the pipeline at the end of that day. The snapshot diff and the order timeline show the uploads themselves.

#### Is the data safe to share?

The view contains operational order data (articles, suppliers, prices, requester names) but no passwords, contact details or notes. Share the link only with people who may see procurement data. The administrator can require a private key in the link.

## Statuses and terms

| Term | Meaning |
|----|----|
| New, Pending | Request received, not yet worked on / waiting for information. |
| Quote Requested → Quote Received → Quote Under Approval | Quotation steps before the purchase decision. |
| Approved | Purchase approved, not yet ordered. |
| Ordered, In Transit, Partially Delivered | Ordered from the supplier and on the way. |
| On Hold | Paused deliberately; no SLA limit. |
| Delivered | Closed, received. Counts as Completed. |
| Cancelled | Closed without delivery. |
| Open | Any status except Delivered and Cancelled. |
| Backlog | The number of open orders at a moment. |
| Snapshot | One daily upload: the state of all orders at the export moment. |
| Change log | PartPulse's record of every edit (order history and audit log), merged across uploads. |
| Median / P90 | The middle value / the value 90% of cases stay below. |
| SLA | The agreed maximum number of days an order may stay in a status. |
