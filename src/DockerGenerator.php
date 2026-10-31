<?php
declare(strict_types=1);

namespace NixVm;

/**
 * Export Docker/Kasm d'un projet OS (même spec que l'OVA) :
 * flake Nix qui construit une image OCI avec bureau + KasmVNC + paquets du projet.
 *
 * L'OVA reste le NixOS système complet ; ce ZIP est une variante conteneur
 * (paquets Nix + XFCE + KasmVNC) pour navigateur / Kasm Workspaces.
 *
 * @see https://github.com/kasmtech/KasmVNC
 */
final class DockerGenerator
{
    private const KASMVNC_VERSION = '1.4.0';
    private const KASMVNC_SHA256 = 'sha256-oFm5242Tp9i7dT6c8rEZsTLIzw2DK1SaEoe4G+aOlWo=';
    private const LIBCRYPT_SHA256 = 'sha256-9fYKXN/U5OqpQ4reUHild0Gnp41ln8sMcBIE9SPovSk=';

    /** @param array<string, mixed> $spec */
    public function __construct(private readonly array $spec)
    {
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        $port = $this->vncPort();
        return [
            'flake.nix' => $this->flake(),
            'kasmvnc.nix' => $this->kasmvncNix(),
            'libcrypt-compat.nix' => $this->libcryptNix(),
            'start-desktop.sh' => $this->startDesktop(),
            'docker-compose.yml' => $this->compose($port),
            '.env.example' => $this->envExample($port),
            'build.sh' => $this->buildScript(),
            'README.md' => $this->readme($port),
        ];
    }

    private function vncPort(): int
    {
        $configured = (int) ($this->spec['docker']['hostPort'] ?? 0);
        return $configured > 0 ? $configured : 8443;
    }

    private function imageName(): string
    {
        return 'nixbuilder-' . $this->slug((string) $this->spec['hostname']);
    }

    /** @return list<string> */
    private function nixPackages(): array
    {
        $pkgs = [];
        foreach ((array) ($this->spec['packages'] ?? []) as $attr) {
            $attr = trim((string) $attr);
            if ($attr !== '' && Catalog::isValidAttr($attr)) {
                $pkgs[$attr] = true;
            }
        }
        // Toujours utiles dans un conteneur (pas de navigateur forcé)
        foreach (['vim', 'git', 'curl', 'htop'] as $extra) {
            if (!isset($pkgs[$extra])) {
                $pkgs[$extra] = true;
            }
        }
        // open-vm-tools inutile dans un conteneur
        unset($pkgs['open-vm-tools']);
        return array_keys($pkgs);
    }

