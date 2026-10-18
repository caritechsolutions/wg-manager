<?php /** @var array $log @var string $q @var ?array $health @var ?string $healthError @var array $backups @var string $csrf */ ?>
<div class="toolbar"><h1 class="grow">Activity</h1>
  <form method="post" action="?r=apply" class="inline"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="back" value="activity"><button class="btn" type="submit">Re-apply configuration</button></form>
</div>

<div class="split">
<section class="card maincol">
  <div class="row-between">
    <h2>Audit log</h2>
    <div class="btnrow">
      <form method="get" class="inline search-sm"><input type="hidden" name="r" value="activity"><label class="sr" for="q">Filter log</label><input class="inp" id="q" name="q" value="<?= h($q) ?>" placeholder="Filter…"></form>
      <a class="btn small" href="?r=audit-csv">Export CSV</a>
    </div>
  </div>
  <?php if (!$log): ?><p class="muted">Nothing logged<?= $q !== '' ? ' for that filter' : ' yet' ?>.</p><?php endif; ?>
  <ul class="log">
  <?php foreach ($log as $r): $bad = preg_match('/fail|locked|stale/', $r['action']); ?>
    <li>
      <div>
        <div><span class="tag-act<?= $bad ? ' bad' : '' ?>"><?= h($r['action']) ?></span> <?= h($r['detail']) ?></div>
        <div class="muted small"><?= h($r['user']) ?><?= $r['ip'] !== '' ? ' · ' . h($r['ip']) : '' ?></div>
      </div>
      <time class="mono small muted" datetime="<?= h($r['ts']) ?>"><?= h(date('d M H:i', (int)strtotime($r['ts']))) ?></time>
    </li>
  <?php endforeach; ?>
  </ul>
</section>

<aside class="sidecol">
  <section class="card">
    <h2>Service health</h2>
    <?php if ($healthError): ?><p class="c-bad small"><?= h($healthError) ?></p><?php endif; ?>
    <ul class="health">
    <?php foreach ($health ?? [] as $c): ?>
      <li><span class="mono small"><?= h($c['name']) ?></span><span class="<?= $c['ok'] ? 'c-on' : 'c-bad' ?> small">● <?= h($c['detail']) ?></span></li>
    <?php endforeach; ?>
    </ul>
  </section>

  <section class="card" id="backups">
    <div class="row-between"><h2>Backups</h2>
      <form method="post" action="?r=backup"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="now"><button class="btn small" type="submit">Back up now</button></form>
    </div>
    <ul class="backups">
    <?php foreach (array_slice($backups, 0, 30) as $b): ?>
      <li>
        <div><span class="mono small"><?= h(date('Y-m-d H:i', $b['time'])) ?></span> <span class="muted small"><?= h($b['reason']) ?></span></div>
        <div class="actions">
          <a class="btn small" href="?r=backup-download&amp;name=<?= h(urlencode($b['name'])) ?>">Download</a>
          <form method="post" action="?r=backup" data-confirm="Restore the backup from <?= h(date('Y-m-d H:i', $b['time'])) ?>? Current clients and settings are replaced (the current state is backed up first).">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="name" value="<?= h($b['name']) ?>">
            <button class="btn small" type="submit">Restore</button></form>
        </div>
      </li>
    <?php endforeach; ?>
    <?php if (!$backups): ?><li class="muted small">No backups yet.</li><?php endif; ?>
    </ul>
    <form method="post" action="?r=backup" enctype="multipart/form-data" class="stack upload">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="upload">
      <h3>Import / restore from file</h3>
      <label>wg-manager backup or wg-easy <span class="mono">wg0.json</span><input type="file" name="file" accept=".json,application/json" required></label>
      <label class="check"><input type="checkbox" name="confirmReplace" value="1"> I understand a wg-easy file replaces the server key and all clients</label>
      <button class="btn" type="submit">Upload</button>
    </form>
  </section>
</aside>
</div>
