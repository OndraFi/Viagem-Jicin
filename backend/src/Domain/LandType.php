<?php
declare(strict_types=1);

namespace App\Domain;

final class LandType
{
    /** @var array<string, string> */
    private const LABELS = [
        'ArableGround' => 'Orná půda',
        'Hopgarden' => 'Chmelnice',
        'Vineyard' => 'Vinice',
        'Garden' => 'Zahrada',
        'Orchard' => 'Ovocný sad',
        'Grassland' => 'Trvalý travní porost',
        'Forest' => 'Lesní pozemek',
        'WaterArea' => 'Vodní plocha',
        'BuiltUpArea' => 'Zastavěná plocha a nádvoří',
        'OtherArea' => 'Ostatní plocha',
    ];

    public static function label(?string $code): ?string
    {
        return $code === null ? null : (self::LABELS[$code] ?? $code);
    }
}
