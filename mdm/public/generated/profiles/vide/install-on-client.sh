#!/usr/bin/env bash
# Installe le client Linux MDM (enregistrement appareil + profil initial: vide)
# Usage (root) : curl -fsSL https://nix.borninthe80s.fr/mdm/generated/profiles/vide/install-on-client.sh | sudo bash
# Changer de profil ensuite : interface serveur → Machines (pas besoin de réinstaller).
set -euo pipefail

HUB="${LINUX_MDM_URL:-${NIX_MDM_URL:-https://nix.borninthe80s.fr/mdm}}"
TARGET="/etc/nixos/parc.nix"
AUTH_FILE="/etc/nixos/mdm.auth"
CFG="/etc/nixos/configuration.nix"
PROFILE_SLUG='vide'

if [[ $(id -u) -ne 0 ]]; then
  echo "À lancer en root (sudo)." >&2
  exit 1
fi

if [[ ! -d /etc/nixos ]] || [[ ! -f /etc/NIXOS ]]; then
  echo "Cette machine ne semble pas être NixOS (/etc/nixos manquant)." >&2
  exit 1
fi

mkdir -p /etc/nixos
HOSTNAME_FQDN="$(hostname -f 2>/dev/null || hostname)"
MAC="$(cat /sys/class/net/*/address 2>/dev/null | grep -vi '^00:00:00:00:00:00$' | head -1 || true)"
EXISTING_TOKEN=""
if [[ -f "$AUTH_FILE" ]]; then
  # shellcheck disable=SC1090
  . "$AUTH_FILE" || true
  EXISTING_TOKEN="${MDM_DEVICE_TOKEN:-}"
fi

echo "→ Enregistrement de l'appareil sur le hub …"
# shellcheck disable=SC1091
eval "$(curl -fsSL -X POST "$HUB/register.php?format=shell" \
  --data-urlencode "hostname=$HOSTNAME_FQDN" \
  --data-urlencode "mac=$MAC" \
  --data-urlencode "device_token=$EXISTING_TOKEN" \
  --data-urlencode "profile_slug=$PROFILE_SLUG" \
  --data-urlencode "format=shell")"
TOKEN="${MDM_DEVICE_TOKEN:-}"
ASSIGNED="${MDM_PROFILE:-}"
if [[ -z "$TOKEN" ]]; then
  echo "Échec enregistrement hub (pas de token)." >&2
  exit 1
fi

printf 'MDM_URL=%s\nMDM_DEVICE_TOKEN=%s\nMDM_PROFILE=%s\n' 'https://nix.borninthe80s.fr/mdm' "$TOKEN" "${ASSIGNED:-$PROFILE_SLUG}" > "$AUTH_FILE"
# Lisible par les utilisateurs (lanceur Centre logiciels depuis le menu)
chmod 644 "$AUTH_FILE"

echo "→ Téléchargement du parc (profil serveur : ${ASSIGNED:-$PROFILE_SLUG}) …"
curl -fsSL "$HUB/parc-for-device.php?token=$TOKEN" -o "$TARGET"
chmod 644 "$TARGET"
ln -sfn parc.nix /etc/nixos/auto-upgrade.nix

if [[ ! -f "$CFG" ]]; then
  echo "Fichier manquant : $CFG" >&2
  exit 1
fi

if grep -qE 'parc\.nix|auto-upgrade\.nix' "$CFG"; then
  echo "→ parc.nix déjà présent dans les imports."
else
  echo "→ Ajout de ./parc.nix dans $CFG …"
  if grep -qE 'imports[[:space:]]*=' "$CFG"; then
    # Insère ./parc.nix juste après le premier « imports = [ »
    sed -i -E '0,/imports[[:space:]]*=[[:space:]]*\[/s//imports = [ .\/parc.nix /' "$CFG"
  else
    echo "Impossible d’injecter automatiquement : pas de bloc imports dans $CFG." >&2
    echo "Ajoutez manuellement : imports = [ ./hardware-configuration.nix ./parc.nix ];" >&2
    exit 1
  fi
fi

# NIX_PATH souvent absent hors session interactive
if [[ -z "${NIX_PATH:-}" ]]; then
  if [[ -e /nix/var/nix/profiles/per-user/root/channels/nixpkgs ]]; then
    export NIX_PATH="nixpkgs=/nix/var/nix/profiles/per-user/root/channels/nixpkgs:nixos-config=$CFG"
  elif [[ -e /root/.nix-defexpr/channels/nixpkgs ]]; then
    export NIX_PATH="nixpkgs=/root/.nix-defexpr/channels/nixpkgs:nixos-config=$CFG"
  fi
fi
export HOME="${HOME:-/root}"

echo "→ nixos-rebuild switch (installation des paquets + service sync) …"
if command -v nixos-rebuild >/dev/null 2>&1; then
  nixos-rebuild switch
else
  /run/current-system/sw/bin/nixos-rebuild switch
fi

echo "→ Activation du timer de synchronisation …"
systemctl enable --now nix-builder-mdm-sync.timer 2>/dev/null || true
systemctl daemon-reload 2>/dev/null || true

echo ""
echo "OK — client MDM installé."
echo "  Module : $TARGET"
echo "  Sync   : nix-builder-mdm-sync.timer (actif)"
echo "  Force  : systemctl start nix-builder-mdm-sync.service"
echo "  Centre Logiciels : linux-mdm-centre  (menu Applications → Centre Logiciels)"
systemctl --no-pager --full status nix-builder-mdm-sync.timer 2>/dev/null || true
