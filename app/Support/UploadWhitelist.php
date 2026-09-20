<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use App\Exceptions\InvalidUploadException;
use Illuminate\Http\UploadedFile;

/**
 * 文件上传白名单校验器。
 *
 * 设计目标:在文件落盘前阻断:
 *   - 危险扩展名(php / phar / htaccess / pht 等可执行文件)
 *   - 客户端声明 MIME 与真实 MIME 不一致(防 .php 伪装 .jpg)
 *   - 超大文件(避免磁盘 / 内存 DoS)
 *   - 路径穿越文件名(防 ../ 写入预期外目录)
 *
 * 调用方式:
 *   UploadWhitelist::validateImage($request->file('attachment'))
 *   UploadWhitelist::validateDocument($request->file('attachment'))
 *   UploadWhitelist::getSafeFilename($file->getClientOriginalName())
 *
 * 成功时返回 ['extension' => 'jpg', 'mime' => 'image/jpeg', 'filename' => 'safe.jpg']
 * 失败时抛 App\Exceptions\InvalidUploadException,业务层选择 redirect/422。
 */
class UploadWhitelist
{
    /**
     * 图片扩展名白名单。
     * svg 单独标注:SVG 本质是 XML,可能携带 XSS payload,
     * 只有走专门的 image 校验入口(且经 HTML 净化)才放行。
     */
    public const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    public const ALLOWED_IMAGE_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public const ALLOWED_DOC_EXT
        = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'zip'];

    public const ALLOWED_DOC_MIME
        = ['application/pdf', 'application/zip', 'text/plain', 'text/markdown'];

    /**
     * 黑名单扩展名。
     * htaccess / svg 在文档上传时强制阻断(防 .htaccess 注入、SVG XSS);
     * 图片通道由 validateImage() 单独放行 svg。
     */
    public const BLOCKED_EXT
        = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'htaccess', 'svg'];

    public const MAX_IMAGE_SIZE = 5 * 1024 * 1024;     // 5MB
    public const MAX_DOC_SIZE   = 50 * 1024 * 1024;   // 50MB

    /**
     * 校验图片上传。成功返回元数据数组。
     */
    public static function validateImage(UploadedFile $file): array
    {
        return self::validate(
            $file,
            self::ALLOWED_IMAGE_EXT,
            self::ALLOWED_IMAGE_MIME,
            self::MAX_IMAGE_SIZE,
            'image'
        );
    }

    /**
     * 校验文档上传(强制阻断 SVG,避免 XML XSS)。
     */
    public static function validateDocument(UploadedFile $file): array
    {
        return self::validate(
            $file,
            self::ALLOWED_DOC_EXT,
            self::ALLOWED_DOC_MIME,
            self::MAX_DOC_SIZE,
            'document'
        );
    }

    /**
     * 清洗原始文件名,防止路径穿越与 shell 元字符。
     *
     * - 去掉目录部分(取 basename)
     * - 把 ../ / 反斜杠 / 控制字符 / shell 元字符替换为下划线
     * - 保留最后一个 . 作为扩展名分隔符(避免 ok.jpg → ok_jpg)
     * - 截断到 200 字符,防止超长文件名触发 OS 限制
     */
    public static function getSafeFilename(string $original): string
    {
        $name = basename(str_replace('\\', '/', $original));

        // 抽取并暂存扩展名(最后一组 .xxx),剩余 basename 部分再做清洗
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);

        // 控制字符 + 路径分隔符 + shell 元字符 → 下划线
        $base = preg_replace('/[\x00-\x1F\x7F\/]/u', '_', $base) ?? '';
        // 连续的 . 折叠成单个下划线(包括 .. )
        $base = preg_replace('/\.+/', '_', $base) ?? '';
        // shell 元字符 + Windows 保留字符 → 下划线
        $base = preg_replace('/[<>:"|?*;`$&\\\\]/u', '_', $base) ?? '';
        $base = trim($base, '_ ');

        if ($ext !== '') {
            $ext = preg_replace('/[^A-Za-z0-9]/', '', $ext) ?? '';
        }

        if ($base === '' || $base === null) {
            $base = 'upload_' . substr(md5(uniqid('', true)), 0, 8);
        }

        $name = $ext !== '' ? $base . '.' . $ext : $base;

        if (strlen($name) > 200) {
            $maxBase = max(1, 200 - strlen($ext) - 1);
            $name = substr($base, 0, $maxBase) . ($ext !== '' ? '.' . $ext : '');
        }

        return $name;
    }

    /**
     * 通用校验流程:错误码 → 黑名单 → 扩展名白名单 → MIME(客户端 vs 真实)→ 大小 → 文件名。
     */
    private static function validate(
        UploadedFile $file,
        array $allowedExt,
        array $allowedMime,
        int $maxSize,
        string $typeLabel
    ): array {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidUploadException(
                sprintf('上传失败(%s): %s', $typeLabel, $file->getErrorMessage())
            );
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === '' || in_array($ext, self::BLOCKED_EXT, true)) {
            throw new InvalidUploadException(
                sprintf('不允许的文件扩展名: %s', $ext === '' ? '(空)' : ".{$ext}")
            );
        }
        if (!in_array($ext, $allowedExt, true)) {
            throw new InvalidUploadException(
                sprintf('文件类型不支持,期望 %s 之一,实际 .%s', implode('/', $allowedExt), $ext)
            );
        }

        // 客户端声明的 MIME(来自 multipart Content-Type)
        $clientMime = strtolower((string) $file->getClientMimeType());
        if ($clientMime !== '' && !in_array($clientMime, $allowedMime, true)) {
            throw new InvalidUploadException(
                sprintf('客户端声明的 MIME 类型不被允许: %s', $clientMime)
            );
        }

        // 真实 MIME(基于文件内容嗅探)。UploadedFile::getMimeType() 内部走 finfo/mime_content_type
        $realMime = strtolower((string) $file->getMimeType());
        if ($realMime !== '' && !in_array($realMime, $allowedMime, true)) {
            throw new InvalidUploadException(
                sprintf('文件实际内容与声明类型不一致,实际 MIME: %s', $realMime)
            );
        }

        if ($file->getSize() === false || $file->getSize() <= 0) {
            throw new InvalidUploadException('无法读取文件大小或文件为空');
        }
        if ($file->getSize() > $maxSize) {
            $mb = $maxSize / 1024 / 1024;
            throw new InvalidUploadException(
                sprintf('文件过大,上限 %dMB', $mb)
            );
        }

        $originalName = $file->getClientOriginalName();
        $safeName     = self::getSafeFilename($originalName);
        if ($safeName === '' || $safeName === null) {
            throw new InvalidUploadException('文件名为空或仅包含非法字符');
        }

        return [
            'extension'      => $ext,
            'mime'           => $realMime !== '' ? $realMime : $clientMime,
            'filename'       => $safeName,
            'size'           => (int) $file->getSize(),
            'original_name'  => $originalName,
        ];
    }
}
