<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

/**
 * 内容格式化助手(T7)。
 *
 * 替代 helpers.php 中 formatHtml() / processMarkdown() / convertSqlTo*().
 */
class FormatHelper
{
    /**
     * 格式化 HTML 代码以方便前端差异对比。
     */
    public static function formatHtml(string $content = ''): string
    {
        if (!$content) {
            return $content;
        }
        $content = preg_replace('@<\s+@i', '<', $content);
        $content = preg_replace('@\s+>@i', '>', $content);
        $content = preg_replace('@\s+/>@i', ' />', $content);

        $content = preg_replace('@<p([^>]*)>(\s|&nbsp;)+@i', '<p\\1>', $content);
        $content = preg_replace('@<p>(\s|&nbsp;)+@i', '<p>', $content);
        $content = preg_replace('@<br([^>]*)>(\s|&nbsp;)+@i', '<br\\1>' . PHP_EOL, $content);
        $content = preg_replace('@<br([^>]*)>\s*</p>@i', '</p>' . PHP_EOL, $content);

        $content = preg_replace('@<p>\s*<br([^>]*)>\s*@i', '<p>', $content);

        $content = preg_replace('@<p([^>]*)>\s*</p>@i', '' . PHP_EOL, $content);

        $content = preg_replace('@\s*<pre([^>]*)>@i', "\n<pre\\1>", $content);
        $content = preg_replace('@</pre>\s*@i', "</pre>\n", $content);

        $content = preg_replace('@\s*<p>@i', "\n<p>", $content);
        $content = preg_replace('@</p>\s*@i', "</p>\n", $content);
        $content = preg_replace('@</p>\s+<p>@i', "</p>\n<p>", $content);
        return $content;
    }

    /**
     * Markdown 预处理:自动添加 TOC/TOCM 标签。
     */
    public static function processMarkdown(?string $markdown = null): string
    {
        if (is_null($markdown)) {
            $markdown = '';
        }

        $defaultTOC = config('wizard.markdown.default_toc');
        if (!in_array($defaultTOC, ['TOC', 'TOCM'], true)) {
            return $markdown;
        }

        if (\Illuminate\Support\Str::contains($markdown, ['[TOC]', '[TOCM]'])) {
            return $markdown;
        }

        return "[{$defaultTOC}]\n\n{$markdown}";
    }
}
