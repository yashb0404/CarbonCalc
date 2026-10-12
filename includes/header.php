<?php
$b = base_url(); $f = flash();
$page = basename($_SERVER['SCRIPT_NAME']);
$loggedIn = !empty($_SESSION['user_id']);
function nav_link(string $href, string $label, string $icon, string $page, string $b): string {
    $on = basename($href) === $page ? ' on' : '';
    return '<a class="nl' . $on . '" href="' . $b . $href . '"' . ($on ? ' aria-current="page"' : '') . '>' . icon($icon) . $label . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'CarbonCalc') ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2315202B'/%3E%3Crect x='6' y='13' width='9' height='6' rx='1.5' fill='%231E4FD8'/%3E%3Crect x='16' y='13' width='5' height='6' rx='1.5' fill='%23E0A100'/%3E%3Crect x='22' y='13' width='4' height='6' rx='1.5' fill='%232F9E6E'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=IBM+Plex+Mono:wght@400;500;600&family=Public+Sans:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= $b ?>assets/style.css">
</head>
<body>
<?php if ($loggedIn): ?>
<div class="shell">
<nav class="side" aria-label="Main">
  <a class="brand" href="<?= $b ?>dashboard.php">
    <svg class="mark" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#15202B"/><rect x="6" y="13" width="9" height="6" rx="1.5" fill="#1E4FD8"/><rect x="16" y="13" width="5" height="6" rx="1.5" fill="#E0A100"/><rect x="22" y="13" width="4" height="6" rx="1.5" fill="#2F9E6E"/></svg>
    <span><b>CarbonCalc</b><small>Personal CO₂ ledger</small></span>
  </a>
  <a class="btn btn-primary cta-add" href="<?= $b ?>add_activity.php"><?= icon('plus') ?>Log activity</a>
  <?= nav_link('dashboard.php', 'Overview', 'dashboard', $page, $b) ?>
  <?= nav_link('history.php', 'History', 'history', $page, $b) ?>
  <?= nav_link('reports.php', 'Reports', 'report', $page, $b) ?>
  <?= nav_link('methodology.php', 'How it works', 'info', $page, $b) ?>
  <div class="nav-group">Data</div>
  <?= nav_link('mongo_log.php', 'Event log', 'db', $page, $b) ?>
  <?php if ($_SESSION['role'] === 'admin'): ?><?= nav_link('admin/factors.php', 'Emission factors', 'sliders', $page, $b) ?><?php endif; ?>
  <div class="side-foot">
    <span class="avatar" aria-hidden="true"><?= e(initials($_SESSION['name'])) ?></span>
    <span class="who"><b><?= e($_SESSION['name']) ?></b><a href="<?= $b ?>logout.php">Log out</a></span>
  </div>
</nav>
<main class="main" id="main">
<?php elseif (!empty($public)): ?>
<header class="public-top">
  <a class="brand" href="<?= $b ?>index.php">
    <svg class="mark" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#15202B"/><rect x="6" y="13" width="9" height="6" rx="1.5" fill="#1E4FD8"/><rect x="16" y="13" width="5" height="6" rx="1.5" fill="#E0A100"/><rect x="22" y="13" width="4" height="6" rx="1.5" fill="#2F9E6E"/></svg>
    <span><b>CarbonCalc</b><small>Personal CO₂ ledger</small></span>
  </a>
  <a class="btn btn-primary btn-sm" href="<?= $b ?>index.php">Log in</a>
</header>
<main class="main public" id="main">
<?php else: ?>
<main id="main">
<?php endif; ?>
<?php if ($f && $loggedIn): ?>
  <div class="flash <?= e($f[1]) ?>" role="status"><?= icon($f[1] === 'err' ? 'alert' : 'check') ?><span><?= e($f[0]) ?></span>
  <?php if (!empty($f[2])): ?><a href="<?= e($b . $f[2][1]) ?>"><?= e($f[2][0]) ?> →</a><?php endif; ?></div>
<?php endif; ?>
