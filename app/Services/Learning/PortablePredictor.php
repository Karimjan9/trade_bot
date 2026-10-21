<?php

namespace App\Services\Learning;

class PortablePredictor
{
    public static function scope(array $plan): string
    {
        return $plan['symbol'].':'.$plan['direction'].':'.$plan['family'];
    }

    public function predict(array $artifact, array $features, string $scope): array
    {
        $classifier = $artifact['classifier']['intercept'];
        $expected = $artifact['regressor']['intercept'];
        $contributions = [];
        $maxZ = 0;
        foreach ($artifact['features'] as $i => $key) {
            $value = $features[$key] ?? $artifact['impute'][$i];
            $z = ($value - $artifact['mean'][$i]) / $artifact['scale'][$i];
            $maxZ = max($maxZ, abs($z));
            $classifier += $z * $artifact['classifier']['weights'][$i];
            $contribution = $z * $artifact['regressor']['weights'][$i];
            $expected += $contribution;
            $contributions[$key] = $contribution;
        }
        $logit = $classifier * $artifact['calibrator']['weight'] + $artifact['calibrator']['intercept'];
        $probability = 1 / (1 + exp(-max(-35, min(35, $logit))));
        uasort($contributions, fn ($a, $b) => abs($b) <=> abs($a));
        $supported = in_array($scope, $artifact['scopes'], true) && $maxZ <= $artifact['max_abs_z'];

        return ['probability' => $probability, 'expected_r' => $expected, 'supported' => $supported,
            'action' => ! $supported ? 'ABSTAIN' : ($probability >= $artifact['threshold'] && $expected > 0 ? 'ALLOW' : 'SKIP'),
            'explanation' => ['contributions_r' => array_slice($contributions, 0, 5, true), 'max_abs_z' => $maxZ, 'threshold' => $artifact['threshold'], 'scope' => $scope]];
    }
}
