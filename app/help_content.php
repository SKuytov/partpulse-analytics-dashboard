<?php
declare(strict_types=1);
/**
 * COO guide content. Rendered by help.php (in the app) and exported to
 * docs/COO_GUIDE.md. Expects: $sla (status => days), $tz (display time zone),
 * $limit (change log cap), $aliases (actor aliases).
 *
 * Every definition below mirrors app/Analytics.php, app/OrderImporter.php and
 * assets/app.js. Update both together.
 */

/** Scope badges: which controls influence a metric. */
$scopeLabels = [
    'period' => ['Period', 'Changes with the selected From–To dates'],
    'compare' => ['Compared', 'Shows a ▲/▼ delta against the comparison period'],
    'filters' => ['Filters', 'Respects building, priority, supplier, requester, category and search'],
    'person' => ['Person filter', 'Narrows to one person when “Everyone (work log)” is set to a name'],
    'snapshot' => ['Latest snapshot', 'Always reflects the latest upload, not the selected period'],
];
$scope = static function (array $keys) use ($scopeLabels): string {
    $out = '';
    foreach ($keys as $k) $out .= '<span class="scope scope-' . $k . '" title="' . e($scopeLabels[$k][1]) . '">' . e($scopeLabels[$k][0]) . '</span>';
    return '<p class="scopes">' . $out . '</p>';
};
/** One metric definition block. */
$metric = static function (string $id, string $title, string $shows, string $calc, string $read, array $scopes) use ($scope): string {
    return '<article class="metric" id="' . e($id) . '"><h4>' . $title . '</h4>' . $scope($scopes)
        . '<dl><div><dt>What it shows</dt><dd>' . $shows . '</dd></div>'
        . '<div><dt>How it is calculated</dt><dd>' . $calc . '</dd></div>'
        . '<div><dt>How to read it</dt><dd>' . $read . '</dd></div></dl></article>';
};
$slaRows = '';
foreach ($sla as $status => $days) $slaRows .= '<tr><td>' . e((string)$status) . '</td><td class="num">' . (int)$days . ' days</td></tr>';
$aliasText = $aliases ? implode(', ', array_map(static fn($k, $v) => '“' . e((string)$k) . '” → “' . e((string)$v) . '”', array_keys($aliases), $aliases)) : 'none configured';
?>
<section id="start" class="guide-section">
  <p class="eyebrow">Start here</p>
  <h2>What this dashboard is</h2>
  <p>PartPulse Intelligence turns the PartPulse Orders system into a daily operations view. Every morning the procurement team uploads a fresh export of the PartPulse database. The dashboard keeps every upload as a dated <b>snapshot</b>. It also merges the full PartPulse <b>change log</b>, so every status change, price update and delivery-date change has a timestamp and a person.</p>
  <p>Because each order's history is rebuilt from that change log, the dashboard can answer questions for <b>any date range</b>, including periods before the first upload. For example: how many orders came in last week, how many were delivered, whether the backlog grew, and who did the work.</p>
  <div class="callout">
    <h3>Reading the view in 60 seconds</h3>
    <ol>
      <li>Check <b>“Data as of …”</b> at the top right. This is when the PartPulse export behind the view was made. Anything that happened after that is not in the view yet.</li>
      <li>Pick a period: <b>Yesterday</b> for the daily check-in, <b>Last week</b> for the weekly review, or a custom range.</li>
      <li>Read the eight cards. The small <b>▲/▼</b> line compares with the previous period. <span class="good">Green</span> means better, <span class="bad">red</span> means worse.</li>
      <li>Scroll to <b>SLA watch</b> for what needs action now, and to <b>What changed</b> for the detailed audit trail.</li>
      <li>Click any order number to open its complete timeline.</li>
    </ol>
  </div>
  <h3>Badges used in this guide</h3>
  <p>Each metric shows which controls affect it:</p>
  <ul class="scope-legend">
    <?php foreach ($scopeLabels as $k => [$label, $desc]): ?><li><span class="scope scope-<?= e($k) ?>"><?= e($label) ?></span> <?= e($desc) ?>.</li><?php endforeach; ?>
  </ul>
