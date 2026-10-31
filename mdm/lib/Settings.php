<?php

declare(strict_types=1);

/**
 * Réglages MDM stockés en MySQL (table mdm_profiles).
 */
final class Settings
{
    private \PDO $pdo;

    private int $profileId;

    private string $slug = 'default';

    private string $label = 'Profil par défaut';

    private bool $isDefault = true;

    /** @var array<string, mixed> */
    private array $data;

    private ?string $legacyJsonPath;

    public function __construct(?string $legacyJsonPath = null, ?int $profileId = null)
    {
        $this->pdo = \NixVm\Db::pdo();
        $this->legacyJsonPath = $legacyJsonPath;
        $this->ensureDefaultProfile();
        $row = $profileId !== null
            ? $this->fetchRowById($profileId)
            : $this->fetchDefaultRow();
        $this->applyRow($row);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function profileId(): int
    {
        return $this->profileId;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function loadProfile(int $id): void
    {
        $this->applyRow($this->fetchRowById($id));
    }

    /**
     * Crée un profil (optionnellement cloné depuis un autre).
     * @return int nouvel id
     */
    public function createProfile(string $label, ?int $cloneFromId = null): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new InvalidArgumentException('Le nom du profil est vide.');
        }
        $slug = $this->uniqueSlug($this->slugify($label));

        if ($cloneFromId !== null) {
            $src = $this->fetchRowById($cloneFromId);
            $data = $this->decodeData((string) $src['data']);
        } else {
            $data = self::defaults();
        }
        $data['updatedAt'] = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Impossible d’encoder le profil.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mdm_profiles (slug, label, is_default, data) VALUES (:slug, :label, 0, :data)'
        );
        $stmt->execute(['slug' => $slug, 'label' => $label, 'data' => $json]);
        $id = (int) $this->pdo->lastInsertId();
        $this->loadProfile($id);
        return $id;
    }

    public function renameProfile(string $label): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new InvalidArgumentException('Le nom du profil est vide.');
        }
        // Garde le slug stable (URLs clients) ; seul le libellé change.
        $stmt = $this->pdo->prepare('UPDATE mdm_profiles SET label = :label, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['label' => $label, 'id' => $this->profileId]);
        $this->label = $label;
    }

    public function setAsDefault(): void
    {
        $this->pdo->exec('UPDATE mdm_profiles SET is_default = 0');
        $stmt = $this->pdo->prepare('UPDATE mdm_profiles SET is_default = 1, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $this->profileId]);
        $this->isDefault = true;
    }

    public function deleteProfile(): void
    {
        if ($this->isDefault) {
            throw new RuntimeException('Impossible de supprimer le profil par défaut. Assignez d’abord un autre profil par défaut.');
        }
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM mdm_profiles')->fetchColumn();
        if ($count <= 1) {
            throw new RuntimeException('Il doit rester au moins un profil.');
        }

        // Rattache les clients au profil par défaut
        $defaultId = (int) $this->pdo->query(
            'SELECT id FROM mdm_profiles WHERE is_default = 1 ORDER BY id ASC LIMIT 1'
        )->fetchColumn();
        $reassign = $this->pdo->prepare('UPDATE mdm_clients SET profile_id = :pid WHERE profile_id = :old');
        $reassign->execute(['pid' => $defaultId, 'old' => $this->profileId]);

        $stmt = $this->pdo->prepare('DELETE FROM mdm_profiles WHERE id = :id AND is_default = 0');
        $stmt->execute(['id' => $this->profileId]);

        $this->applyRow($this->fetchDefaultRow());
    }

    /** @param array<string, mixed> $input */
    public function update(array $input): void
    {
        $presets = self::frequencyPresets();
        $preset = (string) ($input['frequency_preset'] ?? 'custom');

        if ($preset !== 'custom' && isset($presets[$preset])) {
            $dates = $presets[$preset]['dates'];
            $label = $presets[$preset]['label'];
        } else {
            $dates = trim((string) ($input['dates_custom'] ?? ''));
            if ($dates === '') {
                throw new InvalidArgumentException('La fréquence personnalisée est vide.');
            }
            $label = 'Personnalisé : ' . $dates;
        }

        // Hostname réel = nixos-<mac> sur le client ; le profil garde juste le modèle.
        $hostnameRaw = trim((string) ($input['hostname'] ?? 'nixos-{mac}'));
        $hostname = $hostnameRaw === 'nixos-{mac}'
            ? 'nixos-{mac}'
            : (preg_replace('/[^a-zA-Z0-9._{}-]/', '', $hostnameRaw) ?: 'nixos-{mac}');
        $operation = in_array($input['operation'] ?? 'switch', ['switch', 'boot', 'test'], true)
            ? (string) $input['operation']
            : 'switch';

        $packages = self::parsePackages((string) ($input['packages'] ?? ''));

        $this->data = [
            'autoUpgradeEnable' => isset($input['autoUpgradeEnable']),
            'dates' => $dates,
            'datesLabel' => $label,
            'frequencyPreset' => $preset,
            'operation' => $operation,
            'allowReboot' => isset($input['allowReboot']),
            'randomizedDelaySec' => trim((string) ($input['randomizedDelaySec'] ?? '0')) ?: '0',
            'hostname' => $hostname,
            'packages' => $packages,
            'hubUrl' => (string) ($this->data['hubUrl'] ?? ''),
            'updatedAt' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        ];

        $this->persist();
    }

    public function setHubUrl(string $url): void
    {
        $this->data['hubUrl'] = rtrim($url, '/');
        $this->persist();
    }

    /**
     * Remplace les données du profil (restauration d’une version) puis persiste.
     * @param array<string, mixed> $data
     */
    public function restoreData(array $data): void
    {
        $merged = array_merge(self::defaults(), $data);
        if (!isset($merged['packages']) || !is_array($merged['packages'])) {
            $merged['packages'] = self::defaults()['packages'];
        }
        $merged['packages'] = array_values(array_filter(
            array_map('strval', $merged['packages']),
            static fn (string $p) => $p !== ''
        ));
        $merged['hubUrl'] = (string) ($this->data['hubUrl'] ?? ($merged['hubUrl'] ?? ''));
        $merged['updatedAt'] = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $this->data = $merged;
        $this->persist();
    }

    /**
     * Remplace uniquement la liste des paquets du profil courant.
     * @param list<string> $packages
     */
    public function setPackages(array $packages): void
    {
        $clean = [];
        foreach ($packages as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(\.[A-Za-z_][A-Za-z0-9_-]*){0,6}$/', $p)) {
                throw new InvalidArgumentException("Nom de paquet invalide : {$p}");
            }
            $clean[] = $p;
        }
        $this->data['packages'] = array_values(array_unique($clean));
        $this->data['updatedAt'] = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $this->persist();
    }

    public function removePackage(string $attr): void
    {
        $attr = trim($attr);
        $pkgs = array_values(array_filter(
            array_map('strval', (array) ($this->data['packages'] ?? [])),
            static fn (string $p) => $p !== $attr
        ));
        $this->setPackages($pkgs);
    }

    /** @return list<string> */
    public static function parsePackages(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(\.[A-Za-z_][A-Za-z0-9_-]*){0,6}$/', $p)) {
                throw new InvalidArgumentException("Nom de paquet invalide : {$p}");
            }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    /** @return array<string, array{dates: string, label: string}> */
    public static function frequencyPresets(): array
    {
        return [
            '2min' => [
                'dates' => '*:0/2',
                'label' => 'Toutes les 2 minutes (tests)',
            ],
            '30min' => [
                'dates' => '*:0/30',
                'label' => 'Toutes les 30 minutes',
            ],
            'hourly' => [
                'dates' => 'hourly',
                'label' => 'Toutes les heures',
            ],
            'daily' => [
                'dates' => '04:40',
                'label' => 'Tous les jours à 04:40',
            ],
            'weekly' => [
                'dates' => 'weekly',
                'label' => 'Une fois par semaine',
            ],
        ];
    }

    /**
     * @return list<array{id:int,slug:string,label:string,is_default:bool,folder_id:?int,folder_name:?string,packages:int,updated_at:?string}>
     */
    public function listProfiles(): array
    {
        $rows = $this->pdo->query(
            'SELECT p.id, p.slug, p.label, p.is_default, p.folder_id, p.data, p.updated_at,
                    f.name AS folder_name
             FROM mdm_profiles p
             LEFT JOIN mdm_profile_folders f ON f.id = p.folder_id
             ORDER BY
               (p.folder_id IS NULL) ASC,
               f.sort_order ASC,
               f.name ASC,
               p.is_default DESC,
               p.label ASC'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $data = $this->decodeData((string) $row['data']);
            $fid = $row['folder_id'] ?? null;
            $out[] = [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'label' => (string) $row['label'],
                'is_default' => (bool) $row['is_default'],
                'folder_id' => $fid !== null && $fid !== '' ? (int) $fid : null,
                'folder_name' => isset($row['folder_name']) && $row['folder_name'] !== null
                    ? (string) $row['folder_name'] : null,
                'packages' => count((array) ($data['packages'] ?? [])),
                'updated_at' => isset($data['updatedAt']) ? (string) $data['updatedAt'] : (string) ($row['updated_at'] ?? ''),
            ];
        }
        return $out;
    }

    public function assignFolder(int $profileId, ?int $folderId): void
    {
        if ($profileId <= 0) {
            throw new InvalidArgumentException('Profil invalide.');
        }
        if ($folderId !== null && $folderId > 0) {
            $check = $this->pdo->prepare('SELECT id FROM mdm_profile_folders WHERE id = :id');
            $check->execute(['id' => $folderId]);
            if (!$check->fetch()) {
                throw new InvalidArgumentException('Dossier introuvable.');
            }
        } else {
            $folderId = null;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE mdm_profiles SET folder_id = :fid, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['fid' => $folderId, 'id' => $profileId]);
        if ($stmt->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT id FROM mdm_profiles WHERE id = :id');
            $exists->execute(['id' => $profileId]);
            if (!$exists->fetch()) {
                throw new InvalidArgumentException('Profil introuvable.');
            }
        }
    }

    private function persist(): void
    {
        $json = json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Impossible d’encoder les réglages MDM.');
        }
        $stmt = $this->pdo->prepare('UPDATE mdm_profiles SET data = :data, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['data' => $json, 'id' => $this->profileId]);
    }

    private function ensureDefaultProfile(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM mdm_profiles')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $seed = self::defaults();
        if ($this->legacyJsonPath !== null && is_file($this->legacyJsonPath)) {
            $raw = file_get_contents($this->legacyJsonPath);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $seed = array_merge($seed, $decoded);
                    $seed['packages'] = array_values(array_filter(
                        array_map('strval', (array) ($seed['packages'] ?? [])),
                        static fn (string $p) => $p !== ''
                    ));
                    unset($seed['flakeUrl']);
                }
            }
        }

        $json = json_encode($seed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Impossible d’encoder le profil MDM par défaut.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mdm_profiles (slug, label, is_default, data) VALUES (:slug, :label, 1, :data)'
        );
        $stmt->execute([
            'slug' => 'default',
            'label' => 'Profil par défaut',
            'data' => $json,
        ]);

        if ($this->legacyJsonPath !== null && is_file($this->legacyJsonPath)) {
            @rename($this->legacyJsonPath, $this->legacyJsonPath . '.bak');
        }
    }

    /** @param array<string, mixed> $row */
    private function applyRow(array $row): void
    {
        $this->profileId = (int) $row['id'];
        $this->slug = (string) ($row['slug'] ?? 'default');
        $this->label = (string) ($row['label'] ?? $this->slug);
        $this->isDefault = !empty($row['is_default']);
        $this->data = $this->decodeData((string) $row['data']);
    }

    /** @return array<string, mixed> */
    private function fetchDefaultRow(): array
    {
        $row = $this->pdo->query(
            'SELECT id, slug, label, is_default, data FROM mdm_profiles WHERE is_default = 1 ORDER BY id ASC LIMIT 1'
        )->fetch();
        if (!$row) {
            $row = $this->pdo->query(
                'SELECT id, slug, label, is_default, data FROM mdm_profiles ORDER BY id ASC LIMIT 1'
            )->fetch();
        }
        if (!$row) {
            throw new RuntimeException('Aucun profil MDM en base.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function fetchRowById(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, label, is_default, data FROM mdm_profiles WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException("Profil #{$id} introuvable.");
        }
        return $row;
    }

    private function slugify(string $label): string
    {
        $s = trim($label);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') {
                $s = $t;
            }
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? 'profil';
        $s = trim($s, '-');
        if ($s === '') {
            $s = 'profil';
        }
        return substr($s, 0, 48);
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 2;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mdm_profiles WHERE slug = :slug');
        while (true) {
            $stmt->execute(['slug' => $slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = substr($base, 0, 40) . '-' . $i;
            $i++;
        }
    }

    /** @return array<string, mixed> */
    private function decodeData(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return self::defaults();
        }
        $merged = array_merge(self::defaults(), $decoded);
        if (!isset($merged['packages']) || !is_array($merged['packages'])) {
            $merged['packages'] = self::defaults()['packages'];
        }
        $merged['packages'] = array_values(array_filter(
            array_map('strval', $merged['packages']),
            static fn (string $p) => $p !== ''
        ));
        unset($merged['flakeUrl']);
        return $merged;
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'autoUpgradeEnable' => true,
            'dates' => '*:0/30',
            'datesLabel' => 'Toutes les 30 minutes',
            'frequencyPreset' => '30min',
            'operation' => 'switch',
            'allowReboot' => false,
            'randomizedDelaySec' => '0',
            'hostname' => 'nixos-{mac}',
            'packages' => ['vim', 'git', 'curl', 'htop'],
            'hubUrl' => '',
            'updatedAt' => null,
        ];
    }
}
