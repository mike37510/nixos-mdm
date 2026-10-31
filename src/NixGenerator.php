<?php
declare(strict_types=1);

namespace NixVm;

/**
 * Transforme une spec normalisée (voir Catalog::normalize) en projet flake NixOS
 * construisant une image VMware via `system.build.images.vmware` (nixpkgs >= 25.05).
 */
final class NixGenerator
{
    /** @param array<string, mixed> $spec */
    public function __construct(private readonly array $spec)
    {
    }

    /**
     * @return array<string, string> nom de fichier => contenu
     */
    public function files(): array
    {
        $files = [
            'flake.nix' => $this->flake(),
            'configuration.nix' => $this->configuration(),
        ];
        if (trim((string) $this->spec['extraConfig']) !== '') {
            $files['custom.nix'] = $this->custom();
        }
        $files[$this->spec['hostname'] . '.vmx'] = $this->vmx();
        $files['build.sh'] = $this->buildScript();
        $files['README.md'] = $this->readme();
        return $files;
    }

    public static function str(string $value): string
    {
        return '"' . str_replace(['\\', '"', '${'], ['\\\\', '\\"', '\\${'], $value) . '"';
    }

    /** @param list<string> $values */
    private static function strList(array $values): string
    {
        return '[ ' . implode(' ', array_map(self::str(...), $values)) . ' ]';
    }

    private static function bool(bool $v): string
    {
        return $v ? 'true' : 'false';
    }

    public function flake(): string
    {
        $s = $this->spec;
        $branch = $s['release'] === 'unstable' ? 'nixos-unstable' : 'nixos-' . $s['release'];
        $host = self::str($s['hostname']);
        $desc = self::str('Image NixOS VMware — ' . $s['name']);

        return <<<NIX
            {
              description = $desc;

              inputs.nixpkgs.url = "github:NixOS/nixpkgs/$branch";

              outputs = { self, nixpkgs }:
                let
                  system = "x86_64-linux";
                in
                {
                  nixosConfigurations.$host = nixpkgs.lib.nixosSystem {
                    modules = [
                      { nixpkgs.hostPlatform = system; }
                      ./configuration.nix
                    ];
                  };

                  # nix build .#vmware  ->  result/{$s['hostname']}.vmdk
                  packages.\${system} = {
                    vmware = self.nixosConfigurations.$host.config.system.build.images.vmware;
                    default = self.packages.\${system}.vmware;
                  };
                };
            }

            NIX;
    }