</section>

<section id="controls" class="guide-section">
  <p class="eyebrow">Controls</p>
  <h2>Periods, comparisons and filters</h2>

  <h3 id="periods">Period presets and custom dates</h3>
  <p>A period always covers <b>whole calendar days in <?= e($tz) ?> time</b>, from 00:00:00 on the From date to 23:59:59 on the To date. Presets are calculated from today's date on the viewer's computer. Weeks run Monday to Sunday.</p>
  <table class="def-table">
    <thead><tr><th>Preset</th><th>Covers</th></tr></thead>
    <tbody>
      <tr><td>Today</td><td>Today only. Only as complete as the latest upload (see “Data as of”).</td></tr>
      <tr><td>Yesterday</td><td>The previous calendar day. The recommended daily check-in.</td></tr>
      <tr><td>This week</td><td>Monday of the current week up to today.</td></tr>
      <tr><td>Last week</td><td>The previous full Monday–Sunday week.</td></tr>
      <tr><td>This month / Last month</td><td>The 1st of the current month up to today / the whole previous calendar month.</td></tr>
      <tr><td>7, 30, 90 days</td><td>The last 7, 30 or 90 days including today. <b>7 days is the default view.</b></td></tr>
      <tr><td>Year to date</td><td>1 January up to today.</td></tr>
      <tr><td>All time</td><td>From the first order in PartPulse up to today.</td></tr>
    </tbody>
  </table>
  <p>Changing the From or To date switches to a custom period. Charts are grouped automatically: <b>daily</b> for periods up to about three months, <b>weekly</b> (starting Mondays) up to about 18 months, and <b>monthly</b> beyond that.</p>

  <h3 id="compare">Compare with</h3>
  <table class="def-table">
    <thead><tr><th>Option</th><th>Comparison period</th></tr></thead>
    <tbody>
      <tr><td>Previous period (default)</td><td>The same number of days immediately before the selected period. A full calendar month is compared with the full previous calendar month.</td></tr>
      <tr><td>Same period last year</td><td>The same dates one year earlier.</td></tr>
      <tr><td>Custom period…</td><td>Any range you choose with “Compare from” / “Compare to”.</td></tr>
      <tr><td>No comparison</td><td>Hides the deltas and the dashed comparison line.</td></tr>
    </tbody>
  </table>
  <p class="tip"><b>Tip.</b> “Previous period” compares an equal number of days, not the same weekdays. With <b>This week</b> on a Thursday (Mon–Thu, 4 days), the comparison is the previous Thu–Sun. For a like-for-like comparison, use <b>Custom period</b> and select last Monday–Thursday.</p>
  <p>Deltas are shown as <b>▲ / ▼ absolute change (percentage)</b>. The percentage is left out for Backlog change and Median lead time, where it would be misleading. Colours always mean better or worse, not up or down. For example, a falling lead time is shown in green.</p>

  <h3 id="filters">Filters</h3>
  <ul>
    <li><b>Building, Priority, Supplier, Requester, Category</b> restrict the dashboard to matching orders. Matching uses each order's <b>current</b> values in the latest snapshot. For example, an order that moved from supplier A to supplier B counts under B.</li>
    <li><b>Search</b> matches the order number, article description, part number and PO number.</li>
    <li><b>Everyone (work log)</b> limits only the activity-based parts to one person: Actions logged, the work log, activity chart, heatmap, top status moves and What changed. Order counts such as New, Completed and Backlog stay the same, because orders are not owned by one person.</li>
    <li><b>Reset</b> clears all filters but keeps the selected period.</li>
  </ul>
  <p>The line under the title shows how many orders are <b>in scope</b> after filtering (all orders in the latest snapshot except PartPulse templates).</p>

  <h3 id="share">Sharing, theme and printing</h3>
  <ul>
    <li><b>Link button</b> (chain icon): copies a link to exactly this view, including the period, comparison and filters. Use it to send “Last week, building CT” to someone.</li>
    <li><b>Theme button</b> (half circle): switches between light and dark mode. The choice is remembered in this browser.</li>
    <li><b>Export CSV</b> buttons under the tables download what you see (with the current filters) in a format Excel opens directly.</li>
  </ul>
