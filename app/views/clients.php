<?php
/** @var array $clients @var ?array $status @var array $s @var string $csrf */
$now = (int)($status['time'] ?? time());
$limit = State::staleSeconds($s);
$seenMap = $status ? Liveness::update($status) : Liveness::read();
$rows = [];
$counts = ['all' => 0, 'on' => 0, 'stale' => 0, 'never' => 0, 'off' => 0];
foreach ($clients as $id => $c) {
    $p = $status['peers'][$c['publicKey']] ?? null;
    $hs = Liveness::lastSeen($c, $status, $seenMap);
    $st = Liveness::state($c, $status, $seenMap, $limit, $now);
    $counts['all']++;
    $counts[$st]++;
    $rows[] = [$c, $p, $st, $hs];
}
$filters = ['all' => 'All', 'on' => 'Online', 'stale' => 'Stale', 'never' => 'Never connected', 'off' => 'Disabled'];
?>
<section class="tiles" aria-label="Summary">
  <div class="tile"><div class="tlabel">Clients</div><div class="tval" data-count="all"><?= $counts['all'] ?></div></div>
  <div class="tile"><div class="tlabel">Online</div><div class="tval c-on" data-count="on"><?= $counts['on'] ?></div></div>
  <div class="tile"><div class="tlabel">Stale (silent &gt;<?= $limit ?> s)</div><div class="tval c-stale" data-count="stale"><?= $counts['stale'] ?></div></div>
  <div class="tile"><div class="tlabel">Never connected</div><div class="tval c-never" data-count="never"><?= $counts['never'] ?></div></div>
</section>

<div class="toolbar">
  <h1>Clients</h1>
  <label class="search">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
    <span class="sr">Search clients</span>
    <input type="search" id="search" placeholder="Search name, IP, subnet, endpoint, group">
  </label>
  <a class="btn primary" href="?r=client&amp;new=1">+ New client</a>
</div>

<div class="chips" role="group" aria-label="Filter">
<?php foreach ($filters as $k => $label): ?>
  <button type="button" class="chip<?= $k === 'all' ? ' on' : '' ?>" data-filter="<?= $k ?>" aria-pressed="<?= $k === 'all' ? 'true' : 'false' ?>"><?= h($label) ?> <span class="muted" data-count="<?= $k ?>"><?= $counts[$k] ?></span></button>
<?php endforeach; ?>
</div>

<div class="card table-card">
  <div class="table-scroll">
  <table class="clients">
    <thead>
      <tr><th scope="col">Client</th><th scope="col">Routes on hub</th><th scope="col">Endpoint</th><th scope="col">Last seen</th><th scope="col">Rx / Tx</th><th scope="col">On</th><th scope="col" class="right">Actions</th></tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="7" class="empty">No clients yet. <a href="?r=client&amp;new=1">Create the first one</a>.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as [$c, $p, $st, $hs]): ?>
      <tr data-id="<?= h($c['id']) ?>" data-state="<?= $st ?>" data-search="<?= h(strtolower($c['name'] . ' ' . $c['address'] . ' ' . implode(' ', $c['serverRoutes']) . ' ' . $c['group'] . ' ' . ($p['endpoint'] ?? ''))) ?>">
        <td>
          <div class="who">
            <span class="dot st-<?= $st ?>" aria-hidden="true"></span>
            <div>
              <a class="name" href="?r=client&amp;id=<?= h(urlencode($c['id'])) ?>"><?= h($c['name']) ?></a>
              <div class="mono sub"><?= h($c['address']) ?><?= $c['group'] !== '' ? ' · ' . h($c['group']) : '' ?><?= State::isExpired($c) ? ' · <span class="c-stale">expired</span>' : '' ?></div>
            </div>
          </div>
        </td>
        <td class="mono small <?= $c['serverRoutes'] ? 'c-accent' : 'muted' ?>"><?= $c['serverRoutes'] ? h(implode(', ', $c['serverRoutes'])) : '—' ?></td>
        <td class="mono small muted ellipsis" data-f="endpoint"><?= h($p['endpoint'] ?? '') ?: '—' ?></td>
        <td class="small" data-f="seen" title="Last packet received (keepalive, data or handshake)"><?= h(Util::ago($hs, $now)) ?></td>
        <td class="mono small muted"><span data-f="rx">↓ <?= Util::bytes((int)($p['rx'] ?? 0)) ?></span><br><span data-f="tx">↑ <?= Util::bytes((int)($p['tx'] ?? 0)) ?></span></td>
        <td>
          <button type="button" class="toggle" role="switch" aria-checked="<?= $c['enabled'] ? 'true' : 'false' ?>" aria-label="Enable <?= h($c['name']) ?>" data-toggle="<?= h($c['id']) ?>"><span></span></button>
        </td>
        <td class="right">
          <div class="actions">
            <?php if ($c['privateKey'] !== null): ?>
            <button type="button" class="icon" data-qr="<?= h($c['id']) ?>" data-name="<?= h($c['name']) ?>" aria-label="QR code for <?= h($c['name']) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3M21 21h-4M21 14v3"/></svg></button>
            <?php endif; ?>
            <a class="icon" href="?r=client-conf&amp;id=<?= h(urlencode($c['id'])) ?>" aria-label="Download config for <?= h($c['name']) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg></a>
            <a class="icon" href="?r=client&amp;id=<?= h(urlencode($c['id'])) ?>" aria-label="Edit <?= h($c['name']) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/></svg></a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<p class="muted small">Live stats refresh every 5 s. <span id="lastUpdate"></span></p>

<dialog id="qrDialog" class="dialog">
  <h2 id="qrTitle">QR code</h2>
  <img id="qrImg" alt="" width="300" height="300">
  <p class="muted small">Scan with the WireGuard mobile app (“+” → “Scan from QR code”).</p>
  <form method="dialog"><button class="btn" type="submit">Close</button></form>
</dialog>
