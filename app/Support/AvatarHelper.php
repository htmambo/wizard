<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

/**
 * 用户头像助手(T7)。
 *
 * 替代 helpers.php::user_face(),使用 jdenticon 生成 SVG identicon。
 */
class AvatarHelper
{
    /**
     * 根据用户 ID 生成头像 data URI(SVG)。
     */
    public static function userFace(string $id): string
    {
        $svg = new \Jdenticon\Identicon();
        $svg->setValue($id);
        $svg->setSize(100);
        return $svg->getImageDataUri('svg');
    }
}
