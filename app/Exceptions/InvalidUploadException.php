<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Exceptions;

use RuntimeException;

/**
 * 上传文件非法异常。
 *
 * 由 UploadWhitelist 在以下场景抛出:
 *   - 扩展名命中黑名单(php/phar/htaccess/svg-作为文档等)
 *   - 客户端声明的 MIME 与真实 MIME 不一致(防伪)
 *   - 文件名包含 ../ 或 shell 元字符
 *   - 文件超过尺寸上限
 *   - UploadedFile 本身报告错误(UPLOAD_ERR_*)
 *
 * 默认 HTTP 状态码 422,与 Laravel ValidationException 一致,
 * API 渲染走全局 Handler,前端可获取 message 直接展示。
 */
class InvalidUploadException extends RuntimeException
{
    public function __construct(string $message = '上传文件不合法', int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