</section>

<section id="kpis" class="guide-section">
  <p class="eyebrow">Overview</p>
  <h2>The eight headline cards</h2>
  <p>Each card shows the value for the selected period, the change against the comparison period, and a short supporting line. Cards with a sparkline (small line chart) show the same value per day, week or month across the period.</p>
  <div class="metric-grid">
    <?= $metric('kpi-incoming', 'New orders',
        'How many orders were submitted in PartPulse during the period. This is incoming demand.',
        'Count of orders whose <b>submission time</b> falls in the period. The supporting line shows the number of days and the average per day (new orders ÷ days in period).',
        'Higher than before means more demand reached procurement. Read it together with Completed: if New consistently exceeds Completed, the backlog grows.',
        ['period', 'compare', 'filters']) ?>
    <?= $metric('kpi-completed', 'Completed (delivered)',
        'How many orders were delivered during the period. This is output.',
        'Count of orders whose <b>current status is Delivered</b> and whose delivery moment falls in the period. The delivery moment is the last time the change log moved the order to Delivered. If that entry is missing, the delivery confirmation time is used, then the last update time. “x% of new” = completed ÷ new orders in the same period. These are not necessarily the same orders. “Cancelled” counts orders whose current status is Cancelled and whose cancellation falls in the period.',
        'Above 100% of new means the team closed more than came in, so the backlog shrinks. Cancelled orders are counted separately and never as completed.',
        ['period', 'compare', 'filters']) ?>
    <?= $metric('kpi-net_backlog', 'Backlog change',
        'Whether the open workload grew or shrank during the period.',
        '<b>Open orders at the end of the period − open orders at the start.</b> “Open” means any status other than Delivered or Cancelled. The start is the end of the day before From. The end is the end of the To date, or the export time if that is earlier. Open counts are rebuilt from each order\'s status history.',
        'Negative (green) means the team reduced the backlog. Positive (red) means work accumulated. The supporting line shows start → end.',
        ['period', 'compare', 'filters']) ?>
    <?= $metric('kpi-backlog_end', 'Open orders at period end',
        'The size of the open workload at the end of the period.',
        'Number of orders that were open (not Delivered/Cancelled) at the end of the period, rebuilt from status history. The supporting line shows how many orders are <b>currently</b> over SLA and late. These two figures come from the latest snapshot (see SLA watch).',
        'Lower is better. Compare with the same period last year to see whether the workload level is structurally changing.',
        ['period', 'compare', 'filters']) ?>
    <?= $metric('kpi-lead_median', 'Median lead time',
        'How long it typically takes from request to delivery.',
        'For every order delivered in the period: <b>delivery moment − submission time</b>, in days, including all time spent waiting for quotes, approval or on hold. The card shows the <b>median</b> (half of the orders were faster). The supporting line shows <b>P90</b> (90% were delivered faster than this, linear interpolation) and the average.',
        'A falling median (green) means faster service. A large gap between median and P90 means a minority of slow orders, which you can find in the lead-time chart and the supplier table. The average is pulled up by a few very old orders, so the median is the fairer headline figure.',
        ['period', 'compare', 'filters']) ?>
    <?= $metric('kpi-spend', 'Delivered value',
        'The euro value of what was delivered in the period.',
        'Sum of <b>total price</b> of the orders counted as Completed, using each order\'s current total price. The supporting line shows the <b>on-time rate</b>: of the delivered orders that have a “needed by” date, the share delivered on or before that date (n = how many had a needed-by date).',
        'This is the value received, not money committed (see Orders placed). An on-time rate based on a small n should be read with care.',
        ['period', 'compare', 'filters']) ?>
    <?= $metric('kpi-ordered_count', 'Orders placed',
        'How many orders procurement actually placed with suppliers in the period.',
        'Count of orders whose <b>first</b> move to status <b>Ordered</b> falls in the period. “€… committed” is the sum of their current total prices.',
        'This shows procurement actively converting requests into purchase orders. Placed orders typically turn into deliveries one to several weeks later.',
        ['period', 'compare', 'filters']) ?>
    <?= $metric('kpi-events', 'Actions logged',
        'How much work was recorded in PartPulse during the period.',
        'Number of change-log entries in the period: status changes, price updates, delivery-date changes, supplier changes, notes and order creations. The supporting line shows the status moves (status changes excluding creation) and the number of different orders touched.',
        'A measure of activity, not output. A busy day with few completions usually means quoting and chasing work. Use the person filter to see one person\'s contribution.',
        ['period', 'compare', 'filters', 'person']) ?>
  </div>
