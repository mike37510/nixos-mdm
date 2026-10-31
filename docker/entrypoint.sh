#!/usr/bin/env bash
set -euo pipefail

APP_DIR=/var/www/html

mkdir -p "$APP_DIR/storage" \
  "$APP_DIR/mdm/data" \
  "$APP_DIR/mdm/public/generated"
chown www-data:www-data "$APP_DIR/storage"
chown -R www-data:www-data "$APP_DIR/mdm/data" "$APP_DIR/mdm/public/generated"
chmod -R ug+rwX "$APP_DIR/mdm/data" "$APP_DIR/mdm/public/generated"

# Auth applicative (login.php) — plus de HTTP Basic .htaccess.
# /mdm/generated/ reste public pour les clients NixOS.
rm -f "$APP_DIR/storage/.htpasswd"
cat > "$APP_DIR/.htaccess" <<'EOF'
# Linux MDM — auth gérée par l'application (sessions PHP).
# Les fichiers clients restent accessibles sans login.
<IfModule mod_rewrite.c>
  RewriteEngine On
</IfModule>
EOF
cat > "$APP_DIR/mdm/public/generated/.htaccess" <<'EOF'
Require all granted
EOF
echo "nixvm: auth applicative (login.php) — /mdm/generated/ public" >&2

# Nix vit dans le volume /nix : l'installation n'est refaite que si le volume est vide ou cassé.
if [ -x "$APP_DIR/bin/install-nix.sh" ]; then
  "$APP_DIR/bin/install-nix.sh" || echo "nixvm: installation de Nix impossible, les builds seront désactivés" >&2
fi

# Donne accès à /dev/kvm au worker (www-data) si le device est passé au conteneur.
if [ -e /dev/kvm ]; then
  kvm_gid=$(stat -c %g /dev/kvm)
  getent group "$kvm_gid" >/dev/null || groupadd -g "$kvm_gid" kvm-host
  usermod -aG "$(getent group "$kvm_gid" | cut -d: -f1)" www-data
fi

if [ -n "${NIXVM_ROOT_PASSWORD:-}" ]; then
  echo "root:$NIXVM_ROOT_PASSWORD" | chpasswd
  mkdir -p /etc/ssh/host_keys /run/sshd
  chmod 700 /etc/ssh/host_keys
  [ -f /etc/ssh/host_keys/ssh_host_ed25519_key ] || ssh-keygen -q -t ed25519 -N '' -f /etc/ssh/host_keys/ssh_host_ed25519_key
  [ -f /etc/ssh/host_keys/ssh_host_rsa_key ] || ssh-keygen -q -t rsa -b 4096 -N '' -f /etc/ssh/host_keys/ssh_host_rsa_key
  /usr/sbin/sshd
else
  echo "nixvm: NIXVM_ROOT_PASSWORD vide, SSH désactivé" >&2
fi

exec docker-php-entrypoint "$@"
