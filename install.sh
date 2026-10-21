#!/usr/bin/env bash
#
# wg-manager installer — plain Linux (Ubuntu 22.04/24.04, Debian 12), no Docker.
#
#   curl -fsSL https://raw.githubusercontent.com/caritechsolutions/wg-manager/main/install.sh | sudo bash -s -- [options]
#
# Run that way, the script downloads the release tarball itself (no git needed).
# Set WGM_REF=<branch|tag> to install a specific version (default: main).
# Running ./install.sh from an unpacked copy works too.
#
# Safe to re-run: code, services and web config are refreshed; existing
# clients, settings, users and keys are kept.
#
# Options
#   --interface NAME       WireGuard interface               (default wg0)
#   --port N               WireGuard UDP listen port          (default 51820)
#   --address CIDR         hub address + tunnel subnet        (default 10.8.0.1/24)
#   --endpoint HOST        public hostname/IP for client configs (default: --domain, else detected)
#   --allowed-ips CIDRs    default client AllowedIPs          (default: the tunnel subnet)
#   --dns IPs              default client DNS                 (default: none)
#   --ui-port N            web UI port                        (default 8080, or 8443 with --domain)
#   --domain NAME          get a Let's Encrypt certificate and serve the UI over HTTPS
#   --email ADDR           contact address for Let's Encrypt (required with --domain)
#   --admin NAME           first admin user                   (default admin)
#   --admin-password P     its password                       (default: random, printed)
#   --import-wgeasy FILE   start from a wg-easy v14 wg0.json instead of a fresh hub
#   --no-apply             don't bring the interface up at the end
#   --no-ufw               don't touch ufw even if it is active
#
set -euo pipefail

WGM_REPO="${WGM_REPO:-caritechsolutions/wg-manager}"
WGM_REF="${WGM_REF:-main}"

# ------------------------------------------------------------------ bootstrap
# When piped from curl there is no source tree next to the script: fetch the
# tarball, unpack it to a temp dir and re-run the installer from there.
# (Kept as one compound command so bash parses it fully before running it.)
if [[ -z "${BASH_SOURCE[0]:-}" || ! -f "$(dirname "${BASH_SOURCE[0]}")/lib/bootstrap.php" ]]; then
  if [[ $EUID -ne 0 ]]; then
    echo "error: run as root:  curl -fsSL https://raw.githubusercontent.com/$WGM_REPO/$WGM_REF/install.sh | sudo bash -s -- [options]" >&2
    exit 1
  fi
  command -v curl >/dev/null || { echo "error: curl is required" >&2; exit 1; }
  command -v tar >/dev/null || { echo "error: tar is required" >&2; exit 1; }
  WGM_TMP="$(mktemp -d /tmp/wg-manager.XXXXXX)"
  printf '\033[1;32m==>\033[0m Downloading wg-manager (%s@%s)\n' "$WGM_REPO" "$WGM_REF"
  if ! curl -fsSL "https://codeload.github.com/$WGM_REPO/tar.gz/$WGM_REF" | tar -xz -C "$WGM_TMP" --strip-components=1; then
    echo "error: download failed (https://codeload.github.com/$WGM_REPO/tar.gz/$WGM_REF)" >&2
    rm -rf "$WGM_TMP"
    exit 1
  fi
  [[ -f "$WGM_TMP/lib/bootstrap.php" ]] || { echo "error: downloaded archive is not wg-manager" >&2; rm -rf "$WGM_TMP"; exit 1; }
  WGM_CLEANUP_DIR="$WGM_TMP" exec bash "$WGM_TMP/install.sh" "$@" </dev/null
fi

APP_DIR=/opt/wg-manager
STATE_DIR=/var/lib/wg-manager
ETC_DIR=/etc/wg-manager
KEY_DIR=/etc/wireguard/wg-manager
HELPER=/usr/local/sbin/wg-manager-helper
WEB_USER=www-data
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

IFACE=wg0
PORT=51820
ADDRESS=10.8.0.1/24
ENDPOINT=""
ALLOWED_IPS=""
DNS=""
UI_PORT=""
DOMAIN=""
EMAIL=""
ADMIN="admin"
ADMIN_PW=""
IMPORT=""
APPLY=1
UFW=1

POLICY=/usr/sbin/policy-rc.d
POLICY_OURS=0
# shellcheck disable=SC2317  # runs from the EXIT trap
cleanup() {
  if [[ $POLICY_OURS -eq 1 ]]; then rm -f "$POLICY"; fi
  # Remove the temp copy made by a curl | bash install.
  if [[ -n "${WGM_CLEANUP_DIR:-}" && "$WGM_CLEANUP_DIR" == /tmp/wg-manager.* ]]; then rm -rf "$WGM_CLEANUP_DIR"; fi
}
trap cleanup EXIT

say()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mwarning:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