    public function configuration(): string
    {
        $s = $this->spec;
        $desktop = $s['desktop'] !== 'none';
        [, $consoleKeymap, $xkbLayout, $xkbVariant] = Catalog::KEYMAPS[$s['keymap']];
        $services = array_flip($s['services']);
        $out = [];
        $add = static function (string ...$lines) use (&$out): void {
            foreach ($lines as $l) {
                $out[] = $l === '' ? '' : '  ' . $l;
            }
        };

        $out[] = '# Généré par NIX Builder — ' . str_replace("\n", ' ', $s['name']);
        $out[] = '# Reconstruire depuis la VM : sudo nixos-rebuild switch --flake /etc/nixos#' . $s['hostname'];
        $out[] = '{ config, lib, pkgs, ... }:';
        $out[] = '';
        $out[] = '{';
        if (trim((string) $s['extraConfig']) !== '') {
            $add('imports = [ ./custom.nix ];', '');
        }

        // nixos-unstable a renommé vmware.baseImageSize en virtualisation.diskSize.
        $diskOption = $s['release'] === 'unstable' ? 'virtualisation.diskSize' : 'vmware.baseImageSize';
        $add(
            '# --- Image VMware -------------------------------------------------------',
            'image.modules.vmware = {',
            '  image.baseName = ' . self::str($s['hostname']) . ';',
            '  vmware.vmSubformat = ' . self::str($s['vm']['subformat']) . ';',
            '  vmware.vmCompat6 = ' . self::bool($s['vm']['compat6']) . ';',
            "  $diskOption = " . ($s['vm']['diskSize'] > 0 ? (string) ($s['vm']['diskSize'] * 1024) : '"auto"') . '; # Mio',
            '};',
            '',
            '# Identiques au module vmware-image pour que `nixos-rebuild` fonctionne dans la VM.',
            'fileSystems."/" = { device = "/dev/disk/by-label/nixos"; fsType = "ext4"; autoResize = true; };',
            'fileSystems."/boot" = { device = "/dev/disk/by-label/ESP"; fsType = "vfat"; };',
            'boot.growPartition = true;',
            'boot.loader.grub = { device = "nodev"; efiSupport = true; efiInstallAsRemovable = true; };',
            'boot.initrd.availableKernelModules = [ "vmw_pvscsi" "mptspi" "ahci" "sd_mod" "sr_mod" ];',
            '',
            'virtualisation.vmware.guest.enable = true;',
            'virtualisation.vmware.guest.headless = ' . self::bool(!$desktop) . ';',
        );

        $add(
            '',
            '# --- Système --------------------------------------------------------------',
            'networking.hostName = ' . self::str($s['hostname']) . ';',
            'time.timeZone = ' . self::str($s['timezone']) . ';',
            'i18n.defaultLocale = ' . self::str($s['locale']) . ';',
            'console.keyMap = ' . self::str($consoleKeymap) . ';',
        );
        if ($s['allowUnfree']) {
            $add('nixpkgs.config.allowUnfree = true;');
        }
        $add(
            '',
            'nix.settings = {',
            '  experimental-features = [ "nix-command" "flakes" ];',
            '  auto-optimise-store = true;',
            '  trusted-users = [ "@wheel" ];',
            '};',
            'nix.gc = { automatic = true; dates = "weekly"; options = "--delete-older-than 14d"; };',
        );
        if ($s['autoUpgrade']) {
            $add(
                'system.autoUpgrade = {',
                '  enable = true;',
                '  flake = ' . self::str('/etc/nixos#' . $s['hostname']) . ';',
                '  flags = [ "--recreate-lock-file" "--no-write-lock-file" "-L" ];',
                '  dates = "04:00";',
                '};',
            );
        }

        $add(
            '',
            '# Copie la configuration dans /etc/nixos au premier démarrage.',
            'system.activationScripts.seedNixosConfig = \'\'',
            '  if [ ! -e /etc/nixos/flake.nix ]; then',
            '    mkdir -p /etc/nixos',
            '    for f in ${./.}/*.nix ${./.}/flake.lock; do',
            '      if [ -e "$f" ]; then install -m 0644 "$f" /etc/nixos/; fi',
            '    done',
            '  fi',
            '\'\';',
        );

        $add('', '# --- Réseau ---------------------------------------------------------------');
        if ($s['network']['mode'] === 'static') {
            $dns = $s['network']['dns'] !== '' ? explode(' ', $s['network']['dns']) : [];
            $add(
                'networking.useDHCP = false;',
                'networking.useNetworkd = true;',
                'systemd.network.networks."10-lan" = {',
                '  matchConfig.Name = "en* eth*";',
                '  address = ' . self::strList([$s['network']['address']]) . ';',
                '  gateway = ' . self::strList([$s['network']['gateway']]) . ';',
                '  dns = ' . self::strList($dns) . ';',
                '};',
            );
        } elseif ($desktop) {
            $add('networking.networkmanager.enable = true;');
        } else {
            $add('networking.useDHCP = lib.mkDefault true;');
        }

        $ports = [];
        foreach ($s['services'] as $svc) {
            array_push($ports, ...Catalog::SERVICES[$svc][2]);
        }
        if ($s['firewall']['extraPorts'] !== '') {
            array_push($ports, ...array_map('intval', explode(' ', $s['firewall']['extraPorts'])));
        }
        $ports = array_values(array_unique($ports));
        sort($ports);
        $add('networking.firewall.enable = ' . self::bool($s['firewall']['enable']) . ';');
        if ($s['firewall']['enable'] && $ports !== []) {
            $add('networking.firewall.allowedTCPPorts = [ ' . implode(' ', $ports) . ' ];');
        }

        $u = $s['user'];
        $groups = ['wheel'];
        if ($desktop && $s['network']['mode'] !== 'static') {
            $groups[] = 'networkmanager';
        }
        foreach ($s['services'] as $svc) {
            array_push($groups, ...Catalog::SERVICES[$svc][3]);
        }
        $keys = $u['sshKeys'] !== '' ? explode("\n", $u['sshKeys']) : [];
        $add(
            '',
            '# --- Utilisateurs ---------------------------------------------------------',
            'users.users.' . self::str($u['name']) . ' = {',
            '  isNormalUser = true;',
            '  extraGroups = ' . self::strList(array_values(array_unique($groups))) . ';',
        );
        if ($u['passwordHash'] !== '') {
            $add('  initialHashedPassword = ' . self::str($u['passwordHash']) . ';');
        }
        if ($keys !== []) {
            $add('  openssh.authorizedKeys.keys = [');
            foreach ($keys as $k) {
                $add('    ' . self::str($k));
            }
            $add('  ];');
        }
        $add('};');
        if ($u['sudoNoPassword'] || $u['passwordHash'] === '') {
            $add('security.sudo.wheelNeedsPassword = false;');
        }

        if ($s['ssh']['enable']) {
            $add(
                '',
                'services.openssh = {',
                '  enable = true;',
                '  settings = {',
                '    PermitRootLogin = "no";',
                '    PasswordAuthentication = ' . self::bool($s['ssh']['passwordAuth']) . ';',
                '    KbdInteractiveAuthentication = false;',
                '  };',
                '};',
            );
        }

        if ($desktop) {
            $add('', '# --- Bureau ---------------------------------------------------------------');
            match ($s['desktop']) {
                'gnome' => $add(
                    'services.displayManager.gdm.enable = true;',
                    'services.desktopManager.gnome.enable = true;',
                ),
                'plasma' => $add(
                    'services.displayManager.sddm.enable = true;',
                    'services.displayManager.sddm.wayland.enable = true;',
                    'services.desktopManager.plasma6.enable = true;',
                ),
                'xfce' => $add(
                    'services.xserver.enable = true;',
                    'services.xserver.displayManager.lightdm.enable = true;',
                    'services.xserver.desktopManager.xfce.enable = true;',
                ),
            };
            $add('services.xserver.xkb.layout = ' . self::str($xkbLayout) . ';');
            if ($xkbVariant !== '') {
                $add('services.xserver.xkb.variant = ' . self::str($xkbVariant) . ';');
            }
            $add(
                'services.pipewire = { enable = true; alsa.enable = true; pulse.enable = true; };',
                'security.rtkit.enable = true;',
                'services.printing.enable = true;',
            );
        }

        if ($s['services'] !== []) {
            $add('', '# --- Services -------------------------------------------------------------');
        }
        foreach ($s['services'] as $svc) {
            match ($svc) {
                'docker' => $add('virtualisation.docker = { enable = true; autoPrune.enable = true; };'),
                'podman' => $add('virtualisation.podman = { enable = true; dockerCompat = ' . self::bool(!isset($services['docker'])) . '; defaultNetwork.settings.dns_enabled = true; };'),
                'nginx' => $add(
                    'services.nginx = {',
                    '  enable = true;',
                    '  recommendedGzipSettings = true;',
                    '  recommendedOptimisation = true;',
                    '  recommendedProxySettings = true;',
                    '  recommendedTlsSettings = true;',
                    '};',
                ),
                'caddy' => $add('services.caddy.enable = true;'),
                'postgresql' => $add('services.postgresql.enable = true;'),
                'mariadb' => $add('services.mysql = { enable = true; package = pkgs.mariadb; };'),
                'redis' => $add('services.redis.servers."".enable = true;'),
                'fail2ban' => $add('services.fail2ban.enable = true;'),
                'tailscale' => $add('services.tailscale.enable = true;'),
                'node_exporter' => $add('services.prometheus.exporters.node.enable = true;'),
                'netdata' => $add('services.netdata.enable = true;'),
                'k3s' => $add('services.k3s = { enable = true; role = "server"; };'),
            };
        }

        $add(
            '',
            '# --- Navigateur -----------------------------------------------------------',
            'programs.firefox.enable = false;',
        );

        $add('', '# --- Paquets --------------------------------------------------------------');
        $packages = array_values(array_filter(
            (array) $s['packages'],
            static fn ($p) => !in_array((string) $p, ['firefox', 'firefox-bin', 'firefox-esr'], true),
        ));
        if ($packages === []) {
            $add('environment.systemPackages = [ ];');
        } else {
            $add('environment.systemPackages = with pkgs; [');
            foreach ($packages as $p) {
                $add('  ' . $p);
            }
            $add('];');
        }

        $add('', 'system.stateVersion = ' . self::str(Catalog::RELEASES[$s['release']][1]) . ';');
        $out[] = '}';
        return implode("\n", $out) . "\n";
    }

