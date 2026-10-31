<?php

declare(strict_types=1);

/**
 * Comptes hub Linux MDM (admin / dsi).
 */
final class Users
{
    public const ROLES = ['admin', 'dsi'];

    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \NixVm\Db::pdo();
    }

    /** @return list<array{id:int,username:string,role:string,created_at:string}> */
    public function listAll(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, username, role, created_at FROM mdm_users ORDER BY role ASC, username ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'role' => (string) $row['role'],
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array{id:int,username:string,role:string,created_at:string}|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, username, role, created_at FROM mdm_users WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    public function countAdmins(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM mdm_users WHERE role = 'admin'"
        )->fetchColumn();
    }

    /**
     * @return array{id:int,username:string,role:string}
     */
    public function create(string $username, string $password, string $role = 'dsi'): array
    {
        $username = $this->normalizeUsername($username);
        $role = $this->normalizeRole($role);
        $this->assertPassword($password);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO mdm_users (username, password_hash, role) VALUES (:u, :p, :r)'
        );
        try {
            $stmt->execute(['u' => $username, 'p' => $hash, 'r' => $role]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || (int) $e->getCode() === 23000) {
                throw new InvalidArgumentException('Ce nom d’utilisateur existe déjà.');
            }
            throw $e;
        }
        $id = (int) $this->pdo->lastInsertId();
        return ['id' => $id, 'username' => $username, 'role' => $role];
    }

    public function setRole(int $id, string $role): void
    {
        $user = $this->requireUser($id);
        $role = $this->normalizeRole($role);
        if ($user['role'] === 'admin' && $role !== 'admin' && $this->countAdmins() <= 1) {
            throw new InvalidArgumentException('Impossible : il doit rester au moins un administrateur.');
        }
        $stmt = $this->pdo->prepare('UPDATE mdm_users SET role = :r WHERE id = :id');
        $stmt->execute(['r' => $role, 'id' => $id]);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->requireUser($id);
        $this->assertPassword($password);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare('UPDATE mdm_users SET password_hash = :p WHERE id = :id');
        $stmt->execute(['p' => $hash, 'id' => $id]);
    }

    public function delete(int $id, ?int $actorId = null): void
    {
        $user = $this->requireUser($id);
        if ($actorId !== null && $actorId === $id) {
            throw new InvalidArgumentException('Vous ne pouvez pas supprimer votre propre compte.');
        }
        if ($user['role'] === 'admin' && $this->countAdmins() <= 1) {
            throw new InvalidArgumentException('Impossible de supprimer le dernier administrateur.');
        }
        $stmt = $this->pdo->prepare('DELETE FROM mdm_users WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'admin' => 'Administrateur',
            'dsi' => 'DSI',
            default => $role,
        };
    }

    /** @return array{id:int,username:string,role:string,created_at:string} */
    private function requireUser(int $id): array
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new InvalidArgumentException('Utilisateur introuvable.');
        }
        return $user;
    }

    private function normalizeUsername(string $username): string
    {
        $username = trim($username);
        if ($username === '' || strlen($username) > 64) {
            throw new InvalidArgumentException('Identifiant invalide (1–64 caractères).');
        }
        if (!preg_match('/^[a-zA-Z0-9._@+-]+$/', $username)) {
            throw new InvalidArgumentException(
                'Identifiant : lettres, chiffres et . _ @ + - uniquement.'
            );
        }
        return $username;
    }

    private function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Rôle invalide (admin ou dsi).');
        }
        return $role;
    }

    private function assertPassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Mot de passe trop court (8 caractères minimum).');
        }
        if (strlen($password) > 200) {
            throw new InvalidArgumentException('Mot de passe trop long.');
        }
    }
}
