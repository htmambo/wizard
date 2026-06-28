<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

/**
 * CDN 资源路径助手(T7)。
 *
 * 替代 helpers.php::cdn_resource()。
 */
class CdnHelper
{
    /**
     * 资源地址 CDN 加速。
     *
     * 静态缓存 enabled/url 配置;不支持 CDN 的资源走公共 CDN(font-awesome/material-icons)。
     */
    public static function resource(string $resourceUrl): string
    {
        static $enabled = null;
        static $cdnUrl = null;

        if ($enabled === null) {
            $enabled = config('wizard.cdn.enabled', false);
        }

        if (!$enabled) {
            return $resourceUrl . '?' . ConfigHelper::resourceVersion();
        }

        if ($cdnUrl === null) {
            $cdnUrl = rtrim(config('wizard.cdn.url'), '/');
        }

        // font-awesome / material-icons 走公共 CDN(避免跨域)
        $replace = [
            '/assets/vendor/font-awesome4/css/font-awesome.min.css'
                => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css',
            '/assets/vendor/material-design-icons/material-icons.css'
                => 'https://cdnjs.cloudflare.com/ajax/libs/material-design-icons/3.0.1/iconfont/material-icons.min.css',
        ];

        if (isset($replace[$resourceUrl])) {
            return $replace[$resourceUrl];
        }

        return "{$cdnUrl}{$resourceUrl}";
    }
}
