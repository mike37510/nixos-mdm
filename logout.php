<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NixVm\Auth;

Auth::logout();
header('Location: /login.php');
exit;