</section>

<section id="flow" class="guide-section">
  <p class="eyebrow">Order flow</p>
  <h2>Flow, pipeline and speed</h2>
  <div class="metric-grid">
    <?= $metric('flow-chart', 'Incoming vs completed',
        'Bars for new, completed and cancelled orders per day, week or month, plus a line for the open backlog.',
        'New, Completed and Cancelled use exactly the KPI definitions above, grouped by bucket. The backlog line is the number of open orders at the end of each bucket.',
        'Completed bars higher than New bars push the backlog line down. Look for weeks where the line rises: something slowed down or demand spiked.',
        ['period', 'filters']) ?>
    <?= $metric('status-mix', 'Status: start vs end',
        'The pipeline at the start of the period next to the pipeline at the end, by status.',
        'For each order, its status at that moment is rebuilt from the status change log. Orders not yet submitted at that moment are left out. Delivered and Cancelled are included so the totals stay comparable.',
        'Growing “Quote Requested” or “Quote Under Approval” bars point to bottlenecks before ordering. Growing “Ordered” or “In Transit” bars point to supplier lead times.',
        ['period', 'filters']) ?>
    <?= $metric('transitions', 'Top status moves',
        'The 16 most frequent status changes in the period, for example “Quote Received → Quote Under Approval”.',
        'Count of status changes in the change log, grouped by from → to. Order creation is not included.',
        'Shows where the team spent its effort and which steps happen most. Unexpected moves (for example back to “New”) deserve a look in What changed.',
        ['period', 'filters', 'person']) ?>
    <?= $metric('aging', 'Open order age',
        'How old the open orders are at the end of the period, split by status.',
        'For every order open at the end of the period: days since submission, grouped as 0–7, 8–14, 15–30, 31–60, 61–90 and 90+ days.',
        'A healthy backlog is mostly young. Orders older than 60 days that are still in early stages (New, Quote Requested) are usually stuck or forgotten.',
        ['period', 'filters']) ?>
    <?= $metric('lead-time', 'Lead time to delivery',
        'The spread of lead times for orders delivered in the period.',
        'Each order counted as Completed is placed in a bucket by its lead time (submission → delivery): 0–3, 4–7, 8–14, 15–30, 31–60, 61–90, 90+ days. The note shows the median.',
        'A tall left side means fast service. A long right tail explains a high P90 on the lead-time card.',
        ['period', 'filters']) ?>
  </div>
</section>

