<?php
/** @var string $title @var string $view @var string $nav @var array $s @var ?array $status @var ?string $statusError @var array $flash @var string $csrf @var ?string $user */
$net = Ip::ifaceV4($s['address']);
$sync = $status === null ? ['err', 'Helper unavailable'] : (!$status['up'] ? ['err', $s['interface'] . ' is down'] : ($status['inSync'] ? ['ok', 'Config in sync'] : ['warn', 'Not applied']));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?= h($csrf) ?>">
<title><?= h($title) ?> · WG Manager</title>
<link rel="stylesheet" href="assets/app.css?v=<?= WGM_VERSION ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<script src="assets/app.js?v=<?= WGM_VERSION ?>" defer></script>
</head>
<body data-page="<?= h($view) ?>">
<header class="top">
  <a class="brand" href="?r=clients">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/><path d="M9 12l2 2 4-4"/></svg>
    <span>WG Manager</span>
  </a>
  <span class="pill mono"><?= h($s['interface']) ?> · udp/<?= (int)$s['listenPort'] ?> · <?= h($net['cidr'] ?? $s['address']) ?></span>
  <nav class="mainnav" aria-label="Main">
    <a href="?r=clients" <?= $nav === 'clients' ? 'aria-current="page"' : '' ?>>Clients</a>
    <a href="?r=settings" <?= $nav === 'settings' ? 'aria-current="page"' : '' ?>>Settings</a>
    <a href="?r=activity" <?= $nav === 'activity' ? 'aria-current="page"' : '' ?>>Activity</a>
  </nav>
  <div class="topright">
    <span class="sync sync-<?= $sync[0] ?>" id="syncBadge" title="<?= h($statusError ?? '') ?>"><span class="dot"></span><?= h($sync[1]) ?></span>
    <?php if ($status !== null && !$status['inSync']): ?>
      <form method="post" action="?r=apply" class="inline"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><button class="btn small" type="submit">Apply now</button></form>
    <?php endif; ?>
    <details class="usermenu">
      <summary class="btn small"><?= h($user) ?> ▾</summary>
      <div class="menu">
        <a href="?r=account">Account &amp; 2FA</a>
        <a href="?r=users">Admin users</a>
        <form method="post" action="?r=logout"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><button type="submit">Sign out</button></form>
      </div>
    </details>
  </div>
</header>
<main class="wrap">
<?php foreach ($flash as $f): ?>
  <div class="flash flash-<?= h($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= nl2br(h($f['msg'])) ?></div>
<?php endforeach; ?>
<?php if ($statusError !== null): ?>
  <div class="flash flash-error" role="alert">Cannot reach the root helper: <?= h($statusError) ?></div>
<?php endif; ?>
<?php require __DIR__ . '/' . $view . '.php'; ?>
</main>
<footer class="foot">wg-manager <?= WGM_VERSION ?></footer>
</body>
</html>
