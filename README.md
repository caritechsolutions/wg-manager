# wg-manager

A small, self-hosted web manager for a WireGuard **hub** — plain Linux, no Docker.
PHP + HTML/CSS/JS on nginx, kernel WireGuard via `wg-quick`, one root helper behind `sudo`.

Built to replace wg-easy on a hub that links many sites, with the thing wg-easy v14
couldn't do: **subnets behind a client** (site LANs behind a MikroTik, etc.) routed on the hub.

## Features

**Clients**
- Live list: online / stale / never connected, endpoint, last handshake, rx/tx (refreshes every 5 s)
- Search and filter; create, edit, enable/disable, delete
- **Server-side routes per client** — added to the hub's AllowedIPs *and* routed into the tunnel automatically
- Advertise a client's subnets into other clients' configs (e.g. let your laptop reach a site LAN)
- Overlap checks: no two clients can claim the same subnet; routes can't overlap the tunnel
- Client AllowedIPs, DNS, keepalive, MTU, group, notes, expiry date (expired clients are dropped from the hub)
- Generated keys (phones/laptops) or bring-your-own public key (routers keep their private key)
- QR code, `.conf` download, key rotation, preshared keys

**Hub**
- `/etc/wireguard/<if>.conf` is always generated — never hand-edited
- Changes apply with `wg syncconf` (no tunnel drops); only interface-level changes restart it
- Firewall managed in its own iptables chains (`WGM-IN`, `WGM-FWD`, `WGM-NAT`): forwarding,
  client-to-client, NAT to the internet, optional hub-side enforcement of client AllowedIPs
- Works on hosts that also run Docker (its FORWARD DROP policy is handled)

**Safety & operations**
- The web app (www-data) can run exactly one program as root: `/usr/local/sbin/wg-manager-helper`,
  which re-validates everything before writing a config
- The server private key is root-only; the web side never sees it
- Admin users, bcrypt passwords, optional TOTP 2FA, login lockout, optional IP allow-list, CSRF, strict CSP
- HTTPS via Let's Encrypt (`--domain`)
- Audit log of every change (who, from where, what), CSV export
- Backup before every change + nightly, one-click restore, download/upload
- Health panel: interface, forwarding, routes vs config, firewall, nginx, php-fpm, TLS expiry
- Stale-peer alerts via Telegram, email (SMTP) or a JSON webhook
- `wgm` CLI for everything you'd want over SSH

IPv4 tunnels and routes. (Client configs may still list IPv6 AllowedIPs.)

## Install

Ubuntu 22.04/24.04 or Debian 12:

```bash
curl -fsSL https://raw.githubusercontent.com/caritechsolutions/wg-manager/main/install.sh \
  | sudo bash -s -- --endpoint vpn.example.com
```

The script downloads the code itself (no git needed) and installs it to `/opt/wg-manager`.
Options go after `--`. To install a specific version, put `WGM_REF=<tag or branch>` before `bash`:
`... | sudo WGM_REF=v1.0.0 bash -s -- ...`

The installer prints the web UI address and the admin password. Common options:

| Option | Default | |
|---|---|---|
| `--interface` | `wg0` | WireGuard interface name |
| `--port` | `51820` | UDP listen port |
| `--address` | `10.8.0.1/24` | hub address + tunnel subnet |
| `--endpoint` | detected | public host/IP written into client configs |
| `--allowed-ips` | tunnel subnet | default client AllowedIPs |
| `--dns` | none | default client DNS |
| `--ui-port` | `8080` (`8443` with TLS) | web UI port |
| `--domain`, `--email` | — | Let's Encrypt certificate, UI over HTTPS (needs port 80 reachable) |
| `--admin`, `--admin-password` | `admin`, random | first admin user |
| `--import-wgeasy FILE` | — | start from a wg-easy `wg0.json` |
| `--no-apply` | — | don't bring the interface up |

Open the WireGuard UDP port and the UI port in any firewall in front of the server.
If ufw is active the installer opens them for you.

**Upgrade:** run the same `curl … | sudo bash -s --` command again (options can be left off) —
code and services are refreshed, clients, settings, users and keys are kept.

## Moving from wg-easy (Docker)

Run wg-manager **next to** the running wg-easy first, on a different interface, port and subnet,
so nothing changes for existing sites:

```bash
curl -fsSL https://raw.githubusercontent.com/caritechsolutions/wg-manager/main/install.sh \
  | sudo bash -s -- --interface wg1 --port 51822 --address 10.9.0.1/24 \
      --endpoint wireguard.caritech.net --ui-port 8080
```

Try it with a test client or two. When you're happy, cut over (a few minutes of downtime):

```bash
# 1. Back up wg-easy (also click "Backup" in its UI and keep the file)
docker cp wireguard:/etc/wireguard/wg0.json /root/wg0.json

# 2. Note wg-easy's client defaults
docker inspect wireguard --format '{{range .Config.Env}}{{println .}}{{end}}' | grep ^WG_

# 3. Import: same server key, same clients and addresses; switch to wg0 / 51820.
#    Use WG_ALLOWED_IPS from step 2 for --allowed-ips (and --dns if WG_DEFAULT_DNS is set).
#    Test clients created on wg1 are replaced.
sudo wgm import-wgeasy /root/wg0.json --interface wg0 --port 51820 \
     --endpoint wireguard.caritech.net --allowed-ips 10.8.0.0/24

# 4. Add the subnets that live behind clients
sudo wgm route add 10.8.0.54 192.168.110.0/24

# 5. Stop wg-easy and keep it from restarting
docker stop wireguard && docker update --restart=no wireguard

# 6. Go live (stops wg1, starts wg0 with all clients)
sudo wgm apply
sudo ufw allow 51820/udp     # only if ufw is active
sudo wgm status              # sites reconnect within their keepalive interval
```

Peers don't need new configs: the server key, port, endpoint and addresses are unchanged.

**Roll back:** `sudo systemctl disable --now wg-quick@wg0 && docker start wireguard && docker update --restart=unless-stopped wireguard`

## CLI

```
sudo wgm help
sudo wgm list                         # clients
sudo wgm status                       # live peers
sudo wgm health                       # checks
sudo wgm route add <client> <cidr>    # subnet behind a client (then: wgm apply)
sudo wgm apply                        # write config + sync interface
sudo wgm user add|passwd|reset-2fa|del <name>
sudo wgm ui-allow clear               # if the UI allow-list locked you out
sudo wgm backup | backups | restore <name>
```

## Files

| Path | What | Owner |
|---|---|---|
| `/opt/wg-manager` | the app (this repo) | root |
| `/var/lib/wg-manager/state.json` | settings + clients | www-data, 0600 |
| `/var/lib/wg-manager/users.json` | admin users | www-data, 0600 |
| `/var/lib/wg-manager/audit.jsonl` | audit log | www-data |
| `/var/lib/wg-manager/backups/` | state backups | www-data |
| `/etc/wireguard/wg-manager/server.key` | **hub private key** | root, 0600 |
| `/etc/wireguard/<if>.conf` | generated config | root, 0600 |
| `/usr/local/sbin/wg-manager-helper` | root helper | root |
| `/etc/sudoers.d/wg-manager` | lets www-data run the helper only | root |
| `wg-manager-tick.timer` | down detection, alerts, expiry, nightly backup (every 15 s) | systemd |

**Back up `server.key` somewhere safe.** State backups don't contain it; without it every
client needs a new config.

## Uninstall

```bash
sudo /opt/wg-manager/uninstall.sh          # removes the app and stops the hub, keeps data
sudo /opt/wg-manager/uninstall.sh --purge  # also deletes clients, backups and the server key
```