<section id="work" class="guide-section">
  <p class="eyebrow">Work log</p>
  <h2>Who moved orders, and when</h2>
  <p>The work log is built from the person recorded on each PartPulse change-log entry. Names that refer to the same person are merged using the configured aliases (<?= $aliasText ?>). Entries without a name appear as “system”, for example automatic cancellations.</p>
  <table class="def-table">
    <thead><tr><th>Column</th><th>Meaning</th></tr></thead>
    <tbody>
      <tr><td>Actions</td><td>All change-log entries by this person in the period.</td></tr>
      <tr><td>Orders touched</td><td>Number of different orders the person changed.</td></tr>
      <tr><td>Created</td><td>Orders the person created (the first status entry of an order).</td></tr>
      <tr><td>Status moves</td><td>Status changes made, excluding creation.</td></tr>
      <tr><td>Ordered</td><td>Status changes the person made to “Ordered”.</td></tr>
      <tr><td>Delivered</td><td>Status changes the person made to “Delivered”.</td></tr>
      <tr><td>Price updates</td><td>Changes to unit price or total price.</td></tr>
    </tbody>
  </table>
  <p>Click a name to apply the person filter. The KPI “Actions logged”, the activity chart, heatmap, top status moves and What changed then show only that person's work. Use <b>Reset</b> to clear it.</p>
  <div class="metric-grid">
    <?= $metric('activity', 'Actions per day / week / month',
        'Logged actions over time. With a comparison selected, a dashed line shows the comparison period aligned by position (first day vs first day). The line appears when both periods have the same number of bars.',
        'Change-log entries per bucket. Same definition as “Actions logged”.',
        'Use it to see workload peaks and quiet days, and how this period compares with the last one.',
        ['period', 'compare', 'filters', 'person']) ?>
    <?= $metric('heatmap', 'When work happens',
        'A weekday × hour grid of activity in local time, plus a list of the most frequently changed fields.',
        'Each change-log entry with a full timestamp is counted in its weekday and hour (' . e($tz) . '). Entries that only store a date are left out. Darker cells have more actions, relative to the busiest cell. The field list counts entries per field (Status, Total price, Delivery date…) with their share.',
        'Shows the team\'s working rhythm, for example quotes handled in the morning and deliveries confirmed in the afternoon.',
        ['period', 'filters', 'person']) ?>
  </div>
</section>

<section id="spend" class="guide-section">
  <p class="eyebrow">Spend &amp; suppliers</p>
  <h2>Where the work and the money go</h2>
  <div class="metric-grid">
    <?= $metric('buildings', 'By building',
        'Delivered value per building (chart) and a table of activity per building.',
        '<b>New</b> = submitted in period. <b>Done</b> = delivered in period. <b>Open</b> = orders of that building that are open <b>now</b> (latest snapshot). <b>Value</b> = delivered value in period. <b>Lead</b> = median lead time of the building\'s deliveries in the period. Sorted by value.',
        'Compare spend with demand: a building with high New but low Done is waiting on procurement or suppliers.',
        ['period', 'filters']) ?>
    <?= $metric('suppliers', 'Suppliers — delivered in period',
        'The 25 suppliers with the highest delivered value in the period.',
        '<b>Orders</b> = deliveries in period. <b>Value</b> = their total price. <b>Lead</b> = median lead time from request to delivery. <b>On time</b> = share delivered on or before the “needed by” date (— when none had one). “—” as a supplier name means no supplier was set.',
        'Lead time here is the full request-to-delivery time, so it includes internal quoting and approval steps, not only the supplier\'s transit time.',
        ['period', 'filters']) ?>
    <?= $metric('categories', 'New orders by category',
        'The 15 categories with the most new orders in the period.',
        'Count of orders submitted in the period, grouped by their PartPulse category. Orders without a category appear as “Uncategorised”.',
        'Shows what kind of demand is coming in. A rising share of “Uncategorised” is a data-quality signal for requesters.',
        ['period', 'filters']) ?>
    <?= $metric('requesters', 'Requesters',
        'The 15 people who submitted the most orders in the period.',
        '<b>New in period</b> = orders they submitted in the period. <b>Still open</b> = how many of those are not Delivered or Cancelled now.',
        'Helps when talking to departments about demand and follow-ups.',
        ['period', 'filters']) ?>
  </div>
</section>

