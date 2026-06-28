<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

/**
 * 图片水印服务(T7)。
 *
 * 替代 helpers.php::watermark(),封装 Intervention/Image v3 API。
 */
class Watermarker
{
    /**
     * 给图片添加水印(logo 或 text 类型)。
     *
     * 配置由 config('wizard.watermark') 提供。
     */
    public static function apply(string $filePath): void
    {
        $cfg = config('wizard.watermark');
        if (!$cfg['enabled'] || !in_array($cfg['type'], ['logo', 'text'], true)) {
            return;
        }

        $manager = new ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
        $img     = $manager->read($filePath);
        $base    = $cfg['position'];

        if ($cfg['type'] === 'logo') {
            $img->place(public_path($cfg['pic']), $base, 0, 10);
        } else {
            if (!$cfg['font']) {
                return;
            }
            $fontpath = public_path($cfg['font']);
            if (!file_exists($fontpath)) {
                return;
            }

            $text       = $cfg['text'] ?: config('app.name');
            $fontsize   = $cfg['size'];
            $color      = $cfg['color'] ?: 'FF0000';
            $background = $cfg['background'] ?: 'FFFFFF';
            $text       = trim($text);

            $size = imagettfbbox($fontsize, 0, $fontpath, $text);
            if (!$size || count($size) < 8) {
                return;
            }
            $textWidth  = abs($size[2] - $size[0]);
            $textHeight = abs($size[1] - $size[7]);
            $minX = min($size[0], $size[2], $size[4], $size[6]);
            $maxX = max($size[0], $size[2], $size[4], $size[6]);
            $minY = min($size[1], $size[3], $size[5], $size[7]);
            $maxY = max($size[1], $size[3], $size[5], $size[7]);
            $actualWidth  = $maxX - $minX;
            $actualHeight = $maxY - $minY;

            $base_x = $base_y = 0;
            $width  = $img->width();
            $height = $img->height();

            $ar = explode('-', $base);
            if (in_array('left', $ar)) {
                $base_x = 10;
                if ($minX < 0) $base_x = 10 - $minX;
            }
            if (in_array('right', $ar)) {
                $base_x = $width - 10 - $actualWidth;
            }
            if (in_array('top', $ar)) {
                $base_y = 10 + $maxY;
                if ($minY < 0) $base_y = 10 - $minY;
            }
            if (in_array('bottom', $ar)) {
                $base_y = $height - 10 - $actualHeight - $maxY;
            }
            if (in_array('center', $ar)) {
                $base_x = ($width - $actualWidth) / 2;
                $base_y = ($height - $actualHeight) / 2;
            }

            $borderSize = max(0, intval($cfg['border_size']));
            if ($borderSize) {
                for ($i = 0; $i <= ($borderSize * 2); $i++) {
                    for ($j = 0; $j <= ($borderSize * 2); $j++) {
                        $img->text($text, $base_x + $i, $base_y + $j,
                            function (FontFactory $font) use ($fontpath, $fontsize, $background) {
                                $font->file($fontpath);
                                $font->size($fontsize);
                                $font->color($background);
                            });
                    }
                }
            }
            $img->text($text, $base_x + $borderSize, $base_y + $borderSize,
                function (FontFactory $font) use ($fontpath, $fontsize, $color) {
                    $font->file($fontpath);
                    $font->size($fontsize);
                    $font->color($color);
                });
        }
        $img->save();
    }
}