    private function flake(): string
    {
        $s = $this->spec;
        $release = (string) ($s['release'] ?? '26.05');
        $branch = $release === 'unstable' ? 'nixos-unstable' : 'nixos-' . $release;
        $image = $this->imageName();
        $user = $this->nixStr((string) $s['user']['name']);
        $tzEnv = $this->nixStr('TZ=' . (string) $s['timezone']);
        $vncUserEnv = $this->nixStr('VNC_USER=' . (string) $s['user']['name']);
        $homeEnv = '"HOME=/home/${userName}"';
        $userEnv = '"USER=${userName}"';
        $projectEnv = $this->nixStr('NIXBUILDER_PROJECT=' . (string) $s['name']);
        $desktop = (string) ($s['desktop'] ?? 'none');
        $pkgs = $this->nixPackages();
        $pkgLines = $pkgs === []
            ? '          # (aucun paquet utilisateur)'
            : implode("\n", array_map(static fn ($p) => '          ' . $p, $pkgs));

        $allowUnfree = !empty($s['allowUnfree']) ? 'true' : 'false';
        $deNote = $desktop === 'none'
            ? 'headless + terminal (openbox)'
            : 'XFCE (conteneur) — OVA garde ' . (Catalog::DESKTOPS[$desktop] ?? $desktop);

        return <<<NIX
{
  description = {$this->nixStr('NIX Builder — Docker/Kasm : ' . $s['name'])};

  inputs.nixpkgs.url = "github:NixOS/nixpkgs/{$branch}";

  outputs = { self, nixpkgs }:
    let
      system = "x86_64-linux";
      pkgs = import nixpkgs {
        inherit system;
        config.allowUnfree = {$allowUnfree};
      };
      kasmvnc = pkgs.callPackage ./kasmvnc.nix { };
      userName = {$user};
      appPackages = with pkgs; [
{$pkgLines}
      ];
      desktopPackages = with pkgs; [
        # Bureau pour KasmVNC ({$deNote})
        xfce4-session
        xfce4-panel
        xfce4-settings
        xfce4-terminal
        xfdesktop
        xfwm4
        mousepad
        dbus
        xdg-utils
        shared-mime-info
        hicolor-icon-theme
        adwaita-icon-theme
        noto-fonts
        xauth
        xsetroot
        xterm
        openbox
      ];
      startDesktop = pkgs.writeShellApplication {
        name = "start-desktop";
        runtimeInputs = [ kasmvnc pkgs.bashInteractive pkgs.coreutils pkgs.dbus ]
          ++ desktopPackages ++ appPackages;
        text = builtins.replaceStrings [ "#!/usr/bin/env bash\n" ] [ "" ] (builtins.readFile ./start-desktop.sh);
      };
      contents = pkgs.buildEnv {
        name = "nixbuilder-desktop-env";
        paths = [
          pkgs.bashInteractive
          pkgs.coreutils
          pkgs.findutils
          pkgs.gnugrep
          pkgs.gnused
          pkgs.gawk
          pkgs.shadow
          pkgs.su
          pkgs.tzdata
          kasmvnc
          startDesktop
        ] ++ desktopPackages ++ appPackages;
        pathsToLink = [ "/bin" "/share" "/etc" "/lib" ];
      };
    in {
      packages.\${system} = {
        default = self.packages.\${system}.docker;
        docker = pkgs.dockerTools.buildLayeredImage {
          name = "{$image}";
          tag = "latest";
          created = "now";
          maxLayers = 120;
          contents = [ contents ];
          fakeRootCommands = ''
            #!\${pkgs.runtimeShell}
            set -e
            \${pkgs.dockerTools.shadowSetup}
            groupadd -r ssl-cert || true
            useradd -m -u 1000 -g users -G ssl-cert -s /bin/bash \${userName} || true
            mkdir -p /tmp /tmp/.X11-unix /home/\${userName}/.vnc /home/\${userName}/.config
            chmod 1777 /tmp /tmp/.X11-unix
            chown -R 1000:100 /home/\${userName}
          '';
          config = {
            User = "1000:100";
            Env = [
              {$homeEnv}
              {$userEnv}
              {$tzEnv}
              "LANG=C.UTF-8"
              "DISPLAY=:1"
              "VNC_PORT=8443"
              "VNC_RESOLUTION=1280x720"
              {$vncUserEnv}
              "PATH=/bin"
              {$projectEnv}
            ];
            Entrypoint = [ "/bin/start-desktop" ];
            ExposedPorts = { "8443/tcp" = { }; };
            Volumes = { "/home/\${userName}" = { }; };
          };
        };
      };
    };
}
NIX;
    }

