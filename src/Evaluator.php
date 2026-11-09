<?php

namespace ABPlatform;

/** Protocol v1 evaluator only. No networking or framework dependencies. */
final class Evaluator
{
    private static function validIdentifier($value)
    {
        return is_string($value) && $value !== '' && preg_match('//u', $value) === 1
            && strlen($value) <= 512;
    }

    public static function canonicalSeed($domain, $experimentId, $allocationVersion, $visitorId)
    {
        if (!in_array($domain, array('traffic', 'variant'), true)
            || !self::validIdentifier($experimentId) || !self::validIdentifier($visitorId)
            || !is_int($allocationVersion) || $allocationVersion < 1 || $allocationVersion > 2147483647) {
            throw new \InvalidArgumentException('Invalid assignment input');
        }
        $fields = array('vexo:assignment:v1', $domain, $experimentId, (string) $allocationVersion, $visitorId);
        $seed = '';
        foreach ($fields as $field) {
            $seed .= strlen($field) . ':' . $field;
        }
        return $seed;
    }

    public static function hashBucket($seed)
    {
        // Byte-wise modulo avoids unsigned integer overflow on 32-bit PHP builds.
        $hash = hash('sha256', $seed, true);
        $bucket = 0;
        for ($index = 0; $index < 4; $index++) {
            $bucket = ($bucket * 256 + ord($hash[$index])) % 10000;
        }
        return $bucket;
    }

    public function getVariant($experiment, $visitorId)
    {
        if (!$this->validExperiment($experiment) || !self::validIdentifier($visitorId)
            || $experiment['status'] !== 'running') {
            return null;
        }
        $traffic = self::hashBucket(self::canonicalSeed('traffic', $experiment['id'], $experiment['allocationVersion'], $visitorId));
        if ($traffic >= $experiment['trafficAllocation']) {
            return null;
        }
        $bucket = self::hashBucket(self::canonicalSeed('variant', $experiment['id'], $experiment['allocationVersion'], $visitorId));
        $upperBound = 0;
        foreach ($experiment['variants'] as $variant) {
            $upperBound += $variant['weight'];
            if ($bucket < $upperBound) {
                return $variant['key'];
            }
        }
        return null;
    }

    public function validExperiment($experiment)
    {
        if (!is_array($experiment) || array_diff(array_keys($experiment), array('id', 'key', 'status', 'allocationVersion', 'trafficAllocation', 'variants', 'targeting', 'experience'))
            || !isset($experiment['id'], $experiment['key'], $experiment['status'], $experiment['allocationVersion'], $experiment['trafficAllocation'], $experiment['variants'])
            || !self::validIdentifier($experiment['id']) || !self::validIdentifier($experiment['key'])
            || !in_array($experiment['status'], array('draft', 'running', 'paused', 'completed'), true)
            || !is_int($experiment['allocationVersion']) || $experiment['allocationVersion'] < 1 || $experiment['allocationVersion'] > 2147483647
            || !is_int($experiment['trafficAllocation']) || $experiment['trafficAllocation'] < 0 || $experiment['trafficAllocation'] > 10000
            || !is_array($experiment['variants']) || count($experiment['variants']) < 2 || count($experiment['variants']) > 16
            || array_keys($experiment['variants']) !== range(0, count($experiment['variants']) - 1)) {
            return false;
        }
        if(isset($experiment['experience']) && !Experience::valid($experiment['experience'])) { return false; }
        $sum = 0;
        $keys = array();
        foreach ($experiment['variants'] as $variant) {
            if (!is_array($variant) || array_diff(array_keys($variant), array('key', 'weight', 'payload'))
                || !isset($variant['key'], $variant['weight']) || !self::validIdentifier($variant['key'])
                || !is_int($variant['weight']) || $variant['weight'] < 0 || $variant['weight'] > 10000
                || in_array($variant['key'], $keys, true)
                || (isset($variant['payload']) && !is_array($variant['payload']) && !is_object($variant['payload']))) {
                return false;
            }
            $keys[] = $variant['key'];
            $sum += $variant['weight'];
        }
        return $sum === 10000;
    }
}
