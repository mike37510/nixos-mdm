<?php
declare(strict_types=1);

namespace NixVm;

final class Catalog
{
    /** Canal nixpkgs => [libellé, stateVersion]. */
    public const RELEASES = [
        '26.05' => ['NixOS 26.05 (stable)', '26.05'],
        'unstable' => ['NixOS unstable', '26.05'],
    ];

    public const DESKTOPS = [
        'none' => 'Aucun (serveur headless)',
        'gnome' => 'GNOME',
        'plasma' => 'KDE Plasma 6',
        'xfce' => 'XFCE (léger)',
    ];

    public const SUBFORMATS = [
        'streamOptimized' => 'streamOptimized — OVA pour ESXi / vSphere / Workstation (recommandé)',
        'monolithicSparse' => 'monolithicSparse — VMDK + VMX pour Workstation / Fusion / Player',
        'twoGbMaxExtentSparse' => 'twoGbMaxExtentSparse — VMDK découpé en fichiers de 2 Go',
    ];

    /** Disposition => [libellé, console.keyMap, xkb layout, xkb variant]. */
    public const KEYMAPS = [
        'fr' => ['Français (AZERTY)', 'fr', 'fr', ''],
        'fr-bepo' => ['Français (BÉPO)', 'fr-bepo', 'fr', 'bepo'],
        'be' => ['Belge', 'be-latin1', 'be', ''],
        'ch-fr' => ['Suisse romand', 'fr_CH', 'ch', 'fr'],
        'ca-fr' => ['Canadien français', 'cf', 'ca', 'fr'],
        'us' => ['Anglais (US QWERTY)', 'us', 'us', ''],
        'uk' => ['Anglais (UK)', 'uk', 'gb', ''],
        'de' => ['Allemand', 'de', 'de', ''],
        'es' => ['Espagnol', 'es', 'es', ''],
        'it' => ['Italien', 'it', 'it', ''],
    ];

    private const NIX_KEYWORDS = ['assert', 'else', 'if', 'in', 'inherit', 'let', 'or', 'rec', 'then', 'with'];

    /**
     * Services activables : clé => [libellé, description, ports firewall, groupes utilisateur].
     */
    public const SERVICES = [
        'docker' => ['Docker', 'Moteur de conteneurs Docker + docker-compose', [], ['docker']],
        'podman' => ['Podman', 'Conteneurs rootless, compatible Docker CLI', [], []],
        'nginx' => ['Nginx', 'Serveur web / reverse proxy', [80, 443], []],
        'caddy' => ['Caddy', 'Serveur web avec HTTPS automatique', [80, 443], []],
        'postgresql' => ['PostgreSQL', 'Base de données relationnelle', [], []],
        'mariadb' => ['MariaDB', 'Base de données MySQL compatible', [], []],
        'redis' => ['Redis', 'Cache clé/valeur en mémoire', [], []],
        'fail2ban' => ['Fail2ban', 'Bannissement des IP qui bruteforcent SSH', [], []],
        'tailscale' => ['Tailscale', 'VPN mesh WireGuard', [], []],
        'node_exporter' => ['Prometheus node exporter', 'Métriques système sur :9100', [9100], []],
        'netdata' => ['Netdata', 'Monitoring temps réel sur :19999', [19999], []],
        'k3s' => ['k3s', 'Kubernetes léger (nœud serveur)', [6443], []],
    ];

    /**
     * Paquets suggérés par catégorie (noms d'attributs nixpkgs).
     */
    public const PACKAGE_GROUPS = [
        'Essentiels' => ['vim', 'neovim', 'nano', 'git', 'curl', 'wget', 'htop', 'btop', 'tmux', 'jq', 'tree', 'unzip', 'rsync', 'file'],
        'Réseau' => ['dig', 'nmap', 'tcpdump', 'iperf3', 'mtr', 'traceroute', 'inetutils', 'socat', 'wireguard-tools'],
        'Développement' => ['gcc', 'gnumake', 'python3', 'nodejs', 'go', 'rustc', 'cargo', 'php', 'jdk', 'gh', 'direnv'],
        'DevOps / Cloud' => ['docker-compose', 'kubectl', 'kubernetes-helm', 'k9s', 'terraform', 'opentofu', 'ansible', 'awscli2', 'google-cloud-sdk'],
        'Bases de données' => ['postgresql', 'mariadb', 'redis', 'sqlite'],
        'Bureau' => ['firefox', 'chromium', 'vscode', 'libreoffice', 'thunderbird', 'vlc', 'gimp', 'keepassxc'],
        'Système' => ['lsof', 'strace', 'pciutils', 'usbutils', 'smartmontools', 'ncdu', 'duf', 'open-vm-tools'],
    ];

    /** Paquets non libres : nécessitent nixpkgs.config.allowUnfree. */
    public const UNFREE = ['vscode', 'google-chrome', 'terraform', 'slack', 'discord', 'zoom-us', 'spotify'];

