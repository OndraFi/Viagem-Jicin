<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(new App\Import\CadastralUnitSynchronizer(App\Config\Database::connect()))->synchronize();