<section id="watch" class="guide-section">
  <p class="eyebrow">Action queue</p>
  <h2>SLA watch and late deliveries</h2>
  <p>These two lists are an action queue. They always reflect the <b>latest upload</b> (the “Data as of” moment) and ignore the selected period, because they describe what needs attention now. Filters still apply.</p>
  <div class="metric-grid">
    <?= $metric('sla', 'Over SLA',
        'Open orders that have stayed in their current status longer than allowed.',
        'Days in status = whole days between the order\'s last status change (or submission, if it never changed) and the export time. An order is listed when days in status is <b>greater than</b> the limit for its status. Urgent orders are listed first, then by days descending. A red number means more than three times the limit.',
        'Work the list top-down. Statuses without a limit (for example On Hold, Partially Delivered) are never listed.',
        ['snapshot', 'filters']) ?>
    <?= $metric('late', 'Late deliveries',
        'Orders that should already have arrived.',
        'Orders with status Approved, Ordered, In Transit or Partially Delivered whose expected delivery date is <b>before</b> the export date. Days late = export date − expected date. Sorted by days late.',
        'These are the orders to chase with suppliers. An expected date in the past also often means the date in PartPulse needs updating.',
        ['snapshot', 'filters']) ?>
  </div>
  <h3 id="sla-limits">Current SLA limits</h3>
  <p>Configured by the administrator (<code>sla_thresholds</code> in <code>config.php</code>):</p>
  <table class="def-table narrow"><thead><tr><th>Status</th><th class="num">Allowed in status</th></tr></thead><tbody><?= $slaRows ?></tbody></table>
  <p>Use the search box and priority selector above the list to narrow it. <b>Export CSV</b> downloads the current list.</p>
</section>

<section id="changes" class="guide-section">
  <p class="eyebrow">Audit trail</p>
  <h2>What changed</h2>
  <p>A chronological list (newest first) of every recorded change in the period, with who made it. This is the detailed record of the work done.</p>
  <table class="def-table">
    <thead><tr><th>Type chip</th><th>Meaning</th></tr></thead>
    <tbody>
      <tr><td>Created</td><td>A new order was submitted. Orders without a creation entry in the log are added from their submission time.</td></tr>
      <tr><td>Completed</td><td>Status changed to Delivered.</td></tr>
      <tr><td>Cancelled</td><td>Status changed to Cancelled, including automatic cancellations from the audit log.</td></tr>
      <tr><td>Reopened</td><td>Status changed from Delivered or Cancelled back to an open status.</td></tr>
      <tr><td>Status move</td><td>Any other status change.</td></tr>
      <tr><td>Price</td><td>Unit or total price changed.</td></tr>
      <tr><td>Delivery date</td><td>Expected delivery date changed.</td></tr>
      <tr><td>Supplier</td><td>Supplier changed.</td></tr>
      <tr><td>Other</td><td>Any other field (quote, alternative product, notes…).</td></tr>
    </tbody>
  </table>
  <ul>
    <li>Free-text values (notes, cancellation reasons, supplier notes) appear as <code>[text]</code>. Their content is removed for privacy before the data reaches this server. The fact that they changed is kept.</li>
    <li>Some PartPulse fields only store a date. Those entries show the date without a time.</li>
    <li>The table shows the first 1,500 rows. <b>Export CSV</b> contains all rows matching the chip and search. One period returns at most <?= (int)$limit ?> changes. Narrow the period if you see the “capped” note.</li>
  </ul>
</section>

