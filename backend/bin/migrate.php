<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = App\Config\Database::connect();
$files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];
sort($files);
foreach ($files as $file) {
    $pdo->exec((string) file_get_contents($file));
    fwrite(STDOUT, 'Applied ' . basename($file) . PHP_EOL);
}
