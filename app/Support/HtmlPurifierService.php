<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * HTML 净化服务封装(基于 ezyang/htmlpurifier)。
 *
 * 使用方式:
 *   - 写入路径(Repository saving): HtmlPurifierService::clean($html)
 *   - Blade 模板: 不再调净化函数,直接输出已净化的内容
 *
 * AD5: 写入时净化、渲染时直接输出,避免运行时开销。
 */
class HtmlPurifierService
{
    private static ?HTMLPurifier $instance = null;

    /**
     * 净化 HTML 字符串,返回安全 HTML。
     *
     * 空字符串 / null 直接返回,不做处理。
     */
    public static function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return (string) $html;
        }
        return self::getInstance()->purify($html);
    }

    /**
     * 获取单例 HTMLPurifier(惰性初始化,缓存配置以提升性能)。
     */
    private static function getInstance(): HTMLPurifier
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = HTMLPurifier_Config::createDefault();
        // 默认 UTF-8,设置字符集
        $config->set('Core.Encoding', 'UTF-8');
        // 禁用缓存目录(用内存缓存,适合 FPM 长生命周期进程)
        $config->set('Cache.SerializerPath', null);

        // URL 协议白名单(防止 javascript:/data:/vbscript:)
        $config->set('URI.AllowedSchemes', [
            'http'   => true,
            'https'  => true,
            'mailto' => true,
        ]);

        // 自动为外链添加 rel="noopener noreferrer" + target="_blank"
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);

        // CSS 属性白名单:只允许排版相关,禁止 expression/javascript 协议
        $config->set('CSS.AllowedProperties', [
            'color', 'background-color', 'text-align', 'font-weight',
            'font-style', 'text-decoration', 'width', 'height', 'margin', 'padding',
        ]);

        // 显式禁用表单/输入元素(最小权限原则,默认 DTD 包含 form)
        $config->set('HTML.ForbiddenElements', [
            'form', 'input', 'button', 'select', 'textarea',
            'fieldset', 'legend', 'label', 'optgroup', 'option',
        ]);

        // 注意:不要覆盖 HTML.Allowed(它会清空 DTD,丢失 table[height] 等遗留属性支持)。
        // 默认 XHTML 1.0 Transitional 已包含富文本所需全部标签(a/p/img/table/span/div 等)。
        // XSS 面已通过:
        //   - URI.AllowedSchemes(禁 javascript:)
        //   - HTML.TargetBlank/Nofollow(自动加 rel 防 opener)
        //   - CSS.AllowedProperties(禁 expression)
        //   - HTML.ForbiddenElements(禁 form/input 等)

        return self::$instance = new HTMLPurifier($config);
    }
}