<section id="snapshots" class="guide-section">
  <p class="eyebrow">Daily uploads</p>
  <h2>Snapshot diff</h2>
  <p>Compares the complete order list of two uploads, order by order. By default it compares the latest upload with the one before it, which shows exactly what changed since yesterday's upload. Choose any two uploads under Earlier / Later and press <b>Compare</b>.</p>
  <table class="def-table">
    <thead><tr><th>Result</th><th>Rule</th></tr></thead>
    <tbody>
      <tr><td>New</td><td>The order exists in the later upload but not in the earlier one.</td></tr>
      <tr><td>Completed</td><td>The status changed to Delivered (from an open status).</td></tr>
      <tr><td>Cancelled</td><td>The status changed to Cancelled.</td></tr>
      <tr><td>Reopened</td><td>The order was Delivered/Cancelled and is open again.</td></tr>
      <tr><td>Updated</td><td>Any other tracked field changed: status, priority, building, cost centre, category, description, quantity, unit/total price, supplier, requester, assignee, needed-by date, expected delivery date, approval status, PO number.</td></tr>
      <tr><td>Removed</td><td>The order was in the earlier upload but not in the later one, for example deleted in PartPulse or turned into a template. Removed orders are never counted as completed.</td></tr>
    </tbody>
  </table>
  <p>The “What changed” column lists each changed field as old → new. The admin snapshot list shows the same summary for every upload.</p>
  <p class="tip"><b>Snapshot diff vs What changed.</b> “What changed” comes from the PartPulse change log and has exact times and names. The snapshot diff compares two complete pictures, so it also catches changes PartPulse does not log (for example a corrected description), but without a time or person.</p>
</section>

<section id="drawer" class="guide-section">
  <p class="eyebrow">Drill-down</p>
  <h2>Order timeline</h2>
  <p>Click any order number anywhere on the dashboard to open its panel on the right:</p>
  <ul>
    <li><b>Facts</b>: current status, priority, submission time, requester, supplier, category, quantity, prices, needed-by and expected dates, part number and PO.</li>
    <li><b>Timeline</b>: every logged change, newest first, with time and person, ending with the submission.</li>
    <li><b>In daily snapshots</b>: the order's status, total and expected date in each upload. Shown when the order appears in more than one upload.</li>
  </ul>
  <p>Close with ✕, by clicking outside the panel, or with Esc.</p>
</section>

<section id="data" class="guide-section">
  <p class="eyebrow">Behind the numbers</p>
  <h2>Where the data comes from</h2>
  <ol class="pipeline">
    <li><b>PartPulse Orders</b> (the procurement system) stores orders and a change log of every edit.</li>
    <li>Each morning the administrator exports its database from phpMyAdmin and uploads it on the admin page. Alternatively, the PartPulse Normalizer on the administrator's PC creates a cleaned package first.</li>
    <li>The server reads the export <b>without running it</b>, keeps only reporting fields and deletes the raw file. Removed before anything is stored: passwords, usernames, e-mail addresses, phone numbers, order notes, supplier contacts, sessions, login logs, documents and invoices.</li>
    <li>The upload becomes a <b>snapshot</b>: the state of every order at the export moment. New change-log entries are added to the permanent history, and duplicates are skipped automatically.</li>
    <li>When you open the dashboard, each order's timeline (submitted → status changes → delivered/cancelled) is rebuilt from the history, and all period figures are calculated from those timelines.</li>
  </ol>
  <h3>Time</h3>
  <p>PartPulse stores times in UTC. The dashboard converts everything to <b><?= e($tz) ?></b>, including summer and winter time. A period “day” is a local calendar day.</p>
  <h3>Freshness</h3>
  <p>The dashboard is as current as the latest upload. Changes made in PartPulse after the export appear after the next upload. “Today” is therefore usually incomplete until the next morning. “Yesterday” is complete once today's export is uploaded.</p>
  <h3>Which orders count</h3>
  <p>All orders in the latest upload except PartPulse templates. Each order uses its current building, supplier, category and price for filtering and value figures.</p>
</section>