    /**
     * Images de base OS (NixOS) : point de départ préconfiguré.
     * Chaque projet peut être exporté en OVA VMware et/ou en ZIP Docker.
     *
     * @return array<string, array{label: string, description: string, desktop: string, packages: list<string>, services: list<string>, memory: int, cpus: int}>
     */
    public static function bases(): array
    {
        return [
            'dev' => [
                'label' => 'Poste développeur',
                'description' => 'Bureau GNOME avec chaîne de dev complète et Docker.',
                'desktop' => 'gnome',
                'packages' => ['vim', 'git', 'curl', 'htop', 'vscode', 'python3', 'nodejs', 'go', 'gcc', 'gnumake', 'gh'],
                'services' => ['docker'],
                'memory' => 8192, 'cpus' => 4,
            ],
            'desktop' => [
                'label' => 'Bureau léger',
                'description' => 'XFCE avec bureautique (navigateur à ajouter si besoin).',
                'desktop' => 'xfce',
                'packages' => ['libreoffice', 'vlc', 'keepassxc'],
                'services' => [],
                'memory' => 4096, 'cpus' => 2,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(string $base = 'dev'): array
    {
        $b = self::bases()[$base] ?? self::bases()['dev'];
        return [
            'name' => $b['label'],
            'base' => isset(self::bases()[$base]) ? $base : 'dev',
            'release' => '26.05',
            'hostname' => 'nixos-vm',
            'timezone' => 'Europe/Paris',
            'locale' => 'fr_FR.UTF-8',
            'keymap' => 'fr',
            'desktop' => $b['desktop'],
            'packages' => $b['packages'],
            'services' => $b['services'],
            'user' => [
                'name' => 'admin',
                'passwordHash' => '',
                'sshKeys' => '',
                'sudoNoPassword' => false,
            ],
            'ssh' => ['enable' => true, 'passwordAuth' => true],
            'network' => ['mode' => 'dhcp', 'address' => '', 'gateway' => '', 'dns' => '1.1.1.1 9.9.9.9'],
            'firewall' => ['enable' => true, 'extraPorts' => ''],
            'vm' => [
                'cpus' => $b['cpus'],
                'memory' => $b['memory'],
                'diskSize' => 20,
                'subformat' => 'streamOptimized',
                'compat6' => false,
                'hwVersion' => 19,
            ],
            // Port hôte suggéré pour l'export ZIP Docker (optionnel).
            'docker' => ['hostPort' => 8080],
            'allowUnfree' => false,
            'autoUpgrade' => false,
            'extraConfig' => '',
        ];
    }

    /**
     * Valide et normalise une spec reçue du formulaire.
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $previous spec déjà enregistrée (pour conserver le hash du mot de passe)
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function normalize(array $in, ?array $previous = null): array
    {
        $errors = [];
        $base = is_string($in['base'] ?? null) && isset(self::bases()[$in['base']]) ? $in['base'] : 'dev';
        $d = self::defaults($base);
        $s = $d;

        $s['name'] = mb_substr(trim((string) ($in['name'] ?? $d['name'])), 0, 120) ?: $d['name'];
        $s['release'] = self::pick($in['release'] ?? null, array_keys(self::RELEASES), $d['release']);
        $s['desktop'] = self::pick($in['desktop'] ?? null, array_keys(self::DESKTOPS), $d['desktop']);

        $hostname = strtolower(trim((string) ($in['hostname'] ?? '')));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $hostname)) {
            $errors['hostname'] = 'Nom d\'hôte invalide (lettres, chiffres et tirets, 63 caractères max).';
            $hostname = $d['hostname'];
        }
        $s['hostname'] = $hostname;

        $tz = trim((string) ($in['timezone'] ?? $d['timezone']));
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true) && $tz !== 'UTC') {
            $errors['timezone'] = 'Fuseau horaire inconnu.';
            $tz = $d['timezone'];
        }
        $s['timezone'] = $tz;

        $locale = trim((string) ($in['locale'] ?? $d['locale']));
        if (!preg_match('/^[a-zA-Z]{2,3}_[A-Z]{2}\.UTF-8$/', $locale) && $locale !== 'C.UTF-8') {
            $errors['locale'] = 'Locale invalide (ex : fr_FR.UTF-8).';
            $locale = $d['locale'];
        }
        $s['locale'] = $locale;

        $s['keymap'] = self::pick($in['keymap'] ?? null, array_keys(self::KEYMAPS), $d['keymap']);

        $packages = [];
        foreach ((array) ($in['packages'] ?? []) as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            if (!self::isValidAttr($p)) {
                $errors['packages'] = "Nom de paquet invalide : « $p ».";
                continue;
            }
            $packages[$p] = true;
        }
        $s['packages'] = array_keys($packages);

        $s['services'] = array_values(array_intersect(array_keys(self::SERVICES), (array) ($in['services'] ?? [])));

        $u = (array) ($in['user'] ?? []);
        $username = strtolower(trim((string) ($u['name'] ?? '')));
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username) || $username === 'root') {
            $errors['user.name'] = 'Nom d\'utilisateur invalide (minuscules, chiffres, _ ou -, pas root).';
            $username = $d['user']['name'];
        }
        $password = (string) ($u['password'] ?? '');
        $hash = (string) ($previous['user']['passwordHash'] ?? '');
        if (!empty($u['clearPassword'])) {
            $hash = '';
        }
        if ($password !== '') {
            if (mb_strlen($password) < 8) {
                $errors['user.password'] = 'Mot de passe trop court (8 caractères minimum).';
            } else {
                $hash = crypt($password, '$6$rounds=100000$' . substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16) . '$');
            }
        }
        $keys = [];
        foreach (preg_split('/\R/', (string) ($u['sshKeys'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^(ssh-(ed25519|rsa|dss)|ecdsa-sha2-nistp(256|384|521)|sk-(ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com) [A-Za-z0-9+\/=]+( [^\r\n"\\\\$]*)?$/', $line)) {
                $errors['user.sshKeys'] = 'Une des clés SSH publiques est invalide.';
                continue;
            }
            $keys[] = $line;
        }
        $s['user'] = [
            'name' => $username,
            'passwordHash' => $hash,
            'sshKeys' => implode("\n", $keys),
            'sudoNoPassword' => !empty($u['sudoNoPassword']),
        ];

        $ssh = (array) ($in['ssh'] ?? []);
        $s['ssh'] = [
            'enable' => !empty($ssh['enable']),
            'passwordAuth' => !empty($ssh['passwordAuth']) || $keys === [],
        ];

        if ($s['user']['passwordHash'] === '' && $keys === []) {
            $errors['user.password'] = 'Définissez un mot de passe, sinon la VM sera inaccessible.';
        }

        $n = (array) ($in['network'] ?? []);
        $s['network'] = $d['network'];
        $s['network']['mode'] = ($n['mode'] ?? '') === 'static' ? 'static' : 'dhcp';
        $s['network']['dns'] = self::ipList((string) ($n['dns'] ?? ''), $errors, 'network.dns');
        if ($s['network']['mode'] === 'static') {
            $addr = trim((string) ($n['address'] ?? ''));
            $gw = trim((string) ($n['gateway'] ?? ''));
            if (!preg_match('#^(\d{1,3}(\.\d{1,3}){3})/(\d{1,2})$#', $addr, $m) || !filter_var($m[1], FILTER_VALIDATE_IP) || (int) $m[3] > 32) {
                $errors['network.address'] = 'Adresse invalide, format attendu : 192.168.1.50/24.';
            }
            if (!filter_var($gw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors['network.gateway'] = 'Passerelle invalide.';
            }
            $s['network']['address'] = $addr;
            $s['network']['gateway'] = $gw;
        }

        $f = (array) ($in['firewall'] ?? []);
        $ports = [];
        foreach (preg_split('/[\s,;]+/', (string) ($f['extraPorts'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $port) {
            if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                $errors['firewall.extraPorts'] = "Port invalide : « $port ».";
                continue;
            }
            $ports[(int) $port] = true;
        }
        $s['firewall'] = ['enable' => !empty($f['enable']), 'extraPorts' => implode(' ', array_keys($ports))];

        $vm = (array) ($in['vm'] ?? []);
        $s['vm'] = [
            'cpus' => self::clampInt($vm['cpus'] ?? null, 1, 64, $d['vm']['cpus']),
            'memory' => self::clampInt($vm['memory'] ?? null, 512, 262144, $d['vm']['memory']),
            'diskSize' => self::clampInt($vm['diskSize'] ?? null, 0, 4096, $d['vm']['diskSize']),
            'subformat' => self::pick($vm['subformat'] ?? null, array_keys(self::SUBFORMATS), $d['vm']['subformat']),
            'compat6' => !empty($vm['compat6']),
            'hwVersion' => self::clampInt($vm['hwVersion'] ?? null, 10, 22, $d['vm']['hwVersion']),
        ];

        $docker = (array) ($in['docker'] ?? []);
        $defaultPort = (int) ($d['docker']['hostPort'] ?? 8080);
        $s['docker'] = [
            'hostPort' => self::clampInt($docker['hostPort'] ?? null, 1, 65535, $defaultPort),
        ];

        $s['allowUnfree'] = !empty($in['allowUnfree']) || array_intersect($s['packages'], self::UNFREE) !== [];
        $s['autoUpgrade'] = !empty($in['autoUpgrade']);
        $s['extraConfig'] = mb_substr(str_replace("\r\n", "\n", (string) ($in['extraConfig'] ?? '')), 0, 20000);

        return [$s, $errors];
    }

    public static function isValidAttr(string $attr): bool
    {
        if (strlen($attr) > 120 || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(\.[A-Za-z_][A-Za-z0-9_-]*){0,4}$/', $attr)) {
            return false;
        }
        return array_intersect(explode('.', $attr), self::NIX_KEYWORDS) === [];
    }

    /**
     * @param list<string> $allowed
     */
    private static function pick(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }

    /**
     * @param array<string, string> $errors
     */
    private static function ipList(string $raw, array &$errors, string $field): string
    {
        $ips = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $errors[$field] = "Adresse IP invalide : « $ip ».";
                continue;
            }
            $ips[] = $ip;
        }
        return implode(' ', $ips);
    }
}
