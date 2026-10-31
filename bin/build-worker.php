<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

set_time_limit(0);
$buildId = (int) ($argv[1] ?? 0);
if ($buildId <= 0) {
    fwrite(STDERR, "Usage: php bin/build-worker.php <build-id>\n");
    exit(2);
}

NixVm\Builder::run($buildId);