<section id="limits" class="guide-section">
  <p class="eyebrow">Good to know</p>
  <h2>Limitations and edge cases</h2>
  <ul>
    <li><b>Deleted orders</b> disappear from the latest upload, and with that from all period figures. They remain visible as “Removed” in the snapshot diff.</li>
    <li><b>Values use the current price.</b> If a price was corrected after delivery, past periods show the corrected value.</li>
    <li><b>Reopened orders.</b> An order counts as completed only if it is Delivered now. If it was delivered last week and reopened today, last week's Completed figure drops by one after today's upload.</li>
    <li><b>Orders without status history</b> (for example very old ones) are reconstructed from their submission time, current status and last update. Their exact delivery moment may be approximate.</li>
    <li><b>Lead time is request-to-delivery.</b> It includes internal steps (quotes, approvals, on hold), not only supplier transit.</li>
    <li><b>Work log credit</b> goes to the name PartPulse recorded. Actions done outside PartPulse (phone calls, e-mails) are not visible.</li>
    <li><b>SLA and late lists</b> always describe the latest upload, never a past period.</li>
  </ul>
</section>

<section id="faq" class="guide-section">
  <p class="eyebrow">FAQ</p>
  <h2>Common questions</h2>
  <details><summary>Why does “Today” show zero or very little?</summary><p>The view only contains what was in the latest export. If today's export was made early in the morning or has not been uploaded yet, today has little data. Check “Data as of” at the top.</p></details>
  <details><summary>Why do the numbers differ from the old Excel workbook?</summary><p>The workbook showed totals at one moment. This dashboard counts events within a period (submitted, delivered, cancelled) from the change log. All-time totals and SLA counts match the workbook for the same export.</p></details>
  <details><summary>Why is “x% of new” above 100%?</summary><p>The team delivered more orders in the period than came in, for example by clearing older backlog. The orders delivered need not be the orders that came in.</p></details>
  <details><summary>Why didn't someone's action appear in the work log?</summary><p>Only changes PartPulse writes to its change log are counted, under the name it recorded. If the same person appears under two names, the administrator can merge them with <code>actor_aliases</code>.</p></details>
  <details><summary>Can I see the state on a past day?</summary><p>Yes. Set From and To to that day. The status mix “end” bar and Open orders at period end show the pipeline at the end of that day. The snapshot diff and the order timeline show the uploads themselves.</p></details>
  <details><summary>Is the data safe to share?</summary><p>The view contains operational order data (articles, suppliers, prices, requester names) but no passwords, contact details or notes. Share the link only with people who may see procurement data. The administrator can require a private key in the link.</p></details>
</section>

<section id="glossary" class="guide-section">
  <p class="eyebrow">Glossary</p>
  <h2>Statuses and terms</h2>
  <table class="def-table">
    <thead><tr><th>Term</th><th>Meaning</th></tr></thead>
    <tbody>
      <tr><td>New, Pending</td><td>Request received, not yet worked on / waiting for information.</td></tr>
      <tr><td>Quote Requested → Quote Received → Quote Under Approval</td><td>Quotation steps before the purchase decision.</td></tr>
      <tr><td>Approved</td><td>Purchase approved, not yet ordered.</td></tr>
      <tr><td>Ordered, In Transit, Partially Delivered</td><td>Ordered from the supplier and on the way.</td></tr>
      <tr><td>On Hold</td><td>Paused deliberately; no SLA limit.</td></tr>
      <tr><td>Delivered</td><td>Closed, received. Counts as Completed.</td></tr>
      <tr><td>Cancelled</td><td>Closed without delivery.</td></tr>
      <tr><td>Open</td><td>Any status except Delivered and Cancelled.</td></tr>
      <tr><td>Backlog</td><td>The number of open orders at a moment.</td></tr>
      <tr><td>Snapshot</td><td>One daily upload: the state of all orders at the export moment.</td></tr>
      <tr><td>Change log</td><td>PartPulse's record of every edit (order history and audit log), merged across uploads.</td></tr>
      <tr><td>Median / P90</td><td>The middle value / the value 90% of cases stay below.</td></tr>
      <tr><td>SLA</td><td>The agreed maximum number of days an order may stay in a status.</td></tr>
    </tbody>
  </table>
</section>
