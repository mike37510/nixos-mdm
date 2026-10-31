# Généré par Linux MDM — ne pas éditer à la main
# Mis à jour : 2026-10-16T17:01:35+00:00
# Fréquence  : Toutes les 2 minutes (tests)
# Hébergé sur : https://nix.borninthe80s.fr/mdm/generated/profiles/comptabilit/parc.nix
#
# Ce fichier est téléchargé automatiquement par chaque client.
# Ajoutez / retirez des logiciels dans l’UI Linux MDM, enregistrez :
# au prochain cycle les postes les installeront.
{ config, pkgs, lib, ... }:

{
  # Requis pour vscode / brave / onlyoffice, etc.
  nixpkgs.config.allowUnfree = lib.mkDefault true;

  environment.systemPackages = with pkgs; [
    vim
    git
    htop
    curl
    onlyoffice-desktopeditors
    brave
    vscode
  ]
  ++ (let
    linuxMdmIcons = pkgs.runCommand "linux-mdm-icons" {
      nativeBuildInputs = [ pkgs.coreutils ];
    } ''
      mkdir -p $out/share/icons/hicolor/512x512/apps
      base64 -d > $out/share/icons/hicolor/512x512/apps/linux-mdm-centre.png <<'LOGOB64'
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
LOGOB64
    '';
  in [
    linuxMdmIcons
    # Application autonome : Chromium embarqué (pas besoin de navigateur système).
    (pkgs.writeShellApplication {
      name = "linux-mdm-centre";
      runtimeInputs = [ pkgs.coreutils ];
      text = ''
        set -euo pipefail
        AUTH=/etc/nixos/mdm.auth
        if [ ! -r "$AUTH" ]; then
          echo "Linux MDM : impossible de lire $AUTH (droits ou fichier manquant)." >&2
          echo "Corrigez avec : sudo chmod 644 /etc/nixos/mdm.auth" >&2
          exit 1
        fi
        # shellcheck disable=SC1090
        . "$AUTH"
        HUB="''${MDM_URL:-https://nix.borninthe80s.fr/mdm}"
        TOKEN="''${MDM_DEVICE_TOKEN:-}"
        if [ -z "$TOKEN" ]; then
          echo "Linux MDM : MDM_DEVICE_TOKEN manquant. Relancez install-on-client.sh." >&2
          exit 1
        fi
        URL="$HUB/software-center?token=$TOKEN"
        PROFILE="''${XDG_CACHE_HOME:-$HOME/.cache}/linux-mdm-centre"
        mkdir -p "$PROFILE"
        # Moteur web inclus dans le paquet — aucun navigateur n’est requis sur le poste
        exec ${pkgs.chromium}/bin/chromium \
          --app="$URL" \
          --user-data-dir="$PROFILE" \
          --class=LinuxMDMCentre \
          --name="Centre Logiciels" \
          --window-size=1100,750 \
          --no-first-run \
          --no-default-browser-check \
          --disable-sync \
          --disable-features=TranslateUI,ChromeWhatsNewUI,BlockInsecurePrivateNetworkRequests
      '';
    })
    (pkgs.makeDesktopItem {
      name = "linux-mdm-centre";
      desktopName = "Centre Logiciels";
      genericName = "Software Center";
      comment = "Centre Logiciels Linux MDM — catalogue DSI";
      exec = "linux-mdm-centre";
      icon = "linux-mdm-centre";
      categories = [ "System" "Settings" "Utility" ];
      startupWMClass = "LinuxMDMCentre";
      terminal = false;
    })
  ])
  ++ [
    (pkgs.writeShellApplication {
      name = "linux-mdm-sync-now";
      runtimeInputs = [ pkgs.systemd pkgs.coreutils ];
      text = ''
        set -euo pipefail
        systemctl start nix-builder-mdm-sync.service
        echo "Linux MDM : synchronisation démarrée."
      '';
    })
    (pkgs.makeDesktopItem {
      name = "linux-mdm-sync-now";
      desktopName = "Linux MDM — Sync maintenant";
      comment = "Déclenche une synchronisation MDM immédiate";
      exec = "linux-mdm-sync-now %u";
      noDisplay = true;
      mimeTypes = [ "x-scheme-handler/linux-mdm" ];
      categories = [ "System" ];
      terminal = false;
    })
  ];

  # SSH activé par défaut (parc / accès distant)
  services.openssh.enable = true;

  # Pas de Firefox par défaut (écrase la config de base si besoin)
  programs.firefox.enable = lib.mkForce false;

  # open-vm-tools (VMware) — utile pour les VM de test
  virtualisation.vmware.guest.enable = true;

  # Tire périodiquement parc.nix depuis CE hub, puis rebuild.
  systemd.services.nix-builder-mdm-sync = {
    description = "Linux MDM — sync config parc + rebuild";
    after = [ "network-online.target" ];
    wants = [ "network-online.target" ];
    serviceConfig.Type = "oneshot";
    path = [ pkgs.curl pkgs.nix pkgs.bash pkgs.coreutils pkgs.git ];
    environment = config.nix.envVars // {
      # Requis sous systemd (pas de NIX_PATH de session)
      NIX_PATH = lib.concatStringsSep ":" config.nix.nixPath;
      HOME = "/root";
    };
    script = ''
      set -euo pipefail
      if [ -f /etc/nixos/mdm.auth ]; then
        # shellcheck disable=SC1091
        . /etc/nixos/mdm.auth
        # Centre logiciels (utilisateur) doit pouvoir lire le token appareil
        chmod 644 /etc/nixos/mdm.auth || true
      fi
      HUB="''${MDM_URL:-https://nix.borninthe80s.fr/mdm}"
      DEST=/etc/nixos/parc.nix
      TMP="$(mktemp)"
      # Profil dynamique : si token présent, le serveur décide du profil assigné.
      if [ -n "''${MDM_DEVICE_TOKEN:-}" ]; then
        curl -fsSL "$HUB/parc-for-device.php?token=$MDM_DEVICE_TOKEN" -o "$TMP"
      else
        curl -fsSL "$HUB/generated/profiles/comptabilit/parc.nix" -o "$TMP"
      fi
      mv "$TMP" "$DEST"
      chmod 644 "$DEST"
      echo "Linux MDM : parc.nix mis à jour, nixos-rebuild switch …"
      if ! ${config.system.build.nixos-rebuild}/bin/nixos-rebuild switch; then
        echo "Linux MDM : ÉCHEC nixos-rebuild — voir journalctl -u nix-builder-mdm-sync.service" >&2
        exit 1
      fi
    '';
  };

  systemd.timers.nix-builder-mdm-sync = {
    description = "Linux MDM — planification (Toutes les 2 minutes (tests))";
    wantedBy = [ "timers.target" ];
    timerConfig = {
      OnCalendar = "*:0/2";
      Persistent = true;
      RandomizedDelaySec = "0";
      Unit = "nix-builder-mdm-sync.service";
    };
  };

  # Agent local : le Centre logiciels (HTTPS) peut demander une sync immédiate.
  systemd.services.linux-mdm-local-agent = {
    description = "Linux MDM — agent local (sync depuis Logiciels)";
    wantedBy = [ "multi-user.target" ];
    after = [ "network.target" ];
    path = [ pkgs.systemd pkgs.coreutils pkgs.python3 ];
    serviceConfig = {
      Type = "simple";
      Restart = "on-failure";
      RestartSec = "3";
    };
    script = ''
      exec ${pkgs.python3}/bin/python3 - <<'PY'
      import json, subprocess
      from http.server import BaseHTTPRequestHandler, HTTPServer

      PORT = 17380
      AUTH_FILE = "/etc/nixos/mdm.auth"

      def load_token():
          token = ""
          try:
              with open(AUTH_FILE, encoding="utf-8") as fh:
                  for line in fh:
                      line = line.strip()
                      if line.startswith("MDM_DEVICE_TOKEN="):
                          token = line.split("=", 1)[1].strip().strip("'\"")
          except OSError:
              pass
          return token

      class Handler(BaseHTTPRequestHandler):
          def log_message(self, fmt, *args):
              return

          def cors(self):
              origin = self.headers.get("Origin", "*")
              self.send_header("Access-Control-Allow-Origin", origin)
              self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
              self.send_header("Access-Control-Allow-Headers", "Authorization, Content-Type")
              self.send_header("Access-Control-Allow-Private-Network", "true")
              self.send_header("Vary", "Origin")

          def do_OPTIONS(self):
              self.send_response(204)
              self.cors()
              self.end_headers()

          def authorized(self):
              expected = load_token()
              if not expected:
                  return False
              auth = self.headers.get("Authorization", "")
              if auth.startswith("Bearer "):
                  return auth[7:].strip() == expected
              return False

          def do_GET(self):
              path = self.path.split("?", 1)[0]
              if path == "/health":
                  self.send_response(200)
                  self.cors()
                  self.send_header("Content-Type", "application/json")
                  self.end_headers()
                  self.wfile.write(b'{"ok":true}')
                  return
              self.send_response(404)
              self.cors()
              self.end_headers()

          def do_POST(self):
              path = self.path.split("?", 1)[0]
              if path != "/sync-now":
                  self.send_response(404)
                  self.cors()
                  self.end_headers()
                  return
              if not self.authorized():
                  self.send_response(401)
                  self.cors()
                  self.send_header("Content-Type", "application/json")
                  self.end_headers()
                  self.wfile.write(b'{"error":"unauthorized"}')
                  return
              try:
                  subprocess.run(
                      ["systemctl", "start", "nix-builder-mdm-sync.service"],
                      check=True,
                      capture_output=True,
                      text=True,
                  )
                  body = b'{"ok":true,"message":"sync started"}'
                  code = 200
              except Exception as exc:
                  body = json.dumps({"error": str(exc)}).encode()
                  code = 500
              self.send_response(code)
              self.cors()
              self.send_header("Content-Type", "application/json")
              self.end_headers()
              self.wfile.write(body)

      HTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
      PY
    '';
  };

  # Permet à un utilisateur de session de lancer la sync (protocole / CLI)
  # sans mot de passe — uniquement pour cette unité et le verbe start.
  security.polkit.extraConfig = ''
    polkit.addRule(function(action, subject) {
      if (action.id == "org.freedesktop.systemd1.manage-units") {
        var unit = action.lookup("unit");
        var verb = action.lookup("verb");
        if (unit == "nix-builder-mdm-sync.service" && verb == "start" && subject.active) {
          return polkit.Result.YES;
        }
      }
    });
  '';
  # Optionnel : reboot autorisé lors d’un rebuild qui le demande
  system.autoUpgrade.allowReboot = false;
}
