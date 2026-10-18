<?php
/** @var array $c @var bool $new @var array $state @var array $s @var ?array $status @var string $csrf @var array $alertState */
$p = (!$new && $status) ? ($status['peers'][$c['publicKey']] ?? null) : null;
$now = (int)($status['time'] ?? time());
$hs = (int)($p['handshake'] ?? 0);
$action = $new ? '?r=client' : '?r=client&id=' . urlencode($c['id']);
$routesText = implode("\n", (array)$c['serverRoutes']);
$others = [];
foreach ($state['clients'] as $o) {
    if (!$new && $o['id'] === $c['id']) {
        continue;
    }
    if (!empty($o['advertise']) && $o['serverRoutes']) {
        $others[] = $o['name'] . ' (' . implode(', ', $o['serverRoutes']) . ')';
    }
}
?>
<div class="toolbar">
  <a href="?r=clients" class="small">← Clients</a>
  <h1 class="grow">
    <?php if (!$new): ?><span class="dot st-<?= !State::isActive($c) ? 'off' : ($hs && $now - $hs < 180 ? 'on' : ($hs ? 'stale' : 'never')) ?>" aria-hidden="true"></span><?php endif; ?>
    <?= $new ? 'New client' : h($c['name']) ?>
    <?php if (!$new): ?><span class="mono muted h-sub"><?= h($c['address']) ?> · <?= $p ? 'handshake ' . h(Util::ago($hs, $now)) : 'not connected' ?></span><?php endif; ?>
  </h1>
  <button class="btn primary" type="submit" form="clientForm"><?= $new ? 'Create' : 'Save &amp; apply' ?></button>
</div>

<div class="split">
<form id="clientForm" method="post" action="<?= h($action) ?>" class="maincol">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

  <section class="card">
    <h2>General</h2>
    <div class="grid2">
      <label>Name<input class="inp" name="name" value="<?= h($c['name']) ?>" maxlength="64" required></label>
      <label>Tunnel address<input class="inp mono" name="address" value="<?= h($c['address']) ?>" placeholder="next free"><span class="help">Inside <?= h(Ip::ifaceV4($s['address'])['cidr']) ?></span></label>
      <label>Group<input class="inp" name="group" value="<?= h($c['group']) ?>" maxlength="64" placeholder="e.g. Hotels, Site routers"></label>
      <label>Expires<input class="inp" type="date" name="expiresAt" value="<?= h($c['expiresAt'] ?? '') ?>"><span class="help">Empty = never. Expired clients are removed from the hub automatically.</span></label>
    </div>
    <label>Notes<input class="inp" name="notes" value="<?= h($c['notes']) ?>" maxlength="500"></label>
    <div class="checks">
      <label class="check"><input type="checkbox" name="enabled" value="1" <?= $c['enabled'] ? 'checked' : '' ?>> Enabled</label>
      <label class="check"><input type="checkbox" name="monitor" value="1" <?= !empty($c['monitor']) ? 'checked' : '' ?>> Alert me when this client goes stale</label>
    </div>
  </section>

  <section class="card accent">
    <h2>Server-side routes</h2>
    <label>Subnets behind this client — one per line
      <textarea class="inp mono" name="serverRoutes" rows="3" placeholder="192.168.110.0/24"><?= h($routesText) ?></textarea>
      <span class="help">Added to the hub's AllowedIPs for this peer <em>and</em> routed into <?= h($s['interface']) ?> on the hub. Overlaps with the tunnel or another client are refused.</span>
    </label>
    <label class="check"><input type="checkbox" name="advertise" value="1" <?= !empty($c['advertise']) ? 'checked' : '' ?>> Advertise these subnets to clients that opt in below</label>
  </section>

  <section class="card">
    <h2>Client config</h2>
    <div class="grid2">
      <label>Client AllowedIPs<input class="inp mono" name="allowedIps" value="<?= h($c['allowedIps']) ?>" required><span class="help">What the device sends into the tunnel.</span></label>
      <label>DNS<input class="inp mono" name="dns" value="<?= h($c['dns']) ?>" placeholder="(none)"></label>
      <label>Persistent keepalive (s)<input class="inp mono" name="keepalive" value="<?= h((string)$c['keepalive']) ?>" inputmode="numeric"><span class="help">0 = off. 25 for devices behind NAT.</span></label>
      <label>MTU<input class="inp mono" name="mtu" value="<?= h((string)($c['mtu'] ?? '')) ?>" placeholder="default" inputmode="numeric"></label>
    </div>
    <label class="check"><input type="checkbox" name="includeAdvertised" value="1" <?= !empty($c['includeAdvertised']) ? 'checked' : '' ?>> Also route advertised site subnets
      <span class="help"><?= $others ? 'Currently: ' . h(implode('; ', $others)) : 'No client advertises subnets yet.' ?></span></label>
  </section>

  <?php if ($new): ?>
  <section class="card">
    <h2>Keys</h2>
    <fieldset class="radios">
      <legend class="sr">Key source</legend>
      <label class="check"><input type="radio" name="keyMode" value="generate" checked data-keymode> Generate a key pair here (phones, laptops)</label>
      <label class="check"><input type="radio" name="keyMode" value="own" data-keymode> The device has its own key (routers) — paste its public key</label>
    </fieldset>
    <label id="ownKey" hidden>Device public key<input class="inp mono" name="publicKey" placeholder="44-character base64 key"></label>
    <label class="check"><input type="checkbox" name="presharedKey" value="1" <?= $c['presharedKey'] ? 'checked' : '' ?>> Use a preshared key</label>
  </section>
  <?php else: ?>
  <section class="card">
    <h2>Hub peer (read-only)</h2>
    <pre class="code"># <?= h($c['name']) ?>

