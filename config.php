<?php
// Surcharger ces valeurs dans config.local.php (non versionné) plutôt que de modifier ce fichier.
$dbHost = getenv('NIXVM_DB_HOST');

return [
    // Base MariaDB/MySQL : variables d'environnement (docker compose) sinon fichier .devbox-db.php.
    'db' => $dbHost ? [
        'dsn' => sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbHost,
            (int) (getenv('NIXVM_DB_PORT') ?: 3306),
            getenv('NIXVM_DB_NAME') ?: 'nixvm',
        ),
        'user' => getenv('NIXVM_DB_USER') ?: 'nixvm',
        'password' => (string) getenv('NIXVM_DB_PASSWORD'),
    ] : null,
    'db_file' => __DIR__ . '/.devbox-db.php',

    'build' => [
        // Chemin vers le binaire nix. null = détection automatique dans le PATH et /nix/var/nix/profiles.
        'nix_bin' => null,
        // Options passées à chaque `nix build` (ex: '--builders ssh://builder x86_64-linux' pour un builder distant).
        'extra_args' => '',
        'php_bin' => PHP_BINARY,
        'max_parallel' => 1,
        // Espace libre minimal (Go) avant un build ; en dessous, nettoyage du store puis avertissement.
        // Un échec dur n'intervient qu'en dessous de hard_min_free_gb.
        'min_free_gb' => 12,       // dossier des builds temporaires (build-dir)
        'min_free_store_gb' => 8,  // /nix (nouveaux paquets téléchargés + image finale)
        'hard_min_free_gb' => 6,   // en dessous : refus du build
    ],

    'search' => [
        'endpoint' => 'https://search.nixos.org/backend',
        // Identifiants publics utilisés par le frontend de search.nixos.org.
        'user' => 'aWVSALXpZv',
        'password' => 'X8gPHnzL52wFEekuxsfQ9cSh',
        'timeout' => 8,
    ],
];
