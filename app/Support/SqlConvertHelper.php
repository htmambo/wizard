<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

/**
 * SQL/JSON 转 Markdown 表格助手(T7)。
 *
 * 替代 helpers.php::convertJsonToMarkdownTable() / convertSqlTo*()。
 */
class SqlConvertHelper
{
    /**
     * 转换 JSON 为 Markdown 表格。
     */
    public static function jsonToMarkdownTable(string $json): string
    {
        $markdowns = [
            ['参数名', '类型', '是否必须', '说明'],
            ['---', '---', '---', '---'],
        ];

        foreach (JsonFlattenHelper::flatten($json) as $key => $type) {
            $markdowns[] = [$key, $type, '', ''];
        }

        $html = '';
        foreach ($markdowns as $line) {
            $html .= '| ' . implode(' | ', $line) . ' | ' . "\n";
        }
        return $html;
    }

    /**
     * 转换 SQL 为 Markdown 表格(完整 CREATE TABLE 语句)。
     */
    public static function sqlToMarkdownTable(string $sql): string
    {
        return self::sqlTo($sql, function ($markdowns, $tableName, $tableComment) {
            if (empty($markdowns)) {
                return '';
            }
            $headers = [
                ['字段', '类型', '空', '说明'],
                ['---', '---', '---', '---'],
            ];
            array_unshift($markdowns, ...$headers);

            $html = '';
            foreach ($markdowns as $line) {
                $html .= '| ' . implode(' | ', $line) . ' | ' . "\n";
            }
            return "\n表名：**{$tableName}**   备注：*{$tableComment}*\n\n{$html}\n";
        });
    }

    /**
     * 转换 SQL 为 HTML 表格。
     */
    public static function sqlToHTMLTable(string $sql): string
    {
        return self::sqlTo($sql, function ($markdowns, $tableName, $tableComment) {
            if (empty($markdowns)) {
                return '';
            }
            $html = '';
            foreach ($markdowns as $line) {
                $html .= '<tr><td>' . implode('</td><td>', $line) . "</td></tr>";
            }
            return <<<HEADER
<p><div style="float: left;" class="wz-table-name">❖ 表名： <b>{$tableName}</b></div>
<div style="float: right;" class="wz-table-desc">❖ 备注：<i>{$tableComment}</i></div>
</p>
<table class="table table-hover">
    <thead>
        <tr>
           <th>字段</th>
           <th>类型</th>
           <th>空</th>
           <th>说明</th>
        </tr>
    </thead>
    <tbody>{$html}</tbody>
</thead>
</table>
HEADER;
        });
    }

    /**
     * 通用 SQL 解析:解析 CREATE TABLE 提取字段列表,调用 callback 生成不同格式。
     *
     * T7 审核改进:从 helpers.php 内联迁入,SqlConvertHelper 自包含,
     * 不再依赖 helpers.php 中的 convertSqlTo 私有函数。
     */
    public static function sqlTo(string $sql, $callback): ?string
    {
        try {
            $sql .= "\n";
            if (preg_match_all('@\)\s*ENGINE\s*=.+COMMENT\s*=\s*[^\n]+\n@i', $sql, $match)) {
                foreach ($match as $v) {
                    $str = $v[0];
                    $rep = preg_replace('@\s+COMMENT\s*=\s*@', ' COMMENT ', $str);
                    $sql = str_replace($str, $rep, $sql);
                }
            }
            $sql    = trim($sql);
            $parser = new \PHPSQLParser\PHPSQLParser();
            $parsed = $parser->parse($sql);

            if (!isset($parsed['CREATE'])) {
                return null;
            }

            if ($parsed['CREATE']['expr_type'] === 'table') {
                $fields    = $parsed['TABLE']['create-def']['sub_tree'];
                $tableName = $parsed['TABLE']['base_expr'];

                $markdowns = [];
                foreach ($fields as $field) {
                    if ($field['sub_tree'][0]['expr_type'] === 'constraint') {
                        continue;
                    }
                    if (!isset($field['sub_tree'][1]['sub_tree'])) {
                        continue;
                    }

                    $type = $length = '';
                    foreach ($field['sub_tree'][1]['sub_tree'] as $item) {
                        if ($item['expr_type'] === 'data-type') {
                            $type   = $item['base_expr'] ?? '';
                            $length = $item['length'] ?? '';
                        }
                    }

                    $name     = $field['sub_tree'][0]['base_expr'];
                    $comment  = trim($field['sub_tree'][1]['comment'] ?? '', "'");
                    $nullable = $field['sub_tree'][1]['nullable'] ?? false;

                    $type        = empty($length) ? $type : "{$type} ($length)";
                    $markdowns[] = [trim($name, '`'), $type, $nullable ? 'Y' : 'N', $comment];
                }

                $tableComment = '-';
                $options      = $parsed['TABLE']['options'] ?? [];
                if (!$options || empty($options)) {
                    $options = [];
                }
                foreach ($options as $option) {
                    $type = strtoupper($option['sub_tree'][0]['base_expr'] ?? '');
                    if ($type === 'COMMENT') {
                        $tableComment = trim($option['sub_tree'][1]['base_expr'] ?? '', "'");
                        break;
                    }
                }
                return $callback($markdowns, trim($tableName, '`'), $tableComment);
            }

            return '';
        } catch (\Exception $ex) {
            return "{$ex->getMessage()} @{$ex->getFile()}:{$ex->getLine()}";
        }
    }
}
