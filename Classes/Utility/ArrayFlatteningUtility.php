<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Utility;

/**
 * Utility to deflate and enflate an array of named strings that may be nested by using a seperator
 * that must never be used in the array keys
 */
class ArrayFlatteningUtility {

    /**
     * @param array<string, string|array<string,string>> $array to deflate
     * @param string $seperator seperator that is not used in array keys
     * @return array<string, string>
     */
    public static function deflate(array $array, string $seperator = '.'): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $value;
            } elseif(is_array($value)) {
                foreach ($value as $subkey => $subvalue) {
                    $result[$key . $seperator . $subkey] = $subvalue;
                }
            }
        }
        return $result;
    }

    /**
     * @param array<string, string> $array to enflate
     * @param string $seperator seperator that is not used in array keys
     * @return array<string, string|array<string,string>>
     */
    public static function enflate(array $array, string $seperator = '.'): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (str_contains($key, $seperator)) {
                list($mainKey, $subKey) = explode($seperator, $key, 2);
                if (array_key_exists($mainKey, $result)) {
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
