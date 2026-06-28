<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use Illuminate\Support\Str;

/**
 * JSON 扁平化助手(T7)。
 *
 * 替代 helpers.php::jsonFlatten(),把嵌套 JSON 转为一维 [path => type]。
 */
class JsonFlattenHelper
{
    /**
     * 将 JSON 字符串扁平化为 [path => type]。
     */
    public static function flatten(string $json): array
    {
        $object = json_decode(trim($json));
        $result = [];

        $setCurrent = function ($prefix, $type) use (&$result) {
            if (Str::endsWith($prefix, '.[]')) {
                $result[substr($prefix, 0, -3)] = "array({$type})";
            } else {
                $result[$prefix] = $type;
            }
        };

        $flatten = function ($object, $prefix = '') use (&$flatten, &$result, $setCurrent) {
            $setCurrent($prefix, gettype($object));
            if (is_array($object)) {
                foreach ($object as $o) {
                    $flatten($o, "{$prefix}.[]");
                }
            } elseif (is_object($object)) {
                foreach (get_object_vars($object) as $key => $obj) {
                    $flatten($obj, "{$prefix}.{$key}");
                }
            } else {
                $setCurrent($prefix, gettype($object));
            }
        };

        if (is_array($object)) {
            $flatten($object, '');
        } elseif (is_object($object)) {
            foreach (get_object_vars($object) as $key => $obj) {
                $flatten($obj, $key);
            }
        }

        return $result;
    }
}
