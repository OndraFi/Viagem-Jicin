<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$districtCode = $argv[1] ?? null;
if (!is_string($districtCode) || !ctype_digit($districtCode)) {
    throw new InvalidArgumentException('Usage: php bin/enable-district.php <district-code>');
}

$statement = App\Config\Database::connect()->prepare(<<<'SQL'
    UPDATE cadastral_units
    SET enabled = TRUE, updated_at = NOW()
    WHERE district_code = :district_code
      AND valid_to IS NULL
      AND enabled = FALSE
SQL);
$statement->execute(['district_code' => (int) $districtCode]);
fwrite(STDOUT, "Enabled {$statement->rowCount()} cadastral units in district {$districtCode}." . PHP_EOL);
