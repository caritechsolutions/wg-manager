<?php /** @var array $u @var ?string $pending @var ?string $qr @var string $csrf @var ?string $user */ ?>
<div class="toolbar"><a href="?r=settings" class="small">← Settings</a><h1 class="grow">Account · <?= h($user) ?></h1></div>

<div class="cards">
  <form class="card" method="post" action="?r=account">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="password">
    <h2>Change password</h2>
    <label>Current password<input class="inp" type="password" name="current" required autocomplete="current-password"></label>
    <label>New password<input class="inp" type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>Repeat new password<input class="inp" type="password" name="confirm" minlength="10" required autocomplete="new-password"></label>
    <div class="btnrow"><button class="btn primary" type="submit">Change password</button></div>
  </form>

  <section class="card">
    <h2>Two-factor authentication</h2>
    <?php if ($u['totp']): ?>
      <p><span class="c-on">●</span> On — you'll be asked for a code from your authenticator app at sign-in.</p>
      <form method="post" action="?r=account" class="stack">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="2fa-disable">
        <label>Current password to turn it off<input class="inp" type="password" name="current" required autocomplete="current-password"></label>
        <div class="btnrow"><button class="btn danger" type="submit">Turn off 2FA</button></div>
      </form>
    <?php elseif ($pending): ?>
      <p>Scan this with Google Authenticator, Authy, 1Password or similar, then enter the 6-digit code.</p>
      <?php if ($qr): ?><div class="qrbox"><?= $qr ?></div><?php endif; ?>
      <p class="small">Or enter the key manually: <span class="mono break"><?= h(trim(chunk_split($pending, 4, ' '))) ?></span></p>
      <form method="post" action="?r=account" class="stack">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="2fa-confirm">
        <label>Code<input class="inp mono" name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
        <div class="btnrow"><button class="btn primary" type="submit">Confirm &amp; turn on</button>
          <button class="btn" type="submit" name="action" value="2fa-cancel" formnovalidate>Cancel</button></div>
      </form>
    <?php else: ?>
      <p class="muted">Off. Adds a one-time code from your phone to every sign-in.</p>
      <form method="post" action="?r=account"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="2fa-start">
        <div class="btnrow"><button class="btn primary" type="submit">Set up 2FA</button></div></form>
    <?php endif; ?>
  </section>
</div>