need_arg() { [[ $# -ge 2 && -n "$2" ]] || die "$1 needs a value"; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --interface)      need_arg "$@"; IFACE="$2"; shift 2 ;;
    --port)           need_arg "$@"; PORT="$2"; shift 2 ;;
    --address)        need_arg "$@"; ADDRESS="$2"; shift 2 ;;
    --endpoint)       need_arg "$@"; ENDPOINT="$2"; shift 2 ;;
    --allowed-ips)    need_arg "$@"; ALLOWED_IPS="$2"; shift 2 ;;
    --dns)            need_arg "$@"; DNS="$2"; shift 2 ;;
    --ui-port)        need_arg "$@"; UI_PORT="$2"; shift 2 ;;
    --domain)         need_arg "$@"; DOMAIN="$2"; shift 2 ;;
    --email)          need_arg "$@"; EMAIL="$2"; shift 2 ;;
    --admin)          need_arg "$@"; ADMIN="$2"; shift 2 ;;
    --admin-password) need_arg "$@"; ADMIN_PW="$2"; shift 2 ;;
    --import-wgeasy)  need_arg "$@"; IMPORT="$2"; shift 2 ;;
    --no-apply)       APPLY=0; shift ;;
    --no-ufw)         UFW=0; shift ;;
    -h|--help)        sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown option $1 (see --help)" ;;
  esac
done

