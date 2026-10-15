<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_public_access();
$appName = (string)($config['app_name'] ?? 'PartPulse Intelligence');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#102420">
<meta name="robots" content="noindex,nofollow">
<title><?= e($appName) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 36 36'%3E%3Crect width='36' height='36' rx='8' fill='%23102420'/%3E%3Cpath d='M4 20h6l3-8 5 15 4-11 3 4h7' fill='none' stroke='%2366d0b0' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Roboto+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/app.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" defer></script>
<script src="assets/app.js" defer></script>
</head>
<body class="dashboard-body">
<a class="skip-link" href="#main">Skip to content</a>
<aside class="sidebar">
  <a class="brand" href="index.php"><svg viewBox="0 0 36 36" role="img" aria-label="PartPulse logo"><path d="M4 20h6l3-8 5 15 4-11 3 4h7"/><circle cx="29" cy="20" r="3"/></svg><span>PartPulse</span></a>
  <nav aria-label="Dashboard sections">
    <a class="nav-link active" href="#overview">Overview</a>
    <a class="nav-link" href="#flow">Order flow</a>
    <a class="nav-link" href="#work">Work log</a>
    <a class="nav-link" href="#spend">Spend &amp; suppliers</a>
    <a class="nav-link" href="#watch">SLA watch</a>
    <a class="nav-link" href="#changes">What changed</a>
    <a class="nav-link" href="#snapshots">Snapshot diff</a>
    <a class="nav-link nav-help" href="help.php" data-help="">Guide &amp; definitions</a>
  </nav>
  <div class="sidebar-foot"><span class="live-dot"></span><span id="asOfFoot">Public COO view</span><a href="workbook.php">Workbook snapshots</a><a href="login.php">Admin</a></div>
</aside>

