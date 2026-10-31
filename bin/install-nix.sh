#!/usr/bin/env bash
# Installe Nix (mono-utilisateur, propriétaire www-data) pour que l'application construise les images ici.
# Idempotent : lancé à chaque démarrage du conteneur Docker, ne réinstalle que si /nix est vide ou cassé.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_USER="${BUILD_USER:-www-data}"
NIX_HOME="$APP_DIR/storage/home"
# Version testée avec NIX Builder 0.1 ; surcharger avec NIX_VERSION=x.y.z.
NIX_VERSION="${NIX_VERSION:-2.35.2}"

if [ "$(id -u)" -ne 0 ]; then
  echo "À lancer en root : sudo $0" >&2
  exit 1
fi

mkdir -p /etc/nix
cat > /etc/nix/nix.conf <<'EOF'
experimental-features = nix-command flakes
# Le conteneur n'autorise pas les namespaces : pas de sandbox.
sandbox = false
# make-disk-image exige "kvm" ; sans /dev/kvm, QEMU bascule en émulation TCG (plus lent).
system-features = nixos-test benchmark big-parallel kvm
max-jobs = auto
cores = 0
substituters = https://cache.nixos.org
trusted-public-keys = cache.nixos.org-1:6NCHdD59X431o0gWypbMrAURkbJ16ZPMQFGspcDShjY=
keep-outputs = false
keep-derivations = false
EOF
# Les dossiers temporaires des builds (2 copies du système, 20 Go et plus avec un bureau) vont sur le disque
# de l'application plutôt que dans /nix, souvent sur la petite partition Docker.
BUILD_DIR="${NIXVM_BUILD_DIR:-$APP_DIR/storage/nix-builds}"
echo "build-dir = $BUILD_DIR" >> /etc/nix/nix.conf

mkdir -p /nix "$NIX_HOME" "$BUILD_DIR"
chown "$BUILD_USER:$BUILD_USER" /nix "$BUILD_DIR"
chown -R "$BUILD_USER:$BUILD_USER" "$APP_DIR/storage"

nix_bin() {
  for p in "$NIX_HOME/.nix-profile/bin/nix" "$NIX_HOME/.local/state/nix/profiles/profile/bin/nix" "/nix/var/nix/profiles/per-user/$BUILD_USER/profile/bin/nix"; do
    [ -x "$p" ] && { echo "$p"; return 0; }
  done
  return 1
}

if bin=$(nix_bin) && sudo -u "$BUILD_USER" env HOME="$NIX_HOME" "$bin" --version >/dev/null 2>&1; then
  echo "Nix déjà installé : $("$bin" --version)"
else
  rm -rf "$NIX_HOME/.nix-profile" "$NIX_HOME/.nix-defexpr" "$NIX_HOME/.nix-channels" "$NIX_HOME/.local/state/nix"
  installer=$(mktemp)
  curl -fsSL "https://releases.nixos.org/nix/nix-$NIX_VERSION/install" -o "$installer"
  chmod 755 "$installer"
  sudo -u "$BUILD_USER" env HOME="$NIX_HOME" USER="$BUILD_USER" sh "$installer" --no-daemon --yes --no-modify-profile
  rm -f "$installer"
  bin=$(nix_bin)
fi

ln -sfn "$bin" "$APP_DIR/storage/nix-bin"
echo
echo "Nix opérationnel : $(sudo -u "$BUILD_USER" env HOME="$NIX_HOME" "$bin" --version)"
echo "Binaire : $bin"
[ -e /dev/kvm ] || echo "Note : /dev/kvm absent, la création des disques sera émulée par QEMU (quelques minutes de plus par image)."
