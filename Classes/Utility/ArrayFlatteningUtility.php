<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Utility;

/**
 * Utility to deflate and enflate an array of named strings that may be nested by using a seperator
 * that must never be used in the array keys
 */
class ArrayFlatteningUtility
{
    private const SEPERATOR = '.';

    /**
     * @param array<non-empty-string, string|array<non-empty-string, string>> $array to deflate
     * @return array<non-empty-string, string>
     */
    public static function deflate(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            assert($key !== '');
            if (is_string($value)) {
                $result[$key] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $subkey => $subvalue) {
                    $result[$key . self::SEPERATOR . $subkey] = $subvalue;
                }
            }
        }
        return $result;
    }

    /**
     * @param array<non-empty-string, string> $array to enflate
     * @return array<non-empty-string, string|array<non-empty-string, string>>
     */
    public static function enflate(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            assert($key !== '');
            if (str_contains($key, self::SEPERATOR)) {
                list($mainKey, $subKey) = explode(self::SEPERATOR, $key, 2);
                assert($mainKey !== '');
                assert($subKey !== '');
                if (array_key_exists($mainKey, $result) && is_array($result[$mainKey])) {
                    $result[$mainKey][$subKey] = $value;
                } else {
                    $result[$mainKey] = [$subKey => $value];
                }
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
