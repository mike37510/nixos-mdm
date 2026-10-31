<?php
declare(strict_types=1);

namespace NixVm;

use PDO;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('linuxmdm');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function ensureSeeded(): void
    {
        $pdo = Db::pdo();
        $n = (int) $pdo->query('SELECT COUNT(*) FROM mdm_users')->fetchColumn();
        if ($n > 0) {
            return;
        }
        $user = trim((string) (getenv('NIXVM_WEB_USER') ?: 'admin'));
        $pass = (string) (getenv('NIXVM_WEB_PASSWORD') ?: '');
        if ($pass === '') {
            $pass = 'admin';
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO mdm_users (username, password_hash, role) VALUES (:u, :p, :r)'
        );
        $stmt->execute(['u' => $user, 'p' => $hash, 'r' => 'admin']);
    }

    public static function attempt(string $username, string $password): bool
    {
        self::start();
        self::ensureSeeded();
        $stmt = Db::pdo()->prepare(
            'SELECT id, username, password_hash, role FROM mdm_users WHERE username = :u LIMIT 1'
        );
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
        ];
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    /** @return array{id:int,username:string,role:string}|null */
    public static function user(): ?array
    {
        self::start();
        $u = $_SESSION['user'] ?? null;
        return is_array($u) ? $u : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/mdm/');
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $isApi = str_starts_with($uri, '/api.php')
            || str_contains($accept, 'application/json');
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Non authentifié.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: /login.php?next=' . rawurlencode($uri));
        exit;
    }

    public static function role(): ?string
    {
        $u = self::user();
        return $u !== null ? (string) $u['role'] : null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** Exige un compte administrateur (après connexion). */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (self::isAdmin()) {
            return;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/mdm/');
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $isApi = str_starts_with($uri, '/api.php')
            || str_contains($accept, 'application/json');
        if ($isApi) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Accès réservé aux administrateurs.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Accès refusé</title></head><body>';
        echo '<p>Accès réservé aux administrateurs.</p><p><a href="/mdm/">Retour</a></p></body></html>';
        exit;
    }

    /** Met à jour la session si l’utilisateur courant a changé de rôle / nom. */
    public static function refreshSessionUser(int $id, string $username, string $role): void
    {
        self::start();
        $u = self::user();
        if ($u === null || (int) $u['id'] !== $id) {
            return;
        }
        $_SESSION['user'] = [
            'id' => $id,
            'username' => $username,
            'role' => $role,
        ];
    }
}