<main id="main" class="dashboard-main">
  <header class="dashboard-header">
    <div>
      <p class="eyebrow">Operations intelligence</p>
      <h1>Orders, without the noise.</h1>
      <p class="muted" id="periodLabel">Order-level history from PartPulse.</p>
    </div>
    <div class="header-controls">
      <span class="date-chip" id="asOfChip" title="Time of the PartPulse export behind this view">—</span>
      <button class="icon-button" type="button" id="shareView" aria-label="Copy link to this view" title="Copy link to this view"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></svg></button>
      <a class="button ghost help-button" href="help.php" data-help="" title="Guide: every metric explained"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.4"/><circle cx="12" cy="16.9" r=".6" fill="currentColor"/></svg><span>Guide</span></a>
      <button class="icon-button" type="button" data-theme-toggle aria-label="Switch color theme"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 1 0 16z" fill="currentColor"/></svg></button>
    </div>
  </header>

  <section class="control-bar" aria-label="Period and filters">
    <div class="period-row">
      <div class="segmented" role="group" aria-label="Period presets" id="presets">
        <button type="button" data-preset="today">Today</button>
        <button type="button" data-preset="yesterday">Yesterday</button>
        <button type="button" data-preset="this_week">This week</button>
        <button type="button" data-preset="last_week">Last week</button>
        <button type="button" data-preset="this_month">This month</button>
        <button type="button" data-preset="last_month">Last month</button>
        <button type="button" data-preset="last_7">7 days</button>
        <button type="button" data-preset="last_30">30 days</button>
        <button type="button" data-preset="last_90">90 days</button>
        <button type="button" data-preset="ytd">Year to date</button>
        <button type="button" data-preset="all">All time</button>
      </div>
      <div class="range-inputs">
        <label><span>From</span><input type="date" id="fromDate"></label>
        <label><span>To</span><input type="date" id="toDate"></label>
        <label><span>Compare with</span><select id="compareMode"><option value="prev">Previous period</option><option value="yoy">Same period last year</option><option value="custom">Custom period…</option><option value="none">No comparison</option></select></label>
        <label class="cmp-custom hidden"><span>Compare from</span><input type="date" id="cmpFrom"></label>
        <label class="cmp-custom hidden"><span>Compare to</span><input type="date" id="cmpTo"></label>
      </div>
    </div>
    <div class="filter-row">
      <select id="fBuilding" aria-label="Building"><option value="">All buildings</option></select>
      <select id="fPriority" aria-label="Priority"><option value="">All priorities</option></select>
      <select id="fSupplier" aria-label="Supplier"><option value="">All suppliers</option></select>
      <select id="fRequester" aria-label="Requester"><option value="">All requesters</option></select>
      <select id="fCategory" aria-label="Category"><option value="">All categories</option></select>
      <select id="fActor" aria-label="Person (work log)"><option value="">Everyone (work log)</option></select>
      <input id="fSearch" type="search" placeholder="Order #, article, part no…" aria-label="Search orders">
      <button type="button" class="button ghost small" id="resetFilters">Reset</button>
    </div>
  </section>

  <section id="emptyState" class="empty-state hidden">
    <h2>No order-level data yet</h2>
    <p id="emptyText">Sign in and upload today’s PartPulse SQL export. It contains the complete history since the first order.</p>
    <a class="button primary" href="login.php">Admin sign in</a>
  </section>
  <p class="alert error hidden" id="errorBox" role="alert"></p>

  <div id="dashboardContent">
    <section id="overview" class="kpi-grid kpi-grid-8" aria-label="Key performance indicators">
      <article class="kpi kpi-feature" data-kpi="incoming"><a class="info-link" href="help.php#kpi-incoming" data-help="kpi-incoming" aria-label="How this number is calculated" title="What is this? How is it calculated?">i</a><span>New orders</span><strong>—</strong><small>—</small><svg class="spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true"></svg></article>
      <article class="kpi" data-kpi="completed"><a class="info-link" href="help.php#kpi-completed" data-help="kpi-completed" aria-label="How this number is calculated" title="What is this? How is it calculated?">i</a><span>Completed (delivered)</span><strong>—</strong><small>—</small><svg class="spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true"></svg></article>
      <article class="kpi" data-kpi="net_backlog" data-inverse="1"><a class="info-link" href="help.php#kpi-net_backlog" data-help="kpi-net_backlog" aria-label="How this number is calculated" title="What is this? How is it calculated?">i</a><span>Backlog change</span><strong>—</strong><small>—</small><svg class="spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true"></svg></article>
      <article class="kpi" data-kpi="backlog_end" data-inverse="1"><a class="info-link" href="help.php#kpi-backlog_end" data-help="kpi-backlog_end" aria-label="How this number is calculated" title="What is this? How is it calculated?">i</a><span>Open orders at period end</span><strong>—</strong><small>—</small></article>
      <article class="kpi" data-kpi="lead_median" data-inverse="1"><a class="info-link" href="help.php#kpi-lead_median" data-help="kpi-lead_median" aria-label="How this number is calculated" title="What is this? How is it calculated?">i</a><span>Median lead time</span><strong>—</strong><small>—</small></article>
      <article class="kpi" data-kpi="spend"><a class="info-link" href="help.php#kpi-spend" data-help="kpi-spend" aria-label="How this number is calculated" title="What is this? How is it calculated?">i</a><span>Delivered value</span><strong>—</strong><small>—</small><svg class="spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true"></svg></article>
      <article class="kpi" data-kpi="ordered_count"><a class="info-link" href="help.php#kpi-ordered_count" data-help="kpi-ordered_count" aria-label="How this number is calculated" title="What is this? How is it calculated?">i</a><span>Orders placed</span><strong>—</strong><small>—</small></article>
      <article class="kpi" data-kpi="events"><a class="info-link" href="help.php#kpi-events" data-help="kpi-events" aria-label="How this number is calculated" title="What is this? How is it calculated?">i</a><span>Actions logged</span><strong>—</strong><small>—</small><svg class="spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true"></svg></article>
    </section>
    <p class="kpi-footnote muted" id="kpiFootnote"></p>

    <section id="flow" class="insight-grid">
      <article class="panel">
        <div class="panel-head"><div><p class="eyebrow">Order flow</p><h2 class="with-info">Incoming vs completed <a class="info-link" href="help.php#flow-chart" data-help="flow-chart" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div><span class="date-chip" id="granChip">daily</span></div>
        <div class="chart-box chart-tall"><canvas id="flowChart" role="img" aria-label="Incoming, completed and open backlog over time"></canvas></div>
        <div class="legend-row"><span><i class="sw sw-in"></i>New</span><span><i class="sw sw-done"></i>Completed</span><span><i class="sw sw-cancel"></i>Cancelled</span><span><i class="sw sw-line"></i>Open backlog</span></div>
      </article>
      <article class="panel">
        <div class="panel-head"><div><p class="eyebrow">Pipeline</p><h2 class="with-info">Status: start vs end <a class="info-link" href="help.php#status-mix" data-help="status-mix" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div class="chart-box chart-tall"><canvas id="statusChart" role="img" aria-label="Status distribution at period start and end"></canvas></div>
      </article>
    </section>

    <section class="insight-grid three">
      <article class="panel">
        <div class="panel-head"><div><p class="eyebrow">Movement</p><h2 class="with-info">Top status moves <a class="info-link" href="help.php#transitions" data-help="transitions" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div id="transitions" class="bar-list"></div>
      </article>
      <article class="panel">
        <div class="panel-head"><div><p class="eyebrow">Backlog health</p><h2 class="with-info">Open order age <a class="info-link" href="help.php#aging" data-help="aging" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div class="chart-box"><canvas id="agingChart" role="img" aria-label="Open orders by age and status"></canvas></div>
      </article>
      <article class="panel">
        <div class="panel-head"><div><p class="eyebrow">Speed</p><h2 class="with-info">Lead time to delivery <a class="info-link" href="help.php#lead-time" data-help="lead-time" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div class="chart-box"><canvas id="leadChart" role="img" aria-label="Distribution of lead time for delivered orders"></canvas></div>
        <p class="panel-note" id="leadNote"></p>
      </article>
    </section>

    <section id="work" class="panel table-panel">
      <div class="panel-head"><div><p class="eyebrow">Work log · selected period</p><h2 class="with-info">Who moved orders <a class="info-link" href="help.php#work" data-help="work" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div><span class="muted small" id="workNote"></span></div>
      <div class="table-wrap"><table><thead><tr><th>Person</th><th class="num">Actions</th><th class="num">Orders touched</th><th class="num">Created</th><th class="num">Status moves</th><th class="num">Ordered</th><th class="num">Delivered</th><th class="num">Price updates</th></tr></thead><tbody id="actorRows"></tbody></table></div>
      <p class="panel-note">Click a name to filter the work log, activity chart and change list to that person.</p>
    </section>
    <section class="insight-grid wide-left">
      <article class="panel">
        <div class="panel-head"><div><p class="eyebrow">Activity</p><h2 class="with-info">Actions per <span id="activityGran">day</span> <a class="info-link" href="help.php#activity" data-help="activity" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div class="chart-box chart-tall"><canvas id="activityChart" role="img" aria-label="Logged actions over time"></canvas></div>
      </article>
      <article class="panel">
        <div class="panel-head"><div><p class="eyebrow">Rhythm</p><h2 class="with-info">When work happens <a class="info-link" href="help.php#heatmap" data-help="heatmap" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div id="heatmap" class="heatmap" role="img" aria-label="Activity by weekday and hour"></div>
        <div class="heat-legend"><span>Less</span><i></i><i></i><i></i><i></i><i></i><span>More</span></div>
        <div id="fieldList" class="micro-list"></div>
      </article>
    </section>

    <section id="spend" class="insight-grid even">
      <article class="panel">
        <div class="panel-head"><div><p class="eyebrow">Allocation</p><h2 class="with-info">By building <a class="info-link" href="help.php#buildings" data-help="buildings" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div class="chart-box"><canvas id="buildingChart" role="img" aria-label="Delivered value by building"></canvas></div>
        <div class="table-wrap compact dense"><table><thead><tr><th>Building</th><th class="num">New</th><th class="num">Done</th><th class="num">Open</th><th class="num">Value</th><th class="num">Lead</th></tr></thead><tbody id="buildingRows"></tbody></table></div>
      </article>
      <article class="panel table-panel flush">
        <div class="panel-head"><div><p class="eyebrow">Suppliers</p><h2 class="with-info">Delivered in period <a class="info-link" href="help.php#suppliers" data-help="suppliers" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div class="table-wrap tall dense"><table><thead><tr><th>Supplier</th><th class="num">Orders</th><th class="num">Value</th><th class="num">Lead</th><th class="num" title="Delivered by the date the requester needed">On time</th></tr></thead><tbody id="supplierRows"></tbody></table></div>
      </article>
    </section>
    <section class="insight-grid">
      <article class="panel">
        <div class="panel-head"><div><p class="eyebrow">Demand</p><h2 class="with-info">New orders by category <a class="info-link" href="help.php#categories" data-help="categories" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div class="chart-box chart-tall"><canvas id="categoryChart" role="img" aria-label="New orders by category"></canvas></div>
      </article>
      <article class="panel table-panel flush">
        <div class="panel-head"><div><p class="eyebrow">Demand</p><h2 class="with-info">Requesters <a class="info-link" href="help.php#requesters" data-help="requesters" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div></div>
        <div class="table-wrap tall"><table><thead><tr><th>Requester</th><th class="num">New in period</th><th class="num">Still open</th></tr></thead><tbody id="requesterRows"></tbody></table></div>
      </article>
    </section>

    <section id="watch" class="panel table-panel">
      <div class="panel-head table-heading">
        <div><p class="eyebrow">Action queue · current snapshot</p><h2 class="with-info">SLA watch <a class="info-link" href="help.php#watch" data-help="watch" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div>
        <div class="tabs" role="tablist"><button role="tab" type="button" class="active" data-watch="sla">Over SLA <b id="slaCount">0</b></button><button role="tab" type="button" data-watch="overdue">Late deliveries <b id="overdueCount">0</b></button></div>
        <div class="filters"><input id="watchSearch" type="search" placeholder="Search…" aria-label="Search watchlist"><select id="watchPriority" aria-label="Priority"><option value="">All priorities</option><option>Urgent</option><option>High</option><option>Normal</option><option>Low</option></select></div>
      </div>
      <div class="table-wrap tall"><table><thead id="watchHead"></thead><tbody id="watchRows"></tbody></table></div>
      <div class="table-footer"><span id="watchCountLabel">0 records</span><button class="button secondary" id="watchCsv" type="button">Export CSV</button></div>
    </section>

    <section id="changes" class="panel table-panel">
      <div class="panel-head table-heading">
        <div><p class="eyebrow">Audit trail · selected period</p><h2 class="with-info">What changed <a class="info-link" href="help.php#changes" data-help="changes" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2></div>
        <div class="filters"><input id="changeSearch" type="search" placeholder="Order, article, person…" aria-label="Search changes"></div>
      </div>
      <div class="chip-row" id="changeTypes" role="group" aria-label="Change types"></div>
      <div class="table-wrap tall"><table><thead><tr><th>When</th><th>Order</th><th>Article</th><th>Change</th><th>From</th><th>To</th><th>By</th><th>Bldg</th></tr></thead><tbody id="changeRows"></tbody></table></div>
      <div class="table-footer"><span id="changeCount">0 changes</span><button class="button secondary" id="changeCsv" type="button">Export CSV</button></div>
    </section>

    <section id="snapshots" class="panel table-panel">
      <div class="panel-head table-heading">
        <div><p class="eyebrow">Daily uploads</p><h2 class="with-info">Snapshot diff <a class="info-link" href="help.php#snapshots" data-help="snapshots" aria-label="How this panel is calculated" title="What is this? How is it calculated?">i</a></h2><p class="muted small">Compares the complete order lists of two uploads, order by order.</p></div>
        <div class="filters"><label class="snapshot-label">Earlier<select id="snapA"></select></label><label class="snapshot-label">Later<select id="snapB"></select></label><button class="button primary" id="runCompare" type="button">Compare</button></div>
      </div>
      <div class="diff-cards" id="diffCards"></div>
      <div class="chip-row" id="diffTypes"></div>
      <div class="table-wrap tall"><table><thead><tr><th>Order</th><th>Result</th><th>Article</th><th>Status</th><th>What changed</th><th>Bldg</th><th class="num">Value</th></tr></thead><tbody id="diffRows"></tbody></table></div>
      <div class="table-footer"><span id="diffCount">Upload at least two snapshots to compare.</span><button class="button secondary" id="diffCsv" type="button">Export CSV</button></div>
    </section>
  </div>
</main>

<div class="drawer-backdrop hidden" id="drawerBackdrop"></div>
<aside class="drawer" id="orderDrawer" aria-hidden="true" aria-labelledby="drawerTitle" role="dialog">
  <div class="drawer-head"><div><p class="eyebrow" id="drawerEyebrow">Order</p><h2 id="drawerTitle">—</h2></div><button class="icon-button" type="button" id="drawerClose" aria-label="Close">✕</button></div>
  <div id="drawerBody" class="drawer-body"></div>
</aside>
<div class="toast hidden" id="toast" role="status"></div>
<div class="loading-layer" id="loadingLayer" aria-live="polite"><div class="loading-mark"></div><span>Reading the operational pulse…</span></div>
</body>
</html>