    public function custom(): string
    {
        $body = rtrim((string) $this->spec['extraConfig']);
        $body = implode("\n", array_map(static fn (string $l) => $l === '' ? '' : '  ' . $l, explode("\n", $body)));
        return "# Configuration NixOS personnalisée (options avancées)\n{ config, lib, pkgs, ... }:\n\n{\n$body\n}\n";
    }

    public function vmx(): string
    {
        $s = $this->spec;
        $vm = $s['vm'];
        $q = static fn (string|int $v) => '"' . str_replace('"', '', (string) $v) . '"';
        $lines = [
            '.encoding' => 'UTF-8',
            'config.version' => '8',
            'virtualHW.version' => $vm['hwVersion'],
            'displayName' => $s['hostname'],
            'annotation' => $s['name'],
            'guestOS' => 'other5xlinux-64',
            'firmware' => 'efi',
            'uefi.secureBoot.enabled' => 'FALSE',
            'numvcpus' => $vm['cpus'],
            'memsize' => $vm['memory'],
            'pciBridge0.present' => 'TRUE',
            'pciBridge4.present' => 'TRUE',
            'pciBridge4.virtualDev' => 'pcieRootPort',
            'pciBridge4.functions' => '8',
            'scsi0.present' => 'TRUE',
            'scsi0.virtualDev' => 'pvscsi',
            'scsi0:0.present' => 'TRUE',
            'scsi0:0.fileName' => $s['hostname'] . '.vmdk',
            'ethernet0.present' => 'TRUE',
            'ethernet0.virtualDev' => 'vmxnet3',
            'ethernet0.connectionType' => 'nat',
            'ethernet0.addressType' => 'generated',
            'usb.present' => 'TRUE',
            'ehci.present' => 'TRUE',
            'usb_xhci.present' => 'TRUE',
            'svga.present' => 'TRUE',
            'tools.syncTime' => 'TRUE',
            'tools.upgrade.policy' => 'manual',
        ];
        if ($s['desktop'] !== 'none') {
            $lines['mks.enable3d'] = 'TRUE';
            $lines['svga.graphicsMemoryKB'] = '262144';
            $lines['sound.present'] = 'TRUE';
            $lines['sound.virtualDev'] = 'hdaudio';
        }
        $out = '';
        foreach ($lines as $k => $v) {
            $out .= $k . ' = ' . $q($v) . "\n";
        }
        return $out;
    }

