<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = App\Config\Database::connect();
(new App\Import\CadastralUnitSynchronizer($pdo))->synchronize();

$statement = $pdo->prepare(<<<'SQL'
    UPDATE cadastral_units
    SET enabled = TRUE, updated_at = NOW()
    WHERE district_code = 3604
      AND valid_to IS NULL
      AND enabled = FALSE
SQL);
$statement->execute();
fwrite(STDOUT, "Enabled {$statement->rowCount()} current cadastral units in district 3604." . PHP_EOL);
(new App\Import\CpxImporter($pdo))->importEnabledUnits(onlyMissing: true);
