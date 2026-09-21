<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$refresh = in_array('--refresh', $argv, true);
(new App\Import\CpxImporter(App\Config\Database::connect()))->importEnabledUnits($refresh);
