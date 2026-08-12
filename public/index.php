<?php

declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
(new Catch\Core\Application($root))->run();
