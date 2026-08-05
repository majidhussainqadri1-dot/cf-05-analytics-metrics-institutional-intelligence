<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Domain;

final class Statistics
{
    /** @param array<int,float|int> $values */
    public static function mean(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        return array_sum($values) / count($values);
    }

    /** @param array<int,float|int> $values */
    public static function variance(array $values): ?float
    {
        if (count($values) < 2) {
            return null;
        }
        $mean = self::mean($values);
        if ($mean === null) {
            return null;
        }
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ((float) $value - $mean) ** 2;
        }
        return $sum / (count($values) - 1);
    }

    /** @return array{estimate:float,lower:float,upper:float,standard_error:float} */
    public static function proportionInterval(int $successes, int $total, float $z = 1.96): array
    {
        if ($total < 1 || $successes < 0 || $successes > $total) {
            throw new \InvalidArgumentException('Invalid proportion.');
        }
        $p = $successes / $total;
        $den = 1 + (($z ** 2) / $total);
        $center = ($p + (($z ** 2) / (2 * $total))) / $den;
        $margin = ($z / $den) * sqrt(($p * (1 - $p) / $total) + (($z ** 2) / (4 * ($total ** 2))));
        return [
            'estimate' => $p,
            'lower' => max(0.0, $center - $margin),
            'upper' => min(1.0, $center + $margin),
            'standard_error' => sqrt(max(0.0, $p * (1 - $p) / $total)),
        ];
    }

    /** @return array{difference:float,z_score:?float,conclusive:bool,practical:bool} */
    public static function compareProportions(int $aSuccess, int $aTotal, int $bSuccess, int $bTotal, float $minimumEffect = 0.0): array
    {
        if ($aTotal < 1 || $bTotal < 1) {
            throw new \InvalidArgumentException('Both groups require observations.');
        }
        $a = $aSuccess / $aTotal;
        $b = $bSuccess / $bTotal;
        $pooled = ($aSuccess + $bSuccess) / ($aTotal + $bTotal);
        $se = sqrt(max(0.0, $pooled * (1 - $pooled) * ((1 / $aTotal) + (1 / $bTotal))));
        $z = $se > 0 ? ($b - $a) / $se : null;
        return [
            'difference' => $b - $a,
            'z_score' => $z,
            'conclusive' => $z !== null && abs($z) >= 1.96,
            'practical' => abs($b - $a) >= max(0.0, $minimumEffect),
        ];
    }
}
