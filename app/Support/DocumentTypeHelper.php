<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use App\Repositories\Document;

/**
 * 文档类型标识转换(T7)。
 *
 * 替代 helpers.php::documentType(),支持双向转换(常量 ↔ 字符串)。
 */
class DocumentTypeHelper
{
    private const TYPES = [
        Document::TYPE_HTML    => 'html',
        Document::TYPE_DOC     => 'markdown',
        Document::TYPE_SWAGGER => 'swagger',
        Document::TYPE_TABLE   => 'table',
        Document::TYPE_SHEET   => 'sheet',
    ];

    /**
     * 转换文档类型。
     *
     * @param  int|string  $type
     * @param  bool        $flip  true = 字符串 → 常量,false = 常量 → 字符串
     */
    public static function convert($type, bool $flip = false): string
    {
        $types = $flip ? array_flip(self::TYPES) : self::TYPES;
        return $types[$type] ?? '';
    }
}
