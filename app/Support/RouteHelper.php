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
 * 路由 URL 生成助手(T7)。
 *
 * 替代 helpers.php::wzRoute(),保持全局函数兼容。
 */
class RouteHelper
{
    /**
     * 生成路由 URL,对参数做 urlencode,https 请求自动升级协议。
     */
    public static function url(string $name, array $parameters = [], bool $absolute = false): string
    {
        foreach ($parameters as $k => $v) {
            $parameters[$k] = urlencode($v);
        }
        $result = route($name, $parameters, $absolute);
        if ($absolute && request()->isSecure()) {
            $result = Str::replaceFirst('http://', 'https://', $result);
        }
        return $result;
    }
}
