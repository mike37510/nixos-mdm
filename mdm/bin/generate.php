<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Settings.php';
require_once dirname(__DIR__) . '/lib/NixGenerator.php';

$root = dirname(__DIR__);
$baseUrl = $argv[1] ?? 'https://localhost/mdm';
$settings = new Settings($root . '/data/settings.json');
$generator = new NixGenerator($root . '/public/generated');
$paths = $generator->generate($settings->all(), [
    'baseUrl' => rtrim($baseUrl, '/'),
    'profileSlug' => $settings->slug(),
    'isDefault' => $settings->isDefault(),
]);

echo "Generated (profile #{$settings->profileId()} {$settings->slug()}):\n";
foreach ($paths as $label => $path) {
    echo " - {$label}: {$path}\n";
}
