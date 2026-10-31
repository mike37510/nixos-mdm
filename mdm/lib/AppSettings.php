<?php

declare(strict_types=1);

/**
 * Réglages globaux de l’outil Linux MDM (branding, URL publique, etc.).
 */
final class AppSettings
{
    private const BRANDING_DIR = 'assets/branding';

    private \PDO $pdo;

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \NixVm\Db::pdo();
        $this->ensureRow();
        $this->data = $this->load();
    }

    /** URL hub déduite de la requête HTTP courante (…/mdm). */
    public static function detectRequestHubUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if ($host === '') {
            $host = 'localhost';
        }
        return $scheme . '://' . $host . '/mdm';
    }

    /**
     * Normalise une saisie admin (origine, …/mdm, ou URL complète du script d’install).
     * Chaîne vide = mode automatique (détection HTTP).
     */
    public static function normalizeHubUrlInput(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $url = rtrim($url, '/');
        if (preg_match('#^(https?://.+)/generated(?:/profiles/[^/]+)?/install-on-client\.sh$#i', $url, $m)) {
            $url = rtrim($m[1], '/');
        }
        if (!preg_match('#^https?://#i', $url)) {
            throw new InvalidArgumentException('URL invalide : http:// ou https:// requis.');
        }
        // Origine seule → ajoute /mdm
        if (preg_match('#^https?://[^/]+$#i', $url)) {
            $url .= '/mdm';
        }
        return rtrim($url, '/');
    }

    /** URL configurée (vide si mode auto). */
    public function configuredHubUrl(): string
    {
        return rtrim(trim((string) ($this->data['public_hub_url'] ?? '')), '/');
    }

    /**
     * URL publique du hub (…/mdm) utilisée dans les scripts d’install et parc.nix.
     * Priorité : réglage admin → fallback requête (ou argument).
     */
    public function publicHubUrl(?string $requestFallback = null): string
    {
        $configured = $this->configuredHubUrl();
        if ($configured !== '') {
            return $configured;
        }
        $fallback = $requestFallback !== null && $requestFallback !== ''
            ? $requestFallback
            : self::detectRequestHubUrl();
        return rtrim($fallback, '/');
    }

    /** URL du script d’install pour un profil. */
    public function installScriptUrl(string $slug = 'default', bool $isDefault = true): string
    {
        $hub = $this->publicHubUrl();
        if ($isDefault || $slug === '' || $slug === 'default') {
            return $hub . '/generated/install-on-client.sh';
        }
        return $hub . '/generated/profiles/' . rawurlencode($slug) . '/install-on-client.sh';
    }

    /**
     * Enregistre l’URL publique et régénère les scripts de tous les profils.
     * @return array{hub:string, regenerated:int}
     */
    public function savePublicHubUrl(string $raw): array
    {
        $hub = self::normalizeHubUrlInput($raw);
        $this->update(['public_hub_url' => $hub]);
        $effective = $this->publicHubUrl();
        $n = $this->regenerateAllProfileArtifacts($effective);
        return ['hub' => $effective, 'regenerated' => $n];
    }

    /** Régénère install-on-client.sh / parc.nix pour chaque profil. */
    public function regenerateAllProfileArtifacts(string $hubUrl): int
    {
        $hubUrl = rtrim($hubUrl, '/');
        $lib = dirname(__DIR__) . '/lib';
        if (!class_exists('Settings', false)) {
            require_once $lib . '/Settings.php';
        }
        if (!class_exists('NixGenerator', false)) {
            require_once $lib . '/NixGenerator.php';
        }
        $settingsPath = dirname(__DIR__) . '/data/settings.json';
        $generatedDir = dirname(__DIR__) . '/public/generated';
        $settings = new \Settings($settingsPath);
        $generator = new \NixGenerator($generatedDir);
        $n = 0;
        foreach ($settings->listProfiles() as $p) {
            $id = (int) ($p['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $settings->loadProfile($id);
            $settings->setHubUrl($hubUrl);
            $generator->generate($settings->all(), [
                'baseUrl' => $hubUrl,
                'profileSlug' => $settings->slug(),
                'isDefault' => $settings->isDefault(),
            ]);
            $n++;
        }
        return $n;
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

    /**
     * @param array<string, mixed> $patch
     */
    public function update(array $patch): void
    {
        foreach ($patch as $k => $v) {
            $this->data[(string) $k] = $v;
        }
        $this->data['updatedAt'] = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $this->persist();
    }

    /** @return array{logo_url:string,icon_url:string,logo_alt:string,org_name:string,product_name:string,has_custom_logo:bool,has_custom_icon:bool} */
    public function branding(): array
    {
        $org = trim((string) ($this->data['org_name'] ?? ''));
        $product = trim((string) ($this->data['product_name'] ?? ''));
        if ($product === '') {
            $product = 'Linux MDM';
        }
        $alt = trim((string) ($this->data['logo_alt'] ?? ''));
        if ($alt === '') {
            $alt = $org !== '' ? $org : $product;
        }

        $logoRel = (string) ($this->data['logo_file'] ?? '');
        $iconRel = (string) ($this->data['icon_file'] ?? '');
        $logoUrl = $this->publicUrl($logoRel) ?? '/assets/logo.svg';
        $iconUrl = $this->publicUrl($iconRel)
            ?? ($this->publicUrl($logoRel) ?? '/assets/logo-icon.png');

        return [
            'logo_url' => $logoUrl,
            'icon_url' => $iconUrl,
            'logo_alt' => $alt,
            'org_name' => $org !== '' ? $org : 'Université de Tours',
            'product_name' => $product,
            'has_custom_logo' => $logoRel !== '' && is_file($this->absolutePath($logoRel)),
            'has_custom_icon' => $iconRel !== '' && is_file($this->absolutePath($iconRel)),
        ];
    }

    /** Chemin filesystem du logo à embarquer (Centre Logiciels desktop), ou null. */
    public function logoFilesystemPath(): ?string
    {
        foreach (['icon_file', 'logo_file'] as $key) {
            $rel = (string) ($this->data[$key] ?? '');
            if ($rel === '') {
                continue;
            }
            $abs = $this->absolutePath($rel);
            if (is_file($abs)) {
                return $abs;
            }
        }
        $fallback = [
            APP_ROOT . '/assets/logo-icon.png',
            APP_ROOT . '/assets/logo.png',
            APP_ROOT . '/assets/logo.svg',
        ];
        foreach ($fallback as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file $_FILES entry
     */
    public function saveLogoUpload(array $file, string $kind = 'logo'): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Aucun fichier reçu ou erreur d’upload.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Fichier trop volumineux (max. 2 Mo).');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Upload invalide.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $extMap = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/gif' => 'gif',
        ];
        if (!isset($extMap[$mime])) {
            throw new InvalidArgumentException('Format non supporté (PNG, JPG, WebP, SVG, GIF).');
        }
        $ext = $extMap[$mime];
        $kind = $kind === 'icon' ? 'icon' : 'logo';
        $dir = ensure_dir(APP_ROOT . '/' . self::BRANDING_DIR);
        $name = $kind . '.' . $ext;
        $dest = $dir . '/' . $name;

        // Supprime anciennes variantes du même kind
        foreach (glob($dir . '/' . $kind . '.*') ?: [] as $old) {
            if (is_file($old)) {
                @unlink($old);
            }
        }
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Impossible d’enregistrer le fichier.');
        }
        @chmod($dest, 0644);

        $rel = self::BRANDING_DIR . '/' . $name;
        if ($kind === 'icon') {
            $this->update(['icon_file' => $rel]);
        } else {
            $this->update(['logo_file' => $rel]);
        }
        return $rel;
    }

    public function resetLogo(string $kind = 'logo'): void
    {
        $kind = $kind === 'icon' ? 'icon' : 'logo';
        $key = $kind === 'icon' ? 'icon_file' : 'logo_file';
        $rel = (string) ($this->data[$key] ?? '');
        if ($rel !== '') {
            $abs = $this->absolutePath($rel);
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->data[$key] = '';
        $this->data['updatedAt'] = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        $this->persist();
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'org_name' => 'Université de Tours',
            'product_name' => 'Linux MDM',
            'logo_alt' => 'Université de Tours',
            'logo_file' => '',
            'icon_file' => '',
            /** URL publique du hub (https://…/mdm). Vide = détection automatique. */
            'public_hub_url' => '',
            'updatedAt' => null,
        ];
    }

    private function ensureRow(): void
    {
        $n = (int) $this->pdo->query('SELECT COUNT(*) FROM mdm_app_settings')->fetchColumn();
        if ($n > 0) {
            return;
        }
        $json = json_encode(self::defaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare('INSERT INTO mdm_app_settings (id, data) VALUES (1, :data)');
        $stmt->execute(['data' => $json]);
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $row = $this->pdo->query('SELECT data FROM mdm_app_settings WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        $decoded = [];
        if ($row && isset($row['data'])) {
            $tmp = json_decode((string) $row['data'], true);
            if (is_array($tmp)) {
                $decoded = $tmp;
            }
        }
        return array_merge(self::defaults(), $decoded);
    }

    private function persist(): void
    {
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Impossible d’encoder les réglages.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE mdm_app_settings SET data = :data, updated_at = NOW() WHERE id = 1'
        );
        $stmt->execute(['data' => $json]);
    }

    private function absolutePath(string $rel): string
    {
        $rel = ltrim(str_replace(['..', '\\'], '', $rel), '/');
        return APP_ROOT . '/' . $rel;
    }

    private function publicUrl(string $rel): ?string
    {
        $rel = trim($rel);
        if ($rel === '') {
            return null;
        }
        $abs = $this->absolutePath($rel);
        if (!is_file($abs)) {
            return null;
        }
        return '/' . ltrim(str_replace('\\', '/', $rel), '/') . '?v=' . (string) filemtime($abs);
    }
}