    private function kasmvncNix(): string
    {
        $ver = self::KASMVNC_VERSION;
        $sha = self::KASMVNC_SHA256;
        return <<<NIX
{ lib
, stdenv
, callPackage
, fetchurl
, autoPatchelfHook
, dpkg
, makeWrapper
, libpng
, zlib
, libunwind
, pixman
, libXfont2
, libXau
, systemd
, libGL
, mesa
, libwebp
, openssl
, freetype
, libbsd
, libmd
, libxshmfence
, libxdmcp
, libx11
, libxext
, libxtst
, libxrandr
, libxcursor
, libxfixes
, xkbcomp
, xkeyboard_config
}:

let
  libcryptCompat = callPackage ./libcrypt-compat.nix { };
in
stdenv.mkDerivation rec {
  pname = "kasmvnc";
  version = "{$ver}";

  src = fetchurl {
    url = "https://github.com/kasmtech/KasmVNC/releases/download/v\${version}/kasmvncserver_bookworm_\${version}_amd64.deb";
    hash = "{$sha}";
  };

  nativeBuildInputs = [ autoPatchelfHook dpkg makeWrapper ];

  buildInputs = [
    libpng zlib libunwind pixman libXfont2 libXau systemd libxshmfence libxdmcp
    libGL mesa libwebp openssl libcryptCompat freetype libbsd libmd
    stdenv.cc.cc.lib libx11 libxext libxtst libxrandr libxcursor libxfixes
  ];

  unpackPhase = ''
    dpkg-deb -x \$src .
  '';

  installPhase = ''
    runHook preInstall
    mkdir -p \$out/bin \$out/etc/kasmvnc \$out/share/kasmvnc
    cp usr/bin/Xkasmvnc \$out/bin/
    cp usr/bin/kasmvncpasswd \$out/bin/
    cp usr/bin/kasmxproxy \$out/bin/ 2>/dev/null || true
    cp usr/bin/kasmvncconfig \$out/bin/ 2>/dev/null || true
    cp -r usr/share/kasmvnc/www \$out/share/kasmvnc/
    cp etc/kasmvnc/kasmvnc.yaml \$out/etc/kasmvnc/ 2>/dev/null || true
    cp usr/share/kasmvnc/kasmvnc_defaults.yaml \$out/share/kasmvnc/ 2>/dev/null || true
    if [ -f \$out/share/kasmvnc/kasmvnc_defaults.yaml ]; then
      substituteInPlace \$out/share/kasmvnc/kasmvnc_defaults.yaml \\
        --replace-quiet '/usr/share/kasmvnc/www' "\$out/share/kasmvnc/www" || true
    fi
    runHook postInstall
  '';

  postFixup = ''
    wrapProgram \$out/bin/Xkasmvnc \\
      --set XKB_RULES_DIR "\${xkeyboard_config}/share/X11/xkb/rules" \\
      --set XKB_BASE_DIR "\${xkeyboard_config}/share/X11/xkb" \\
      --prefix PATH : "\${lib.makeBinPath [ xkbcomp ]}"
  '';

  meta = with lib; {
    description = "KasmVNC — serveur VNC web moderne";
    homepage = "https://github.com/kasmtech/KasmVNC";
    license = licenses.gpl2Plus;
    platforms = [ "x86_64-linux" ];
  };
}
NIX;
    }

    private function libcryptNix(): string
    {
        $sha = self::LIBCRYPT_SHA256;
        return <<<NIX
{ stdenv, fetchurl, dpkg }:

# libcrypt.so.1 (ABI legacy) requis par les binaires KasmVNC Bookworm.
stdenv.mkDerivation rec {
  pname = "libcrypt-compat";
  version = "4.4.33-2";

  src = fetchurl {
    url = "https://deb.debian.org/debian/pool/main/libx/libxcrypt/libcrypt1_\${version}_amd64.deb";
    hash = "{$sha}";
  };

  nativeBuildInputs = [ dpkg ];

  unpackPhase = ''
    dpkg-deb -x \$src .
  '';

  installPhase = ''
    mkdir -p \$out/lib
    cp -a lib/x86_64-linux-gnu/libcrypt.so.* \$out/lib/
  '';
}
NIX;
    }