[Peer]
PublicKey    = <?= h($c['publicKey']) ?>

<?= $c['presharedKey'] ? "PresharedKey = (hidden)\n" : '' ?>AllowedIPs   = <?= h(implode(', ', array_merge([$c['address'] . '/32'], $c['serverRoutes']))) ?></pre>
  </section>
  <?php endif; ?>
</form>

<?php if (!$new): ?>
<aside class="sidecol">
  <section class="card">
    <div class="row-between"><h2>Status</h2>
      <form method="post" action="?r=client-action&amp;id=<?= h(urlencode($c['id'])) ?>"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="toggle">
        <button class="toggle" type="submit" role="switch" aria-checked="<?= $c['enabled'] ? 'true' : 'false' ?>" aria-label="Enabled"><span></span></button></form>
    </div>
    <dl class="kv">
      <dt>Endpoint</dt><dd class="mono"><?= h($p['endpoint'] ?? '') ?: '—' ?></dd>
      <dt>Handshake</dt><dd><?= h(Util::ago($hs, $now)) ?></dd>
      <dt>Received</dt><dd class="mono"><?= Util::bytes((int)($p['rx'] ?? 0)) ?></dd>
      <dt>Sent</dt><dd class="mono"><?= Util::bytes((int)($p['tx'] ?? 0)) ?></dd>
      <dt>Alerts</dt><dd><?= !empty($c['monitor']) ? h($alertState[$c['id']] ?? 'watching') : 'off' ?></dd>
      <dt>Created</dt><dd><?= h(substr($c['createdAt'], 0, 10)) ?></dd>
    </dl>
  </section>

  <section class="card">
    <h2>Export</h2>
    <?php if ($c['privateKey'] !== null): ?>
      <img class="qrbox" src="?r=client-qr&amp;id=<?= h(urlencode($c['id'])) ?>" alt="QR code of the client config" width="220" height="220">
    <?php else: ?>
      <p class="muted small">This device keeps its own private key, so the downloaded config has a placeholder for it.</p>
    <?php endif; ?>
    <a class="btn" href="?r=client-conf&amp;id=<?= h(urlencode($c['id'])) ?>">Download .conf</a>
  </section>

  <section class="card">
    <h2>Keys</h2>
    <div><span class="lbl">Public key</span>
      <div class="mono small break"><?= h($c['publicKey']) ?></div>
      <button type="button" class="btn small" data-copy="<?= h($c['publicKey']) ?>">Copy</button>
    </div>
    <?php if ($c['privateKey'] === null): ?>
      <form method="post" action="?r=client-action&amp;id=<?= h(urlencode($c['id'])) ?>" class="stack">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="set-public-key">
        <label>Replace device public key<input class="inp mono" name="publicKey" required></label>
        <button class="btn" type="submit">Update key</button>
      </form>
    <?php endif; ?>
    <div class="btnrow">
      <form method="post" action="?r=client-action&amp;id=<?= h(urlencode($c['id'])) ?>" data-confirm="Generate new keys for <?= h($c['name']) ?>? The device stops working until it gets the new config.">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="rotate">
        <button class="btn" type="submit"><?= $c['privateKey'] === null ? 'New preshared key' : 'Rotate keys' ?></button>
      </form>
      <form method="post" action="?r=client-action&amp;id=<?= h(urlencode($c['id'])) ?>" data-confirm="Delete <?= h($c['name']) ?>? This cannot be undone (a backup is kept).">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete">
        <button class="btn danger" type="submit">Delete client</button>
      </form>
    </div>
  </section>
</aside>
<?php endif; ?>
</div>
