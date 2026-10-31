<?php

declare(strict_types=1);

final class NixGenerator
{
    public function __construct(
        private string $outputDir,
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     * @param array{baseUrl?: string, profileSlug?: string, isDefault?: bool} $opts
     * @return array<string, string>
     */
    public function generate(array $settings, array $opts = []): array
    {
        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0775, true) && !is_dir($this->outputDir)) {
            throw new RuntimeException('Impossible de créer le dossier generated.');
        }

        $baseUrl = rtrim((string) ($opts['baseUrl'] ?? $settings['hubUrl'] ?? ''), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://VOTRE-SERVEUR/mdm';
        }
        $settings['hubUrl'] = $baseUrl;

        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($opts['profileSlug'] ?? 'default'))) ?: 'default';
        $isDefault = !empty($opts['isDefault']) || $slug === 'default';
        $parcPathSuffix = $isDefault ? 'generated/parc.nix' : 'generated/profiles/' . $slug . '/parc.nix';
        $settings['_parcPathSuffix'] = $parcPathSuffix;
        $settings['_profileSlug'] = $slug;
        if (!isset($settings['_profileVersion'])) {
            $settings['_profileVersion'] = (int) ($opts['profileVersion'] ?? 0);
        }

        $module = str_replace("\r\n", "\n", $this->renderModule($settings));
        $flake = str_replace("\r\n", "\n", $this->renderFlake());
        $installScript = str_replace("\r\n", "\n", $this->renderInstallScript($settings, $baseUrl, $parcPathSuffix, $slug));

        $profileDir = $this->outputDir . DIRECTORY_SEPARATOR . 'profiles' . DIRECTORY_SEPARATOR . $slug;
        if (!is_dir($profileDir) && !mkdir($profileDir, 0775, true) && !is_dir($profileDir)) {
            throw new RuntimeException('Impossible de créer le dossier du profil.');
        }

        $paths = [
            'parc' => $profileDir . DIRECTORY_SEPARATOR . 'parc.nix',
            'installScript' => $profileDir . DIRECTORY_SEPARATOR . 'install-on-client.sh',
        ];

        foreach ([
            $paths['parc'] => $module,
            $paths['installScript'] => $installScript,
        ] as $path => $contents) {
            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException('Écriture impossible : ' . basename($path));
            }
        }
        chmod($paths['installScript'], 0755);

        // Profil par défaut aussi à la racine generated/ (rétrocompat clients existants)
        if ($isDefault) {
            $rootPaths = [
                'parc' => $this->outputDir . DIRECTORY_SEPARATOR . 'parc.nix',
                'autoUpgrade' => $this->outputDir . DIRECTORY_SEPARATOR . 'auto-upgrade.nix',
                'configuration' => $this->outputDir . DIRECTORY_SEPARATOR . 'configuration.nix',
                'flake' => $this->outputDir . DIRECTORY_SEPARATOR . 'flake.nix',
                'installScript' => $this->outputDir . DIRECTORY_SEPARATOR . 'install-on-client.sh',
            ];
            foreach ([
                $rootPaths['parc'] => $module,
                $rootPaths['autoUpgrade'] => $module,
                $rootPaths['configuration'] => $module,
                $rootPaths['flake'] => $flake,
                $rootPaths['installScript'] => $installScript,
            ] as $path => $contents) {
                if (file_put_contents($path, $contents) === false) {
                    throw new RuntimeException('Écriture impossible : ' . basename($path));
                }
            }
            chmod($rootPaths['installScript'], 0755);
            $tarballPath = $this->outputDir . DIRECTORY_SEPARATOR . 'flake.tar.gz';
            $this->buildTarball($tarballPath, [
                'flake.nix' => $flake,
                'configuration.nix' => $module,
                'parc.nix' => $module,
                'auto-upgrade.nix' => $module,
            ]);
            $paths['tarball'] = $tarballPath;
            $paths = array_merge($rootPaths, $paths);
        }

        return $paths;
    }

    /**
     * Génère uniquement le contenu Nix du module (sans écrire sur disque).
     * @param array<string, mixed> $settings
     */
    public function renderModuleString(array $settings): string
    {
        return str_replace("\r\n", "\n", $this->renderModule($settings));
    }

    /** @param array<string, mixed> $settings */
    private function renderModule(array $settings): string
    {
        $enable = !empty($settings['autoUpgradeEnable']);
        $allowReboot = !empty($settings['allowReboot']) ? 'true' : 'false';
        $dates = (string) $settings['dates'];
        $operation = (string) $settings['operation'];
        $delay = (string) ($settings['randomizedDelaySec'] ?? '0');
        $hubUrl = rtrim((string) ($settings['hubUrl'] ?? ''), '/');
        $updatedAt = (string) ($settings['updatedAt'] ?? 'n/a');
        $label = (string) ($settings['datesLabel'] ?? $dates);
        /** @var list<string> $packages */
        $packages = array_values(array_filter(array_map('strval', (array) ($settings['packages'] ?? []))));

        // Toujours inclure le lanceur Centre logiciels (sauf désactivation explicite).
        $includeCenter = !array_key_exists('_includeSoftwareCenter', $settings) || !empty($settings['_includeSoftwareCenter']);

        $pkgLines = $packages === []
            ? '    # (aucun paquet métier pour l’instant)'
            : implode("\n", array_map(static fn (string $p) => '    ' . $p, $packages));

        $centerBlock = $includeCenter ? $this->renderSoftwareCenterBlock($hubUrl) : '';
        $syncTools = $enable ? $this->renderSyncToolsAppend() : '';
        $syncAgent = $enable ? $this->renderSyncAgentBlock() : '';
        $systemBlock = $this->renderSystemOptions($settings);
        $profileVer = (int) ($settings['_profileVersion'] ?? 0);
        $profileSlug = (string) ($settings['_profileSlug'] ?? 'default');

        $datesNix = $this->nixString($dates);
        $delayNix = $this->nixString($delay);
        // Échapper pour Nix ''…'' : ''${ → ${ bash ; \$ → $ pour PHP heredoc.
        $timerBlock = $enable ? <<<NIX

  # Tire périodiquement parc.nix depuis CE hub, puis rebuild (+ rollback si échec).
  systemd.services.nix-builder-mdm-sync = {
    description = "Linux MDM — sync config parc + rebuild";
    after = [ "network-online.target" ];
    wants = [ "network-online.target" ];
    serviceConfig.Type = "oneshot";
    path = [ pkgs.curl pkgs.nix pkgs.bash pkgs.coreutils pkgs.git pkgs.gnugrep pkgs.gnused pkgs.python3 pkgs.iproute2 ];
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
      HUB="''\${MDM_URL:-{$hubUrl}}"
      DEST=/etc/nixos/parc.nix
      TMP="\$(mktemp)"
      LOG="\$(mktemp)"
      # Profil dynamique : si token présent, le serveur décide du profil assigné.
      if [ -n "''\${MDM_DEVICE_TOKEN:-}" ]; then
        curl -fsSL "\$HUB/parc-for-device.php?token=\$MDM_DEVICE_TOKEN" -o "\$TMP"
      else
        curl -fsSL "\$HUB/{$settings['_parcPathSuffix']}" -o "\$TMP"
      fi
      mv "\$TMP" "\$DEST"
      chmod 644 "\$DEST"
      PROF_VER="\$(grep -E '^# MDM_PROFILE_VERSION=' "\$DEST" | head -1 | cut -d= -f2 || true)"
      PROF_SLUG="\$(grep -E '^# MDM_PROFILE_SLUG=' "\$DEST" | head -1 | cut -d= -f2 || true)"
      GEN_BEFORE="\$(readlink -f /nix/var/nix/profiles/system 2>/dev/null | grep -oE '[0-9]+\$' || echo 0)"
      # Hostname dérivé de la MAC : nixos-<12 hex>
      MAC="\$(cat /sys/class/net/*/address 2>/dev/null | grep -vi '^00:00:00:00:00:00\$' | head -1 || true)"
      MAC_HEX="\$(printf '%s' "\$MAC" | tr -d ':-' | tr 'A-F' 'a-f')"
      if [ "''\${#MAC_HEX}" -eq 12 ]; then
        HN="nixos-\$MAC_HEX"
        # mkForce : prioritaire sur networking.hostName de configuration.nix
        printf '%s\\n' "{ lib, ... }: { networking.hostName = lib.mkForce \"\$HN\"; }" > /etc/nixos/mdm-hostname.nix
        chmod 644 /etc/nixos/mdm-hostname.nix
        CFG=/etc/nixos/configuration.nix
        if [ -f "\$CFG" ] && ! grep -qE '\\./mdm-hostname\\.nix' "\$CFG"; then
          if grep -qE 'imports[[:space:]]*=' "\$CFG"; then
            sed -i "/imports[[:space:]]*=/,/\\[/{
              /\\[/ s/\\[/[ .\\/mdm-hostname.nix /
            }" "\$CFG" || true
          fi
        fi
      fi
      mdm_report() {
        local st="\$1" err="\$2" rb="\$3" gen="\$4"
        [ -z "''\${MDM_DEVICE_TOKEN:-}" ] && return 0
        HN_NOW="\$(hostname -s 2>/dev/null || true)"
        IP_NOW="\$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if(\$i==\"src\") print \$(i+1)}' | head -1 || true)"
        ERR_JSON="\$(printf '%s' "\$err" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()[:3500]))' 2>/dev/null || printf '"rebuild failed"')"
        curl -fsS -X POST "\$HUB/client-api.php?action=sync-report&token=\$MDM_DEVICE_TOKEN" \
          -H 'Content-Type: application/json' \
          -d "{\"status\":\"\$st\",\"error\":\$ERR_JSON,\"rolled_back\":\$rb,\"nix_generation\":\$gen,\"profile_version\":''\${PROF_VER:-0},\"profile_slug\":\"''\${PROF_SLUG:-}\",\"hostname\":\"\$HN_NOW\",\"ip\":\"\$IP_NOW\"}" \
          >/dev/null 2>&1 || true
      }
      echo "Linux MDM : parc.nix mis à jour, nixos-rebuild {$operation} (gen \$GEN_BEFORE) …"
      if ! \${config.system.build.nixos-rebuild}/bin/nixos-rebuild {$operation} >"\$LOG" 2>&1; then
        echo "Linux MDM : ÉCHEC nixos-rebuild — tentative de rollback gen \$GEN_BEFORE" >&2
        tail -n 40 "\$LOG" >&2 || true
        RB=0
        if [ "\$GEN_BEFORE" -gt 0 ] 2>/dev/null; then
          if nix-env --profile /nix/var/nix/profiles/system --switch-generation "\$GEN_BEFORE" >>"\$LOG" 2>&1 \\
            && /nix/var/nix/profiles/system/bin/switch-to-configuration switch >>"\$LOG" 2>&1; then
            RB=1
            echo "Linux MDM : rollback OK → génération \$GEN_BEFORE" >&2
          else
            echo "Linux MDM : rollback ÉCHEC — intervention manuelle requise" >&2
          fi
        fi
        ERR_MSG="\$(tail -n 25 "\$LOG" | tr '\\n' ' ' | head -c 2000)"
        mdm_report failed "\$ERR_MSG" "\$RB" "\$GEN_BEFORE"
        rm -f "\$LOG"
        exit 1
      fi
      GEN_AFTER="\$(readlink -f /nix/var/nix/profiles/system 2>/dev/null | grep -oE '[0-9]+\$' || echo 0)"
      mdm_report ok "" 0 "\$GEN_AFTER"
      rm -f "\$LOG"
    '';
  };

  systemd.timers.nix-builder-mdm-sync = {
    description = "Linux MDM — planification ({$label})";
    wantedBy = [ "timers.target" ];
    timerConfig = {
      OnCalendar = {$datesNix};
      Persistent = true;
      RandomizedDelaySec = {$delayNix};
      Unit = "nix-builder-mdm-sync.service";
    };
  };
NIX : "\n  # Sync MDM désactivé (autoUpgradeEnable = false)\n";

        return <<<NIX
# Généré par Linux MDM — ne pas éditer à la main
# Mis à jour : {$updatedAt}
# Fréquence  : {$label}
# Hébergé sur : {$hubUrl}/{$settings['_parcPathSuffix']}
# MDM_PROFILE_SLUG={$profileSlug}
# MDM_PROFILE_VERSION={$profileVer}
#
# Ce fichier est téléchargé automatiquement par chaque client.
# Ajoutez / retirez des logiciels dans l’UI Linux MDM, enregistrez :
# au prochain cycle les postes les installeront.
{ config, pkgs, lib, ... }:

{
  # Requis pour vscode / brave / onlyoffice, etc.
  nixpkgs.config.allowUnfree = lib.mkDefault true;

  environment.systemPackages = with pkgs; [
{$pkgLines}
  ]{$centerBlock}{$syncTools};

  # Pas de Firefox par défaut (écrase la config de base si besoin)
  programs.firefox.enable = lib.mkForce false;

  # open-vm-tools (VMware) — utile pour les VM de test
  virtualisation.vmware.guest.enable = true;
{$systemBlock}
{$timerBlock}
{$syncAgent}
  # Optionnel : reboot autorisé lors d’un rebuild qui le demande
  system.autoUpgrade.allowReboot = {$allowReboot};
}

NIX;
    }

    /** @param array<string, mixed> $settings */
    private function renderSystemOptions(array $settings): string
    {
        $sys = is_array($settings['system'] ?? null)
            ? array_merge(
                [
                    'openssh' => true,
                    'firewall' => false,
                    'printing' => false,
                    'timezone' => 'Europe/Paris',
                    'locale' => 'fr_FR.UTF-8',
                    'proxy' => '',
                ],
                $settings['system']
            )
            : [
                'openssh' => true,
                'firewall' => false,
                'printing' => false,
                'timezone' => 'Europe/Paris',
                'locale' => 'fr_FR.UTF-8',
                'proxy' => '',
            ];

        $ssh = !empty($sys['openssh']) ? 'true' : 'false';
        $fw = !empty($sys['firewall']) ? 'true' : 'false';
        $printing = !empty($sys['printing']) ? 'true' : 'false';
        $tz = $this->nixString((string) $sys['timezone']);
        $locale = (string) $sys['locale'];
        if (!preg_match('/^[A-Za-z]{2}_[A-Za-z]{2}\.UTF-8$/', $locale)) {
            $locale = 'fr_FR.UTF-8';
        }
        $localeNix = $this->nixString($locale);
        $proxy = trim((string) $sys['proxy']);
        $proxyBlock = '';
        if ($proxy !== '') {
            $proxyNix = $this->nixString($proxy);
            $proxyBlock = <<<NIX

  networking.proxy.default = {$proxyNix};
  networking.proxy.noProxy = "127.0.0.1,localhost,::1";
NIX;
        }

        return <<<NIX

  # Options système (profil MDM)
  services.openssh.enable = {$ssh};
  networking.firewall.enable = {$fw};
  services.printing.enable = {$printing};
  time.timeZone = {$tz};
  i18n.defaultLocale = {$localeNix};
{$proxyBlock}
NIX;
    }

    /**
     * CLI + protocole linux-mdm:// pour déclencher une sync immédiate.
     */
    private function renderSyncToolsAppend(): string
    {
        return <<<'NIX'

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
  ]
NIX;
    }

    /**
     * Agent HTTP local (127.0.0.1:17380) + Polkit pour le Centre logiciels.
     */
    private function renderSyncAgentBlock(): string
    {
        return <<<'NIX'

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
      from urllib.parse import urlparse, parse_qs

      PORT = 17380
      AUTH_FILE = "/etc/nixos/mdm.auth"
      UNIT = "nix-builder-mdm-sync.service"

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

      def unit_active():
          try:
              r = subprocess.run(
                  ["systemctl", "is-active", UNIT],
                  capture_output=True, text=True, check=False,
              )
              return (r.stdout or "").strip() == "active"
          except OSError:
              return False

      def journal_lines(n=100):
          try:
              r = subprocess.run(
                  ["journalctl", "-u", UNIT, "-n", str(n), "--no-pager", "-o", "short-iso"],
                  capture_output=True, text=True, check=False,
              )
              out = (r.stdout or "").strip()
              return out.splitlines() if out else []
          except OSError as exc:
              return ["journalctl indisponible: " + str(exc)]

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

          def json_response(self, code, payload):
              body = json.dumps(payload, ensure_ascii=False).encode()
              self.send_response(code)
              self.cors()
              self.send_header("Content-Type", "application/json; charset=utf-8")
              self.send_header("Cache-Control", "no-store")
              self.end_headers()
              self.wfile.write(body)

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
              parsed = urlparse(self.path)
              path = parsed.path
              if path == "/health":
                  self.json_response(200, {"ok": True})
                  return
              if path in ("/sync-status", "/sync-log"):
                  if not self.authorized():
                      self.json_response(401, {"error": "unauthorized"})
                      return
                  active = unit_active()
                  if path == "/sync-status":
                      self.json_response(200, {"ok": True, "active": active, "unit": UNIT})
                      return
                  qs = parse_qs(parsed.query)
                  try:
                      n = max(20, min(300, int((qs.get("n") or ["100"])[0])))
                  except ValueError:
                      n = 100
                  self.json_response(200, {
                      "ok": True,
                      "active": active,
                      "unit": UNIT,
                      "lines": journal_lines(n),
                  })
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
                  self.json_response(401, {"error": "unauthorized"})
                  return
              try:
                  subprocess.run(
                      ["systemctl", "start", UNIT],
                      check=True,
                      capture_output=True,
                      text=True,
                  )
                  self.json_response(200, {"ok": True, "message": "sync started"})
              except Exception as exc:
                  self.json_response(500, {"error": str(exc)})

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
NIX;
    }

    private function renderSoftwareCenterBlock(string $hubUrl): string
    {
        $hub = $hubUrl !== '' ? $hubUrl : 'https://VOTRE-SERVEUR/mdm';
        // Logo embarqué (icône carrée) — pas de fetch réseau pendant nixos-rebuild.
        $logoPath = null;
        try {
            if (!class_exists('AppSettings', false)) {
                $as = dirname(__DIR__) . '/lib/AppSettings.php';
                if (is_file($as)) {
                    require_once $as;
                }
            }
            if (class_exists('AppSettings')) {
                $logoPath = (new AppSettings())->logoFilesystemPath();
            }
        } catch (Throwable $e) {
            $logoPath = null;
        }
        if ($logoPath === null || !is_file($logoPath)) {
            $logoPath = dirname(__DIR__, 2) . '/assets/logo-icon.png';
            if (!is_file($logoPath)) {
                $logoPath = dirname(__DIR__, 2) . '/assets/logo.png';
            }
        }
        // SVG non embarqué en PNG pour l’icône desktop : bascule sur le PNG par défaut
        if (is_file($logoPath) && str_ends_with(strtolower($logoPath), '.svg')) {
            $png = dirname(__DIR__, 2) . '/assets/logo-icon.png';
            $logoPath = is_file($png) ? $png : $logoPath;
        }
        $logoB64 = (is_file($logoPath) && !str_ends_with(strtolower($logoPath), '.svg'))
            ? trim(base64_encode((string) file_get_contents($logoPath)))
            : '';
        $iconExpr = $logoB64 !== ''
            ? <<<NIX
    linuxMdmIcons = pkgs.runCommand "linux-mdm-icons" {
      nativeBuildInputs = [ pkgs.coreutils ];
    } ''
      mkdir -p \$out/share/icons/hicolor/512x512/apps
      base64 -d > \$out/share/icons/hicolor/512x512/apps/linux-mdm-centre.png <<'LOGOB64'
{$logoB64}
LOGOB64
    '';
NIX
            : "    linuxMdmIcons = pkgs.emptyDirectory;\n";

        $iconName = $logoB64 !== '' ? 'linux-mdm-centre' : 'applications-system';

        return <<<NIX

  ++ (let
{$iconExpr}
  in [
    linuxMdmIcons
    # Application autonome : Chromium embarqué (pas besoin de navigateur système).
    (pkgs.writeShellApplication {
      name = "linux-mdm-centre";
      runtimeInputs = [ pkgs.coreutils ];
      text = ''
        set -euo pipefail
        AUTH=/etc/nixos/mdm.auth
        if [ ! -r "\$AUTH" ]; then
          echo "Linux MDM : impossible de lire \$AUTH (droits ou fichier manquant)." >&2
          echo "Corrigez avec : sudo chmod 644 /etc/nixos/mdm.auth" >&2
          exit 1
        fi
        # shellcheck disable=SC1090
        . "\$AUTH"
        HUB="''\${MDM_URL:-{$hub}}"
        TOKEN="''\${MDM_DEVICE_TOKEN:-}"
        if [ -z "\$TOKEN" ]; then
          echo "Linux MDM : MDM_DEVICE_TOKEN manquant. Relancez install-on-client.sh." >&2
          exit 1
        fi
        URL="\$HUB/software-center?token=\$TOKEN"
        PROFILE="''\${XDG_CACHE_HOME:-\$HOME/.cache}/linux-mdm-centre"
        mkdir -p "\$PROFILE"
        # Moteur web inclus dans le paquet — aucun navigateur n’est requis sur le poste
        exec \${pkgs.chromium}/bin/chromium \
          --app="\$URL" \
          --user-data-dir="\$PROFILE" \
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
      icon = "{$iconName}";
      categories = [ "System" "Settings" "Utility" ];
      startupWMClass = "LinuxMDMCentre";
      terminal = false;
    })
  ])
NIX;
    }

    private function renderFlake(): string
    {
        return <<<'NIX'
{
  description = "Module parc Linux MDM (logiciels + sync)";

  outputs = { self, ... }: {
    nixosModules.default = import ./parc.nix;
    nixosModules.parc = import ./parc.nix;
    nixosModules.autoUpgrade = import ./parc.nix;
  };
}

NIX;
    }

    /** @param array<string, mixed> $settings */
    private function renderInstallScript(array $settings, string $baseUrl, string $parcPathSuffix, string $slug): string
    {
        $hub = $baseUrl !== '' ? $baseUrl : 'https://VOTRE-SERVEUR/mdm';
        $hubShell = $this->bashSingleQuote($hub);
        $slugShell = $this->bashSingleQuote($slug);
        $parcUrl = $hub . '/' . ltrim($parcPathSuffix, '/');
        $installHint = $slug === 'default' || str_contains($parcPathSuffix, 'generated/parc.nix')
            ? "{$hub}/generated/install-on-client.sh"
            : "{$hub}/generated/profiles/{$slug}/install-on-client.sh";

        return <<<BASH
#!/usr/bin/env bash
# Installe le client Linux MDM (enregistrement appareil + profil initial: {$slug})
# Usage (root) : curl -fsSL {$installHint} | sudo bash
# Changer de profil ensuite : interface serveur → Machines (pas besoin de réinstaller).
set -euo pipefail

HUB="\${LINUX_MDM_URL:-\${NIX_MDM_URL:-{$hub}}}"
TARGET="/etc/nixos/parc.nix"
AUTH_FILE="/etc/nixos/mdm.auth"
CFG="/etc/nixos/configuration.nix"
PROFILE_SLUG={$slugShell}

if [[ \$(id -u) -ne 0 ]]; then
  echo "À lancer en root (sudo)." >&2
  exit 1
fi

if [[ ! -d /etc/nixos ]] || [[ ! -f /etc/NIXOS ]]; then
  echo "Cette machine ne semble pas être NixOS (/etc/nixos manquant)." >&2
  exit 1
fi

mkdir -p /etc/nixos
MAC="\$(cat /sys/class/net/*/address 2>/dev/null | grep -vi '^00:00:00:00:00:00\$' | head -1 || true)"
MAC_HEX="\$(printf '%s' "\$MAC" | tr -d ':-' | tr 'A-F' 'a-f')"
if [[ \${#MAC_HEX} -eq 12 ]]; then
  HOSTNAME_MDM="nixos-\${MAC_HEX}"
else
  # Pas de MAC lisible : suffixe aléatoire (12 hex)
  HOSTNAME_MDM="nixos-\$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \\n' | cut -c1-12)"
  echo "Avertissement : MAC introuvable — hostname aléatoire \${HOSTNAME_MDM}" >&2
fi
echo "→ Hostname MDM : \${HOSTNAME_MDM} (dérivé de la MAC)"

# Persistance NixOS du hostname (mkForce > configuration.nix)
printf '%s\\n' "{ lib, ... }: { networking.hostName = lib.mkForce \\"\${HOSTNAME_MDM}\\"; }" > /etc/nixos/mdm-hostname.nix
chmod 644 /etc/nixos/mdm-hostname.nix

EXISTING_TOKEN=""
if [[ -f "\$AUTH_FILE" ]]; then
  # shellcheck disable=SC1090
  . "\$AUTH_FILE" || true
  EXISTING_TOKEN="\${MDM_DEVICE_TOKEN:-}"
fi

echo "→ Enregistrement de l'appareil sur le hub …"
# shellcheck disable=SC1091
eval "\$(curl -fsSL -X POST "\$HUB/register.php?format=shell" \\
  --data-urlencode "hostname=\$HOSTNAME_MDM" \\
  --data-urlencode "mac=\$MAC" \\
  --data-urlencode "device_token=\$EXISTING_TOKEN" \\
  --data-urlencode "profile_slug=\$PROFILE_SLUG" \\
  --data-urlencode "format=shell")"
TOKEN="\${MDM_DEVICE_TOKEN:-}"
ASSIGNED="\${MDM_PROFILE:-}"
if [[ -z "\$TOKEN" ]]; then
  echo "Échec enregistrement hub (pas de token)." >&2
  exit 1
fi

printf 'MDM_URL=%s\\nMDM_DEVICE_TOKEN=%s\\nMDM_PROFILE=%s\\n' {$hubShell} "\$TOKEN" "\${ASSIGNED:-\$PROFILE_SLUG}" > "\$AUTH_FILE"
# Lisible par les utilisateurs (lanceur Centre logiciels depuis le menu)
chmod 644 "\$AUTH_FILE"

echo "→ Téléchargement du parc (profil serveur : \${ASSIGNED:-\$PROFILE_SLUG}) …"
curl -fsSL "\$HUB/parc-for-device.php?token=\$TOKEN" -o "\$TARGET"
chmod 644 "\$TARGET"
ln -sfn parc.nix /etc/nixos/auto-upgrade.nix

if [[ ! -f "\$CFG" ]]; then
  echo "Fichier manquant : \$CFG" >&2
  exit 1
fi

ensure_import() {
  local mod="\$1"
  if grep -qE "\\./\${mod}" "\$CFG"; then
    echo "→ \${mod} déjà présent dans les imports."
    return 0
  fi
  echo "→ Ajout de ./\${mod} dans \$CFG …"
  if grep -qE 'imports[[:space:]]*=' "\$CFG"; then
    sed -i "/imports[[:space:]]*=/,/\\[/{
      /\\[/ s/\\[/[ .\\/\${mod} /
    }" "\$CFG"
  fi
  if ! grep -qE "\\./\${mod}" "\$CFG"; then
    echo "Impossible d’injecter automatiquement ./\${mod} dans \$CFG." >&2
    echo "Ajoutez manuellement : imports = [ ./hardware-configuration.nix ./\${mod} ];" >&2
    return 1
  fi
}

ensure_import parc.nix
ensure_import mdm-hostname.nix

# NIX_PATH souvent absent hors session interactive
if [[ -z "\${NIX_PATH:-}" ]]; then
  if [[ -e /nix/var/nix/profiles/per-user/root/channels/nixpkgs ]]; then
    export NIX_PATH="nixpkgs=/nix/var/nix/profiles/per-user/root/channels/nixpkgs:nixos-config=\$CFG"
  elif [[ -e /root/.nix-defexpr/channels/nixpkgs ]]; then
    export NIX_PATH="nixpkgs=/root/.nix-defexpr/channels/nixpkgs:nixos-config=\$CFG"
  fi
fi
export HOME="\${HOME:-/root}"

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
echo "  Module : \$TARGET"
echo "  Sync   : nix-builder-mdm-sync.timer (actif)"
echo "  Force  : systemctl start nix-builder-mdm-sync.service"
echo "  Centre Logiciels : linux-mdm-centre  (menu Applications → Centre Logiciels)"
systemctl --no-pager --full status nix-builder-mdm-sync.timer 2>/dev/null || true

BASH;
    }

    private function bashSingleQuote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    /** @param array<string, string> $files */
    private function buildTarball(string $tarballPath, array $files): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nixos-flake-' . bin2hex(random_bytes(4));
        if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
            throw new RuntimeException('Impossible de créer le dossier temporaire du tarball.');
        }

        try {
            foreach ($files as $name => $contents) {
                if (file_put_contents($tmp . DIRECTORY_SEPARATOR . $name, $contents) === false) {
                    throw new RuntimeException("Impossible d’écrire {$name} dans le tarball.");
                }
            }

            if (class_exists(PharData::class)) {
                $tarPath = $tmp . '.tar';
                if (is_file($tarPath)) {
                    unlink($tarPath);
                }
                if (is_file($tarballPath)) {
                    unlink($tarballPath);
                }

                $phar = new PharData($tarPath);
                $phar->buildFromDirectory($tmp);
                $phar->compress(Phar::GZ);
                unset($phar);

                $gz = $tarPath . '.gz';
                if (!is_file($gz) || !rename($gz, $tarballPath)) {
                    throw new RuntimeException('Compression du flake.tar.gz impossible.');
                }
                if (is_file($tarPath)) {
                    unlink($tarPath);
                }
                return;
            }

            $cmd = sprintf(
                'tar -czf %s -C %s .',
                escapeshellarg($tarballPath),
                escapeshellarg($tmp)
            );
            exec($cmd, $out, $code);
            if ($code !== 0 || !is_file($tarballPath)) {
                throw new RuntimeException('Impossible de créer flake.tar.gz (Phar/tar indisponible).');
            }
        } finally {
            foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmp);
        }
    }

    private function nixString(string $value): string
    {
        return '"' . addcslashes($value, "\\\"\$") . '"';
    }
}