    private function startDesktop(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# NIX Builder — démarre KasmVNC + bureau XFCE (accès navigateur).
# Inspiré des images Kasm / projets Nix+KasmVNC communautaires.
set -euo pipefail

VNC_PORT="${VNC_PORT:-8443}"
VNC_RESOLUTION="${VNC_RESOLUTION:-1280x720}"
VNC_COL_DEPTH="${VNC_COL_DEPTH:-24}"
DISPLAY="${DISPLAY:-:1}"
DISPLAY_NUM="${DISPLAY#:}"
VNC_USER="${VNC_USER:-${USER:-user}}"
VNC_PW="${VNC_PW:-password}"
AUTH_MODE="${AUTH_MODE:-basic}"
HOME="${HOME:-/home/${VNC_USER}}"
export HOME DISPLAY XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/tmp/runtime-user}"

mkdir -p "$XDG_RUNTIME_DIR" /tmp/.X11-unix "$HOME/.vnc" "$HOME/.config"
chmod 700 "$XDG_RUNTIME_DIR" || true
rm -f "/tmp/.X${DISPLAY_NUM}-lock" "/tmp/.X11-unix/X${DISPLAY_NUM}"

echo "=== NIX Builder desktop (KasmVNC) ==="
echo "Projet : ${NIXBUILDER_PROJECT:-unknown}"
echo "User   : ${VNC_USER}"
echo "Port   : ${VNC_PORT}"
echo "Res    : ${VNC_RESOLUTION}"

AUTH_ARGS=()
if [ "${AUTH_MODE}" = "none" ]; then
  AUTH_ARGS=(-DisableBasicAuth)
  echo "Auth   : désactivée (dev only)"
else
  rm -f "$HOME/.kasmpasswd"
  printf '%s\n%s\n' "${VNC_PW}" "${VNC_PW}" | kasmvncpasswd -u "${VNC_USER}" -w -r >/dev/null
  echo "Auth   : HTTP Basic (${VNC_USER})"
fi

export XAUTHORITY="$HOME/.Xauthority"
touch "$XAUTHORITY"

DBUS_BIN="$(command -v dbus-run-session)"
cat > "$HOME/.vnc/xstartup" <<EOF
#!/usr/bin/env bash
set -euo pipefail
export DISPLAY=${DISPLAY}
if command -v startxfce4 >/dev/null 2>&1; then
  exec ${DBUS_BIN} startxfce4
elif command -v openbox >/dev/null 2>&1; then
  exec ${DBUS_BIN} openbox-session
else
  exec ${DBUS_BIN} xterm
fi
EOF
chmod +x "$HOME/.vnc/xstartup"

XKASMVNC="$(command -v Xkasmvnc)"
KASMVNC_ROOT="$(dirname "$(dirname "${XKASMVNC}")")"
WWW_DIR="${KASMVNC_ROOT}/share/kasmvnc/www"
if [ ! -d "${WWW_DIR}" ]; then
  WWW_DIR="$(dirname "${XKASMVNC}")/../share/kasmvnc/www"
fi

echo "Starting Xkasmvnc..."
"${XKASMVNC}" "${DISPLAY}" \
  -geometry "${VNC_RESOLUTION}" \
  -depth "${VNC_COL_DEPTH}" \
  -websocketPort "${VNC_PORT}" \
  -interface 0.0.0.0 \
  -SecurityTypes None \
  -RawKeyboard \
  -AlwaysShared \
  -httpd "${WWW_DIR}" \
  -auth "${XAUTHORITY}" \
  -nopn \
  -publicIP 127.0.0.1 \
  "${AUTH_ARGS[@]}" \
  +extension GLX \
  +extension RANDR \
  +extension RENDER &
XPID=$!

echo "Waiting for X..."
for _ in $(seq 1 40); do
  if [ -e "/tmp/.X11-unix/X${DISPLAY_NUM}" ]; then
    break
  fi
  sleep 0.25
done
if [ ! -e "/tmp/.X11-unix/X${DISPLAY_NUM}" ]; then
  echo "ERROR: Xkasmvnc n'a pas démarré" >&2
  wait "${XPID}" || true
  exit 1
fi

command -v xsetroot >/dev/null 2>&1 && xsetroot -solid "#1a1b26" || true

echo "Starting desktop..."
"$HOME/.vnc/xstartup" &
DE_PID=$!

echo ""
echo "============================================"
echo " Bureau prêt — ouvrez https://localhost:${VNC_PORT}"
echo " (ou http:// selon certificats / proxy Kasm)"
echo "============================================"
echo ""

wait "${XPID}"
exit $?
BASH;
    }

    private function compose(int $port): string
    {
        $image = $this->imageName();
        $name = $this->slug((string) $this->spec['hostname']);
        $user = (string) $this->spec['user']['name'];
        return <<<YAML
# Export Docker/Kasm — {$this->spec['name']}
# Construire d'abord : ./build.sh
name: {$name}

services:
  desktop:
    image: {$image}:latest
    container_name: {$name}-desktop
    restart: unless-stopped
    ports:
      - "\${VNC_PORT:-{$port}}:8443"
    environment:
      TZ: \${TZ:-Europe/Paris}
      VNC_PORT: "8443"
      VNC_RESOLUTION: \${VNC_RESOLUTION:-1280x720}
      VNC_USER: \${VNC_USER:-{$user}}
      VNC_PW: \${VNC_PW:-password}
      AUTH_MODE: \${AUTH_MODE:-basic}
    shm_size: "512mb"
    volumes:
      - desktop-home:/home/{$user}

volumes:
  desktop-home:
YAML;
    }

    private function envExample(int $port): string
    {
        $user = (string) $this->spec['user']['name'];
        return <<<ENV
# Copier en .env
VNC_PORT={$port}
VNC_RESOLUTION=1280x720
VNC_USER={$user}
VNC_PW=change-moi
AUTH_MODE=basic
TZ={$this->spec['timezone']}
ENV;
    }

    private function buildScript(): string
    {
        $image = $this->imageName();
        return <<<'BASH'
#!/usr/bin/env bash
# Construit l'image OCI via Nix, puis la charge dans Docker.
set -euo pipefail
cd "$(dirname "$0")"

if ! command -v nix >/dev/null 2>&1; then
  echo "Nix est requis : https://nixos.org/download/" >&2
  exit 1
fi
if ! command -v docker >/dev/null 2>&1; then
  echo "Docker est requis pour charger l'image." >&2
  exit 1
fi

echo "→ nix build .#docker"
nix --extra-experimental-features "nix-command flakes" build .#docker -o result-docker

echo "→ docker load"
docker load < result-docker

echo ""
echo "Image chargée. Ensuite :"
echo "  cp -n .env.example .env"
echo "  docker compose up -d"
echo "  # navigateur → https://localhost:\${VNC_PORT:-8443}"
BASH;
    }

    private function readme(int $port): string
    {
        $s = $this->spec;
        $name = $s['name'];
        $host = $s['hostname'];
        $image = $this->imageName();
        $desktop = Catalog::DESKTOPS[$s['desktop'] ?? 'none'] ?? ($s['desktop'] ?? 'none');
        $pkgs = implode(', ', $this->nixPackages());
        $kasmVer = self::KASMVNC_VERSION;

        return <<<MD
# {$name} — export Docker / Kasm (Nix + KasmVNC)

Généré par **NIX Builder** à partir du projet OS **{$host}**.

## Deux artefacts du même projet

| Sortie | Contenu |
| --- | --- |
| **OVA** (build NIX Builder) | NixOS complet, bureau **{$desktop}**, systemd, VMware |
| **Ce ZIP** | Image OCI construite avec **Nix** : paquets Nix + **XFCE** + **[KasmVNC](https://github.com/kasmtech/KasmVNC) {$kasmVer}** |

Le conteneur n’est **pas** un NixOS systemd complet (compromis pour Docker/Kasm), mais les **mêmes paquets Nix** et l’intention du projet.

## Prérequis

- [Nix](https://nixos.org/download/) avec flakes (`nix-command` + `flakes`)
- Docker + Compose
- ~10–30 Go libres (premier build télécharge nixpkgs + KasmVNC)

## Construction

```bash
chmod +x build.sh start-desktop.sh
./build.sh
cp .env.example .env   # changer VNC_PW
docker compose up -d
```

Bureau web : **https://localhost:{$port}** (Basic Auth = `VNC_USER` / `VNC_PW`).

Image Docker : `{$image}:latest`

## Fichiers

| Fichier | Rôle |
| --- | --- |
| `flake.nix` | Image OCI (`dockerTools.buildLayeredImage`) |
| `kasmvnc.nix` | Paquet KasmVNC depuis le `.deb` Bookworm officiel |
| `libcrypt-compat.nix` | `libcrypt.so.1` pour les binaires Kasm |
| `start-desktop.sh` | Entrypoint : Xkasmvnc + XFCE |
| `build.sh` | `nix build` + `docker load` |
| `docker-compose.yml` | Publication du port web |

Paquets inclus : {$pkgs}

## Kasm Workspaces

1. Construire et pousser l’image vers votre registry (`docker tag` / `docker push`)
2. Dans Kasm : Workspace → image `{$image}:latest` (ou votre tag registry)
3. Port / chemin selon votre config Kasm (souvent proxy vers le websocket KasmVNC)

Pour une intégration « native » maximale (audio, uploads…), les images `kasmweb/core-*` restent le chemin officiel Kasm ; ce ZIP privilégie **Nix + KasmVNC** comme demandé.

## Notes

- Bureau conteneur = **XFCE** (léger, fiable avec KasmVNC). Plasma/GNOME restent sur l’OVA.
- `shm_size: 512mb` est recommandé (déjà dans le compose).
- Architecture : **x86_64-linux** uniquement (binaire KasmVNC amd64).
MD;
    }

    private function slug(string $value): string
    {
        $v = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? 'app');
        return trim($v, '-') ?: 'app';
    }

    private function nixStr(string $value): string
    {
        return '"' . str_replace(['\\', '"', '${'], ['\\\\', '\\"', '\\${'], $value) . '"';
    }
}