[[ $EUID -eq 0 ]] || die "run as root: sudo $0 $*"
command -v apt-get >/dev/null || die "this installer supports Debian/Ubuntu (apt) only"
[[ "$IFACE" =~ ^[a-zA-Z0-9_=+.-]{1,15}$ ]] || die "invalid interface name $IFACE"
[[ "$PORT" =~ ^[0-9]+$ ]] || die "invalid port $PORT"
# On a re-run (upgrade), keep the web settings from the previous install
# unless new ones are given.
PREV_INSTALL=/etc/wg-manager/install.json
if [[ -f "$PREV_INSTALL" ]]; then
  if [[ -z "$DOMAIN" ]]; then
    DOMAIN="$(sed -n 's/.*"domain": *"\([^"]*\)".*/\1/p' "$PREV_INSTALL")"
  fi
  if [[ -z "$UI_PORT" ]]; then
    UI_PORT="$(sed -n 's/.*"uiPort": *\([0-9]*\).*/\1/p' "$PREV_INSTALL")"
  fi
fi
# An e-mail is only needed when a new certificate must be requested.
if [[ -n "$DOMAIN" && -z "$EMAIL" && ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]]; then
  die "--domain needs --email for Let's Encrypt"
fi
[[ -z "$IMPORT" || -f "$IMPORT" ]] || die "cannot read $IMPORT"
[[ -n "$IMPORT" ]] && IMPORT="$(cd "$(dirname "$IMPORT")" && pwd)/$(basename "$IMPORT")"
if [[ -z "$UI_PORT" ]]; then UI_PORT=$([[ -n "$DOMAIN" ]] && echo 8443 || echo 8080); fi
[[ "$UI_PORT" =~ ^[0-9]+$ ]] || die "invalid UI port $UI_PORT"

# ------------------------------------------------------------------ packages
say "Installing packages"
export DEBIAN_FRONTEND=noninteractive
NGINX_NEW=0
dpkg -s nginx >/dev/null 2>&1 || NGINX_NEW=1
# Stop apt from auto-starting nginx on port 80 while we install it.
if [[ ! -e $POLICY ]]; then printf '#!/bin/sh\nexit 101\n' >"$POLICY"; chmod 755 "$POLICY"; POLICY_OURS=1; fi

PKGS=(wireguard-tools iproute2 iptables nginx php-fpm php-cli php-curl qrencode openssl sudo rsync ca-certificates curl)
[[ -n "$DOMAIN" ]] && PKGS+=(certbot)
apt-get update -qq
apt-get install -y -qq "${PKGS[@]}" >/dev/null
if [[ $POLICY_OURS -eq 1 ]]; then rm -f "$POLICY"; POLICY_OURS=0; fi

modprobe wireguard 2>/dev/null || true
[[ -d /sys/module/wireguard ]] || warn "the wireguard kernel module is not loaded (needs Linux 5.6+ or the wireguard-dkms package)"

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_SVC="php${PHP_VER}-fpm"
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
php -r 'exit(function_exists("sodium_crypto_scalarmult_base") ? 0 : 1);' || warn "PHP sodium missing — falling back to the wg binary for key generation"
dpkg --compare-versions "$PHP_VER" ge 8.1 || die "PHP 8.1+ is required (found $PHP_VER)"

# ------------------------------------------------------------------ code
say "Installing wg-manager to $APP_DIR"
mkdir -p "$APP_DIR"
if [[ "$SRC_DIR" != "$APP_DIR" ]]; then
  rsync -a --delete --exclude '.git' "$SRC_DIR/" "$APP_DIR/"
fi
chown -R root:root "$APP_DIR"
# Readable by nginx/php-fpm (www-data), writable only by root. The curl
# bootstrap unpacks into a 0700 temp dir, so set this explicitly.
chmod 0755 "$APP_DIR"
chmod -R u=rwX,go=rX "$APP_DIR"
chmod 755 "$APP_DIR/bin/wgm" "$APP_DIR/sbin/wg-manager-helper"
install -m 0755 -o root -g root "$APP_DIR/sbin/wg-manager-helper" "$HELPER"
ln -sf "$APP_DIR/bin/wgm" /usr/local/bin/wgm

install -d -m 0700 -o "$WEB_USER" -g "$WEB_USER" "$STATE_DIR"
install -d -m 0755 -o root -g root "$ETC_DIR"
install -d -m 0700 -o root -g root /etc/wireguard "$KEY_DIR"

cat >/etc/sudoers.d/wg-manager <<EOF
# wg-manager: the web app may run exactly one program as root.
$WEB_USER ALL=(root) NOPASSWD: $HELPER
EOF
chmod 0440 /etc/sudoers.d/wg-manager
visudo -cf /etc/sudoers.d/wg-manager >/dev/null || die "sudoers file failed validation"

# ------------------------------------------------------------------ state
ENDPOINT="${ENDPOINT:-$DOMAIN}"
detect_endpoint() {
  if [[ -z "$ENDPOINT" ]]; then
    ENDPOINT="$(curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -f)"
    warn "no --endpoint given; using $ENDPOINT (change it under Settings → Interface)"
  fi
}

if [[ -f "$STATE_DIR/state.json" ]]; then
  say "Existing configuration kept ($STATE_DIR/state.json)"
  [[ -n "$IMPORT" ]] && warn "--import-wgeasy ignored because wg-manager is already set up; use: sudo wgm import-wgeasy $IMPORT"
elif [[ -n "$IMPORT" ]]; then
  say "Importing wg-easy clients from $IMPORT"
  detect_endpoint
  args=(import-wgeasy "$IMPORT" --interface "$IFACE" --port "$PORT" --endpoint "$ENDPOINT")
  [[ -n "$ALLOWED_IPS" ]] && args+=(--allowed-ips "$ALLOWED_IPS")
  [[ -n "$DNS" ]] && args+=(--dns "$DNS")
  wgm "${args[@]}"
else
  say "Creating a new hub: $IFACE $ADDRESS udp/$PORT"
  detect_endpoint
  args=(init --interface "$IFACE" --port "$PORT" --address "$ADDRESS" --endpoint "$ENDPOINT")
  [[ -n "$ALLOWED_IPS" ]] && args+=(--allowed-ips "$ALLOWED_IPS")
  [[ -n "$DNS" ]] && args+=(--dns "$DNS")
  wgm "${args[@]}"
fi

ADMIN_MSG=""
if [[ ! -s "$STATE_DIR/users.json" ]] || [[ "$(tr -d ' \n' <"$STATE_DIR/users.json")" == "[]" ]]; then
  if [[ -z "$ADMIN_PW" ]]; then
    ADMIN_PW="$(tr -dc 'A-HJ-NP-Za-km-z2-9' </dev/urandom | head -c 16 || true)"
  fi
  wgm user add "$ADMIN" --password "$ADMIN_PW" >/dev/null
  ADMIN_MSG="Admin login: $ADMIN / $ADMIN_PW   (change it under Account)"
fi
chown -R "$WEB_USER:$WEB_USER" "$STATE_DIR"

# ------------------------------------------------------------------ web server
say "Configuring nginx on port $UI_PORT"
if [[ $NGINX_NEW -eq 1 ]]; then
  rm -f /etc/nginx/sites-enabled/default
fi
LISTEN6=""
[[ -e /proc/net/if_inet6 ]] && LISTEN6=1

TLS=0
if [[ -n "$DOMAIN" ]]; then
  if [[ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]]; then
    say "Requesting a Let's Encrypt certificate for $DOMAIN (needs port 80 reachable)"
    install -d -m 0755 /var/www/wg-manager-acme
    cat >/etc/nginx/sites-available/wg-manager-acme <<EOF
server {
    listen 80;
    ${LISTEN6:+listen [::]:80;}
    server_name $DOMAIN;
    location /.well-known/acme-challenge/ { root /var/www/wg-manager-acme; }
    location / { return 404; }
}
EOF
    ln -sf /etc/nginx/sites-available/wg-manager-acme /etc/nginx/sites-enabled/wg-manager-acme
    if nginx -t >/dev/null 2>&1 && systemctl restart nginx; then
      certbot certonly --webroot -w /var/www/wg-manager-acme -d "$DOMAIN" -m "$EMAIL" --agree-tos -n \
        --deploy-hook 'systemctl reload nginx' || warn "certbot failed — the UI will use plain HTTP for now"
    else
      warn "nginx could not listen on port 80 (in use?) — skipping Let's Encrypt; the UI will use plain HTTP"
    fi
  fi
  [[ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]] && TLS=1
fi

SSL_LINES=""
if [[ $TLS -eq 1 ]]; then
  SSL_LINES="    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    add_header Strict-Transport-Security \"max-age=31536000\" always;"
fi
SSL_FLAG=""; if [[ $TLS -eq 1 ]]; then SSL_FLAG=" ssl"; fi

cat >/etc/nginx/sites-available/wg-manager <<EOF
# wg-manager web UI — generated by install.sh
server {
    listen $UI_PORT$SSL_FLAG;
    ${LISTEN6:+listen [::]:$UI_PORT$SSL_FLAG;}
    server_name ${DOMAIN:-_};
$SSL_LINES
    root $APP_DIR/public;
    index index.php;
    client_max_body_size 6m;
    server_tokens off;

    location /assets/ { try_files \$uri =404; expires 7d; }
    location = /index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$FPM_SOCK;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
    location / { try_files \$uri /index.php\$is_args\$args; }
}
EOF
ln -sf /etc/nginx/sites-available/wg-manager /etc/nginx/sites-enabled/wg-manager
nginx -t >/dev/null 2>&1 || { nginx -t; die "nginx configuration test failed"; }
systemctl enable --now "$FPM_SVC" >/dev/null
systemctl enable nginx >/dev/null
systemctl restart nginx

cat >"$ETC_DIR/install.json" <<EOF
{"version": "$(php -r "require '$APP_DIR/lib/bootstrap.php'; echo WGM_VERSION;")", "uiPort": $UI_PORT, "domain": "$DOMAIN", "tls": $([[ $TLS -eq 1 ]] && echo true || echo false), "installedAt": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"}
EOF
chmod 0644 "$ETC_DIR/install.json"

# ------------------------------------------------------------------ background job
cat >/etc/systemd/system/wg-manager-tick.service <<EOF
[Unit]
Description=wg-manager background job (expiry, alerts, nightly backup)
After=network-online.target

[Service]
Type=oneshot
User=$WEB_USER
Group=$WEB_USER
ExecStart=/usr/bin/php $APP_DIR/bin/wgm tick
EOF
cat >/etc/systemd/system/wg-manager-tick.timer <<EOF
[Unit]
Description=Run the wg-manager background job every 15 seconds

[Timer]
OnBootSec=1min
OnUnitActiveSec=15s
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now wg-manager-tick.timer >/dev/null

# ------------------------------------------------------------------ firewall (ufw)
CUR_PORT="$(php -r "require '$APP_DIR/lib/bootstrap.php'; echo Store::load()['settings']['listenPort'];")"
CUR_IF="$(php -r "require '$APP_DIR/lib/bootstrap.php'; echo Store::load()['settings']['interface'];")"
CUR_EP="$(php -r "require '$APP_DIR/lib/bootstrap.php'; echo Store::load()['settings']['endpointHost'];")"
if [[ $UFW -eq 1 ]] && command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q '^Status: active'; then
  say "Opening ufw: $CUR_PORT/udp (WireGuard), $UI_PORT/tcp (web UI)"
  ufw allow "$CUR_PORT/udp" comment 'wg-manager wireguard' >/dev/null
  ufw allow "$UI_PORT/tcp" comment 'wg-manager web ui' >/dev/null
  [[ -n "$DOMAIN" ]] && ufw allow 80/tcp comment 'wg-manager acme' >/dev/null
  ufw route allow in on "$CUR_IF" >/dev/null 2>&1 || true
fi

# ------------------------------------------------------------------ bring it up
if [[ $APPLY -eq 1 ]]; then
  say "Applying configuration"
  wgm apply || warn "apply failed — fix the message above, then run: sudo wgm apply"
fi

SCHEME=$([[ $TLS -eq 1 ]] && echo https || echo http)
HOST="${DOMAIN:-${CUR_EP:-$(hostname -f)}}"
echo
say "wg-manager is installed."
echo "    Web UI:     $SCHEME://$HOST:$UI_PORT/"
[[ -n "$ADMIN_MSG" ]] && echo "    $ADMIN_MSG"
echo "    Interface:  $CUR_IF  (udp/$CUR_PORT)"
echo "    CLI:        sudo wgm help"
echo "    Keep a copy of $KEY_DIR/server.key somewhere safe — it is the hub's identity."
[[ $TLS -eq 0 ]] && echo "    Note: the UI is plain HTTP. Restrict it (Settings → Web UI security) or use --domain for HTTPS."
exit 0
