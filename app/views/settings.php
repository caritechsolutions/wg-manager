<?php
/** @var array $s @var array $state @var string $preview @var string $csrf */
$fw = $s['firewall'];
$d = $s['defaults'];
$ui = $s['ui'];
$al = $s['alerts'];
$hidden = '<input type="hidden" name="_csrf" value="' . h($csrf) . '">';
$chk = fn(bool $v) => $v ? 'checked' : '';
?>
<div class="toolbar"><h1 class="grow">Settings</h1><a class="btn" href="?r=users">Admin users</a><a class="btn" href="?r=account">My account &amp; 2FA</a></div>

<div class="cards">
  <form class="card" method="post" action="?r=settings" id="interface">
    <?= $hidden ?><input type="hidden" name="section" value="interface">
    <h2>Interface</h2>
    <div class="grid2">
      <label>Name<input class="inp mono" name="interface" value="<?= h($s['interface']) ?>" required></label>
      <label>Listen port (UDP)<input class="inp mono" name="listenPort" value="<?= (int)$s['listenPort'] ?>" inputmode="numeric" required></label>
      <label>Server address<input class="inp mono" name="address" value="<?= h($s['address']) ?>" required><span class="help">Hub address and tunnel subnet, e.g. 10.8.0.1/24</span></label>
      <label>MTU<input class="inp mono" name="mtu" value="<?= h((string)($s['mtu'] ?? '')) ?>" placeholder="default"></label>
      <label>Public endpoint<input class="inp mono" name="endpointHost" value="<?= h($s['endpointHost']) ?>" placeholder="vpn.example.com"><span class="help">Written into client configs.</span></label>
      <label>Public port<input class="inp mono" name="endpointPort" value="<?= (int)$s['endpointPort'] ?>" inputmode="numeric"><span class="help">Usually the same as the listen port.</span></label>
    </div>
    <div><span class="lbl">Server public key</span><div class="mono small break"><?= h($state['server']['publicKey']) ?></div></div>
    <p class="warnbox small">Changing the name, port, address or MTU restarts the interface — every tunnel drops for a few seconds. Changing the subnet does not move existing clients.</p>
    <div class="btnrow"><button class="btn primary" type="submit">Save &amp; apply</button></div>
  </form>

  <form class="card" method="post" action="?r=settings" id="firewall">
    <?= $hidden ?><input type="hidden" name="section" value="firewall">
    <h2>Routing &amp; firewall</h2>
    <label class="check row"><span><b>IP forwarding</b><span class="help">net.ipv4.ip_forward = 1, persisted in /etc/sysctl.d</span></span><input type="checkbox" name="ipForward" value="1" <?= $chk($fw['ipForward']) ?>></label>
    <label class="check row"><span><b>Client-to-client traffic</b><span class="help">Allow forwarding <?= h($s['interface']) ?> → <?= h($s['interface']) ?> (needed to reach site subnets)</span></span><input type="checkbox" name="peerToPeer" value="1" <?= $chk($fw['peerToPeer']) ?>></label>
    <label class="check row"><span><b>NAT clients to the internet</b><span class="help">MASQUERADE the tunnel subnet out of the egress interface</span></span><input type="checkbox" name="nat" value="1" <?= $chk($fw['nat']) ?>></label>
    <label>NAT egress interface<input class="inp mono" name="natInterface" value="<?= h($fw['natInterface']) ?>" placeholder="auto (default route)"></label>
    <label class="check row"><span><b>Enforce client AllowedIPs on the hub</b><span class="help">A client can only reach what its AllowedIPs list — editing its own config won't widen access</span></span><input type="checkbox" name="enforceAllowedIps" value="1" <?= $chk($fw['enforceAllowedIps']) ?>></label>
    <div class="btnrow"><button class="btn primary" type="submit">Save &amp; apply</button></div>
  </form>

  <form class="card" method="post" action="?r=settings" id="defaults">
    <?= $hidden ?><input type="hidden" name="section" value="defaults">
    <h2>New-client defaults</h2>
    <div class="grid2">
      <label>Client AllowedIPs<input class="inp mono" name="allowedIps" value="<?= h($d['allowedIps']) ?>" required></label>
      <label>DNS<input class="inp mono" name="dns" value="<?= h($d['dns']) ?>" placeholder="(none)"></label>
      <label>Keepalive (s)<input class="inp mono" name="keepalive" value="<?= (int)$d['keepalive'] ?>"></label>
      <label>MTU<input class="inp mono" name="mtu" value="<?= h((string)($d['mtu'] ?? '')) ?>" placeholder="default"></label>
    </div>
    <label class="check"><input type="checkbox" name="presharedKey" value="1" <?= $chk($d['presharedKey']) ?>> Generate a preshared key for every new client</label>
    <div class="btnrow"><button class="btn primary" type="submit">Save</button></div>
  </form>

  <form class="card" method="post" action="?r=settings" id="ui">
    <?= $hidden ?><input type="hidden" name="section" value="ui">
    <h2>Web UI security</h2>
    <label>Only allow the UI from (IPs / CIDRs, one per line)
      <textarea class="inp mono" name="allowFrom" rows="3" placeholder="empty = anywhere"><?= h(implode("\n", $ui['allowFrom'])) ?></textarea>
      <span class="help">Your current address is <span class="mono"><?= h($_SERVER['REMOTE_ADDR'] ?? '') ?></span>. Locked out? On the server: <span class="mono">sudo wgm ui-allow clear</span></span>
    </label>
    <div class="grid3">
      <label>Session (min)<input class="inp mono" name="sessionMinutes" value="<?= (int)$ui['sessionMinutes'] ?>"></label>
      <label>Lock after (fails)<input class="inp mono" name="lockoutAttempts" value="<?= (int)$ui['lockoutAttempts'] ?>"></label>
      <label>Lock for (min)<input class="inp mono" name="lockoutMinutes" value="<?= (int)$ui['lockoutMinutes'] ?>"></label>
    </div>
    <div class="btnrow"><button class="btn primary" type="submit">Save</button></div>
  </form>

  <form class="card" method="post" action="?r=settings" id="alerts">
    <?= $hidden ?><input type="hidden" name="section" value="alerts">
    <h2>Down detection &amp; alerts</h2>
    <p class="help">A client is <b>stale</b> when the hub has received nothing from it — no keepalive, data or handshake — for longer than the threshold. Checked every 15 s. Clients need a PersistentKeepalive in their config (e.g. 25 s) or they look stale whenever they're idle.</p>
    <label>Stale after (seconds without traffic)<input class="inp mono" name="staleSeconds" value="<?= State::staleSeconds($s) ?>" inputmode="numeric"><span class="help">90 s = about three missed 25-second keepalives. Minimum 30.</span></label>
    <label class="check"><input type="checkbox" name="monitorAll" value="1" <?= $chk(!empty($al['monitorAll'])) ?>> Alert for every enabled client <span class="help">(mute single clients on their page). Off = only clients you tick individually.</span></label>
    <p class="help">Alerts are sent when a client goes down and when it comes back; several at once are combined into one message. Clients that were already offline don't alert until they've been seen once.</p>
    <fieldset class="sub">
      <legend><label class="check"><input type="checkbox" name="tgEnabled" value="1" <?= $chk($al['telegram']['enabled']) ?>> Telegram</label></legend>
      <div class="grid2">
        <label>Bot token<input class="inp mono" name="tgToken" type="password" placeholder="<?= $al['telegram']['botToken'] ? '(saved — leave empty to keep)' : '123456:ABC…' ?>" autocomplete="off"></label>
        <label>Chat id<input class="inp mono" name="tgChat" value="<?= h($al['telegram']['chatId']) ?>"></label>
      </div>
    </fieldset>
    <fieldset class="sub">
      <legend><label class="check"><input type="checkbox" name="emEnabled" value="1" <?= $chk($al['email']['enabled']) ?>> Email (SMTP)</label></legend>
      <div class="grid2">
        <label>SMTP host<input class="inp mono" name="emHost" value="<?= h($al['email']['host']) ?>"></label>
        <label>Port<input class="inp mono" name="emPort" value="<?= (int)$al['email']['port'] ?>"></label>
        <label>Security<select class="inp" name="emSecurity">
          <?php foreach (['starttls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'None'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $al['email']['security'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Username<input class="inp mono" name="emUser" value="<?= h($al['email']['username']) ?>" autocomplete="off"></label>
        <label>Password<input class="inp mono" name="emPass" type="password" placeholder="<?= $al['email']['password'] !== '' ? '(saved — leave empty to keep)' : '' ?>" autocomplete="new-password"></label>
        <label>From<input class="inp mono" name="emFrom" value="<?= h($al['email']['from']) ?>"></label>
      </div>
      <label>To (comma-separated)<input class="inp mono" name="emTo" value="<?= h($al['email']['to']) ?>"></label>
    </fieldset>
    <fieldset class="sub">
      <legend><label class="check"><input type="checkbox" name="whEnabled" value="1" <?= $chk($al['webhook']['enabled']) ?>> Webhook (JSON POST)</label></legend>
      <label>URL<input class="inp mono" name="whUrl" value="<?= h($al['webhook']['url']) ?>" placeholder="https://…"></label>
    </fieldset>
    <div class="btnrow">
      <button class="btn primary" type="submit">Save</button>
      <button class="btn" type="submit" formaction="?r=alerts-test" formnovalidate>Send test (saved settings)</button>
    </div>
  </form>

  <form class="card" method="post" action="?r=settings" id="backups">
    <?= $hidden ?><input type="hidden" name="section" value="backups">
    <h2>Backups</h2>
    <p class="help">A backup of the client database is taken before every change and once a night. The server private key is not included — keep a copy of <span class="mono">/etc/wireguard/wg-manager/server.key</span>.</p>
    <div class="grid2">
      <label>Keep the newest<input class="inp mono" name="keep" value="<?= (int)$s['backups']['keep'] ?>"></label>
      <label>Nightly backup hour (0–23, server time)<input class="inp mono" name="nightlyHour" value="<?= (int)$s['backups']['nightlyHour'] ?>"></label>
    </div>
    <div class="btnrow"><button class="btn primary" type="submit">Save</button><a class="btn" href="?r=activity#backups">Manage backups</a></div>
  </form>
</div>

<section class="card">
  <div class="row-between"><h2>Preview of /etc/wireguard/<?= h($s['interface']) ?>.conf</h2><span class="muted small">generated — never hand-edited</span></div>
  <pre class="code"><?= h($preview) ?></pre>
</section>
