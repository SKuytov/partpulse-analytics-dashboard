<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_public_access();
$appName = (string)($config['app_name'] ?? 'PartPulse Intelligence');
$sla = (array)($config['sla_thresholds'] ?? ['New' => 2, 'Pending' => 3, 'Quote Requested' => 5, 'Quote Received' => 3, 'Quote Under Approval' => 2, 'Approved' => 3, 'Ordered' => 14, 'In Transit' => 7]);
$tz = (string)($config['timezone'] ?? 'Europe/Sofia');
$limit = (int)($config['change_log_limit'] ?? 3000);
$aliases = (array)($config['actor_aliases'] ?? []);
// Return to the exact dashboard view the visitor came from (period + filters).
$back = (string)($_GET['back'] ?? '');
$backUrl = 'index.php' . (preg_match('/^\?[A-Za-z0-9_\-=&%.|+:,~]{0,1500}$/', $back) ? $back : '');
$toc = [
    'start' => 'Start here',
    'controls' => 'Periods, comparisons & filters',
    'kpis' => 'Headline cards',
    'flow' => 'Order flow & speed',
    'work' => 'Work log',
    'spend' => 'Spend & suppliers',
    'watch' => 'SLA watch & late deliveries',
    'changes' => 'What changed',
    'snapshots' => 'Snapshot diff',
    'drawer' => 'Order timeline',
    'data' => 'Where the data comes from',
    'limits' => 'Limitations',
    'faq' => 'FAQ',
    'glossary' => 'Glossary',
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Guide &amp; definitions · <?= e($appName) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 36 36'%3E%3Crect width='36' height='36' rx='8' fill='%23102420'/%3E%3Cpath d='M4 20h6l3-8 5 15 4-11 3 4h7' fill='none' stroke='%2366d0b0' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Roboto+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/help.css">
<script>try{var t=localStorage.getItem('pp-theme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=t;}catch(e){}</script>
<script src="assets/help.js" defer></script>
</head>
<body class="guide-body">
<a class="skip-link" href="#guide">Skip to content</a>
<header class="topbar guide-top">
  <a class="brand brand-dark" href="<?= e($backUrl) ?>"><svg viewBox="0 0 36 36" aria-hidden="true"><path d="M4 20h6l3-8 5 15 4-11 3 4h7"/><circle cx="29" cy="20" r="3"/></svg><span>PartPulse</span></a>
  <nav class="top-actions" aria-label="Guide actions">
    <button class="button ghost" type="button" id="printGuide">Print / PDF</button>
    <button class="icon-button" type="button" data-theme-toggle aria-label="Switch color theme"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 1 0 16z" fill="currentColor"/></svg></button>
    <a class="button primary" href="<?= e($backUrl) ?>" id="backLink">← Back to dashboard</a>
  </nav>
</header>
<div class="guide-shell">
  <aside class="guide-toc" aria-label="Guide contents">
    <label class="toc-search"><span class="sr-only">Search the guide</span><input type="search" id="guideSearch" placeholder="Search metrics, e.g. lead time" autocomplete="off"></label>
    <nav><ol>
      <?php foreach ($toc as $id => $label): ?><li><a href="#<?= e($id) ?>" data-toc="<?= e($id) ?>"><?= e($label) ?></a></li><?php endforeach; ?>
    </ol></nav>
    <p class="toc-note">Definitions match the calculations in this installation. SLA limits and time zone come from its configuration.</p>
  </aside>
  <main id="guide" class="guide-main">
    <header class="guide-hero">
      <p class="eyebrow">COO guide</p>
      <h1>How to read the PartPulse dashboard</h1>
      <p class="lead">Every card, chart and table explained: what it shows, how it is calculated, and how to use it in daily and weekly reviews.</p>
    </header>
    <p class="search-empty hidden" id="searchEmpty">Nothing in the guide matches that search.</p>
    <?php require __DIR__ . '/app/help_content.php'; ?>
    <footer class="guide-foot"><a class="button secondary" href="<?= e($backUrl) ?>">← Back to dashboard</a><a class="text-link" href="#start">Back to top ↑</a></footer>
  </main>
</div>
</body>
</html>