    /**
     * Descripteur OVF pour un disque streamOptimized (empaqueté en OVA par le worker).
     */
    public function ovf(int $diskFileSize, int $diskCapacityBytes): string
    {
        $s = $this->spec;
        $vm = $s['vm'];
        $x = static fn (string|int $v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $host = $x($s['hostname']);
        $name = $x($s['name']);
        $vmdk = $x($s['hostname'] . '.vmdk');
        $hw = (int) $vm['hwVersion'];

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <Envelope xmlns="http://schemas.dmtf.org/ovf/envelope/1" xmlns:cim="http://schemas.dmtf.org/wbem/wscim/1/common" xmlns:ovf="http://schemas.dmtf.org/ovf/envelope/1" xmlns:rasd="http://schemas.dmtf.org/wbem/wscim/1/cim-schema/2/CIM_ResourceAllocationSettingData" xmlns:vmw="http://www.vmware.com/schema/ovf" xmlns:vssd="http://schemas.dmtf.org/wbem/wscim/1/cim-schema/2/CIM_VirtualSystemSettingData" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
              <References>
                <File ovf:href="$vmdk" ovf:id="file1" ovf:size="$diskFileSize"/>
              </References>
              <DiskSection>
                <Info>Virtual disk information</Info>
                <Disk ovf:capacity="$diskCapacityBytes" ovf:capacityAllocationUnits="byte" ovf:diskId="vmdisk1" ovf:fileRef="file1" ovf:format="http://www.vmware.com/interfaces/specifications/vmdk.html#streamOptimized"/>
              </DiskSection>
              <NetworkSection>
                <Info>The list of logical networks</Info>
                <Network ovf:name="VM Network">
                  <Description>The VM Network network</Description>
                </Network>
              </NetworkSection>
              <VirtualSystem ovf:id="$host">
                <Info>A virtual machine</Info>
                <Name>$host</Name>
                <AnnotationSection>
                  <Info>Description</Info>
                  <Annotation>$name — généré par NIX Builder</Annotation>
                </AnnotationSection>
                <OperatingSystemSection ovf:id="101" vmw:osType="other5xLinux64Guest">
                  <Info>The kind of installed guest operating system</Info>
                </OperatingSystemSection>
                <VirtualHardwareSection>
                  <Info>Virtual hardware requirements</Info>
                  <System>
                    <vssd:ElementName>Virtual Hardware Family</vssd:ElementName>
                    <vssd:InstanceID>0</vssd:InstanceID>
                    <vssd:VirtualSystemIdentifier>$host</vssd:VirtualSystemIdentifier>
                    <vssd:VirtualSystemType>vmx-$hw</vssd:VirtualSystemType>
                  </System>
                  <Item>
                    <rasd:AllocationUnits>hertz * 10^6</rasd:AllocationUnits>
                    <rasd:Description>Number of Virtual CPUs</rasd:Description>
                    <rasd:ElementName>{$vm['cpus']} virtual CPU(s)</rasd:ElementName>
                    <rasd:InstanceID>1</rasd:InstanceID>
                    <rasd:ResourceType>3</rasd:ResourceType>
                    <rasd:VirtualQuantity>{$vm['cpus']}</rasd:VirtualQuantity>
                  </Item>
                  <Item>
                    <rasd:AllocationUnits>byte * 2^20</rasd:AllocationUnits>
                    <rasd:Description>Memory Size</rasd:Description>
                    <rasd:ElementName>{$vm['memory']}MB of memory</rasd:ElementName>
                    <rasd:InstanceID>2</rasd:InstanceID>
                    <rasd:ResourceType>4</rasd:ResourceType>
                    <rasd:VirtualQuantity>{$vm['memory']}</rasd:VirtualQuantity>
                  </Item>
                  <Item>
                    <rasd:Address>0</rasd:Address>
                    <rasd:Description>SCSI Controller</rasd:Description>
                    <rasd:ElementName>SCSI Controller 0</rasd:ElementName>
                    <rasd:InstanceID>3</rasd:InstanceID>
                    <rasd:ResourceSubType>VirtualSCSI</rasd:ResourceSubType>
                    <rasd:ResourceType>6</rasd:ResourceType>
                  </Item>
                  <Item>
                    <rasd:AddressOnParent>0</rasd:AddressOnParent>
                    <rasd:ElementName>Hard Disk 1</rasd:ElementName>
                    <rasd:HostResource>ovf:/disk/vmdisk1</rasd:HostResource>
                    <rasd:InstanceID>4</rasd:InstanceID>
                    <rasd:Parent>3</rasd:Parent>
                    <rasd:ResourceType>17</rasd:ResourceType>
                  </Item>
                  <Item>
                    <rasd:AddressOnParent>7</rasd:AddressOnParent>
                    <rasd:AutomaticAllocation>true</rasd:AutomaticAllocation>
                    <rasd:Connection>VM Network</rasd:Connection>
                    <rasd:Description>VmxNet3 ethernet adapter on "VM Network"</rasd:Description>
                    <rasd:ElementName>Network adapter 1</rasd:ElementName>
                    <rasd:InstanceID>5</rasd:InstanceID>
                    <rasd:ResourceSubType>VmxNet3</rasd:ResourceSubType>
                    <rasd:ResourceType>10</rasd:ResourceType>
                  </Item>
                  <vmw:Config ovf:required="false" vmw:key="firmware" vmw:value="efi"/>
                  <vmw:Config ovf:required="false" vmw:key="bootOptions.efiSecureBootEnabled" vmw:value="false"/>
                  <vmw:Config ovf:required="false" vmw:key="tools.syncTimeWithHost" vmw:value="true"/>
                </VirtualHardwareSection>
              </VirtualSystem>
            </Envelope>

            XML;
    }

    public function buildScript(): string
    {
        $host = $this->spec['hostname'];
        return <<<BASH
            #!/usr/bin/env bash
            # Construit l'image VMware sur n'importe quelle machine Linux x86_64 avec Nix
            # (idéalement avec /dev/kvm, sinon le build est beaucoup plus lent).
            set -euo pipefail
            cd "\$(dirname "\$0")"

            nix --extra-experimental-features 'nix-command flakes' build .#vmware -L --out-link result "\$@"

            vmdk=\$(find -L result -maxdepth 1 -name '*.vmdk' | head -n1)
            cp --no-preserve=mode "\$vmdk" ./$host.vmdk
            echo
            echo "Image prête : \$(pwd)/$host.vmdk  (+ $host.vmx pour VMware Workstation)"

            BASH;
    }

    public function readme(): string
    {
        $s = $this->spec;
        $h = $s['hostname'];
        $ssh = $s['ssh']['enable'] ? "ssh {$s['user']['name']}@<ip-de-la-vm>" : '(SSH désactivé)';
        return <<<MD
            # {$s['name']}

            Image NixOS ({$s['release']}) pour VMware, générée par NIX Builder.

            ## Construire

            ```bash
            ./build.sh            # produit ./$h.vmdk
            ```

            ou directement : `nix build .#vmware`.

            ## Importer dans VMware

            - **Workstation / Fusion / Player** : placer `$h.vmx` et `$h.vmdk` dans le même dossier puis *Fichier > Ouvrir* le `.vmx`.
            - **ESXi / vSphere** : utiliser l'OVA générée par l'application (format `streamOptimized`), via *Déployer un modèle OVF*.
              Pour un VMDK `monolithicSparse`, le convertir sur l'hôte : `vmkfstools -i $h.vmdk -d thin $h-esxi.vmdk`.

            La VM démarre en UEFI ; la partition racine s'agrandit automatiquement si vous augmentez le disque virtuel.

            ## Accès

            Utilisateur : `{$s['user']['name']}` — $ssh

            ## Faire évoluer la VM

            La configuration est copiée dans `/etc/nixos` au premier démarrage :

            ```bash
            sudo nixos-rebuild switch --flake /etc/nixos#$h
            ```

            MD;
    }
}
