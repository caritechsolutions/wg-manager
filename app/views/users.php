<?php /** @var array $users @var string $csrf @var ?string $user */ ?>
<div class="toolbar"><a href="?r=settings" class="small">← Settings</a><h1 class="grow">Admin users</h1></div>

<section class="card table-card">
  <div class="table-scroll">
  <table>
    <thead><tr><th scope="col">User</th><th scope="col">2FA</th><th scope="col">Last sign-in</th><th scope="col">Created</th><th scope="col" class="right">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $name => $u): ?>
      <tr>
        <td><b><?= h($name) ?></b><?= $name === $user ? ' <span class="muted small">(you)</span>' : '' ?></td>
        <td><?= $u['totp'] ? '<span class="c-on">on</span>' : '<span class="muted">off</span>' ?></td>
        <td class="small"><?= h($u['lastLogin'] ? date('Y-m-d H:i', (int)strtotime($u['lastLogin'])) : 'never') ?></td>
        <td class="small"><?= h(substr($u['createdAt'], 0, 10)) ?></td>
        <td class="right">
          <div class="actions">
          <?php if ($name !== $user): ?>
            <?php if ($u['totp']): ?>
            <form method="post" action="?r=users" data-confirm="Remove 2FA for <?= h($name) ?>?"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reset-2fa"><input type="hidden" name="username" value="<?= h($name) ?>"><button class="btn small" type="submit">Reset 2FA</button></form>
            <?php endif; ?>
            <form method="post" action="?r=users" data-confirm="Delete user <?= h($name) ?>?"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="username" value="<?= h($name) ?>"><button class="btn small danger" type="submit">Delete</button></form>
          <?php else: ?>
            <a class="btn small" href="?r=account">Manage</a>
          <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<div class="cards">
  <form class="card" method="post" action="?r=users">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="add">
    <h2>Add a user</h2>
    <label>Username<input class="inp" name="username" required pattern="[A-Za-z0-9._@\-]{2,64}" autocomplete="off"></label>
    <label>Password<input class="inp" type="password" name="password" minlength="10" required autocomplete="new-password"><span class="help">At least 10 characters. They can turn on 2FA under Account.</span></label>
    <div class="btnrow"><button class="btn primary" type="submit">Add user</button></div>
  </form>
  <form class="card" method="post" action="?r=users">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="password">
    <h2>Set another user's password</h2>
    <label>User<select class="inp" name="username"><?php foreach (array_keys($users) as $n): if ($n === $user) { continue; } ?><option><?= h($n) ?></option><?php endforeach; ?></select></label>
    <label>New password<input class="inp" type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <div class="btnrow"><button class="btn" type="submit" <?= count($users) < 2 ? 'disabled' : '' ?>>Set password</button></div>
  </form>
</div>
