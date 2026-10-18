<?php /** @var string $title @var string $view @var array $flash @var string $csrf */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> · WG Manager</title>
<link rel="stylesheet" href="assets/app.css?v=<?= WGM_VERSION ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
</head>
<body class="bare">
<main class="auth">
  <div class="brand">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/><path d="M9 12l2 2 4-4"/></svg>
    <span>WG Manager</span>
  </div>
  <h1><?= h($title) ?></h1>
  <?php foreach ($flash as $f): ?>
    <div class="flash flash-<?= h($f['type']) ?>" role="alert"><?= h($f['msg']) ?></div>
  <?php endforeach; ?>
  <?php if ($view === 'login'): ?>
    <form method="post" action="?r=login" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <label>Username<input class="inp" name="username" autocomplete="username" required autofocus></label>
      <label>Password<input class="inp" type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn primary" type="submit">Sign in</button>
    </form>
  <?php else: ?>
    <form method="post" action="?r=2fa" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <label>6-digit code from your authenticator app<input class="inp mono" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" required autofocus></label>
      <button class="btn primary" type="submit">Verify</button>
      <a href="?r=login" class="muted small">Start over</a>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
