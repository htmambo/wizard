<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace Tests\Unit\Support;

use App\Exceptions\InvalidUploadException;
use App\Support\UploadWhitelist;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * UploadWhitelist 单元测试。
 *
 * 用 tempnam 构造真实的 UploadedFile,让 getMimeType() 走真实的 finfo 嗅探;
 * 文本文件伪装成 .jpg 后会被真实 MIME 校验拦截。
 */
class UploadWhitelistTest extends TestCase
{
    private const TMP_DIR = '/tmp/wizard-upload-whitelist';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(self::TMP_DIR)) {
            mkdir(self::TMP_DIR, 0755, true);
        }
    }

    /**
     * 在临时目录写入二进制内容,返回真实文件路径。
     */
    private function makeRealFile(string $name, string $content, string $mime = 'application/octet-stream'): string
    {
        $path = self::TMP_DIR . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * 构造一个 minimal valid JPEG 文件(只够让 finfo 识别为 image/jpeg)。
     */
    private function makeJpegFile(string $name): string
    {
        // SOI + APP0(JFIF) + 像素数据 + EOI。最简 JPEG,finfo 会识别为 image/jpeg。
        $jpeg = "\xFF\xD8\xFF\xE0" . pack('n', 16) . 'JFIF' . "\x00" . "\x01\x01\x00" . "\x00\x01\x00\x00" . "\x00\x00";
        $jpeg .= "\xFF\xD9";
        return $this->makeRealFile($name, $jpeg, 'image/jpeg');
    }

    /**
     * 构造一个 minimal valid PNG 文件。
     */
    private function makePngFile(string $name): string
    {
        // 89 50 4E 47 0D 0A 1A 0A + IHDR(13 bytes) + IDAT + IEND 简化版本。
        $png = "\x89PNG\r\n\x1A\n" . pack('N', 13) . 'IHDR'
            . pack('N', 1) . pack('N', 1) . "\x08\x02" . "\x00\x00\x00"
            . pack('N', crc32('IHDR' . pack('N', 1) . pack('N', 1) . "\x08\x02\x00\x00\x00"));
        $png .= "\x00\x00\x00\x00IEND" . pack('N', crc32('IEND'));
        return $this->makeRealFile($name, $png, 'image/png');
    }

    /**
     * 把真实文件包装成 UploadedFile,模拟客户端上传。
     */
    private function uploaded(string $path, string $clientName, string $clientMime): UploadedFile
    {
        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }

    public function test_validate_image_accepts_real_jpeg(): void
    {
        $path = $this->makeJpegFile('ok.jpg');
        $file = $this->uploaded($path, 'ok.jpg', 'image/jpeg');

        $result = UploadWhitelist::validateImage($file);

        $this->assertSame('jpg', $result['extension']);
        $this->assertSame('ok.jpg', $result['filename']);
        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertGreaterThan(0, $result['size']);
    }

    public function test_validate_image_accepts_real_png(): void
    {
        $path = $this->makePngFile('ok.png');
        $file = $this->uploaded($path, 'ok.png', 'image/png');

        $result = UploadWhitelist::validateImage($file);

        $this->assertSame('png', $result['extension']);
        $this->assertSame('image/png', $result['mime']);
    }

    public function test_validate_document_accepts_text_file(): void
    {
        $path = $this->makeRealFile('doc.txt', "hello world\n", 'text/plain');
        $file = $this->uploaded($path, 'doc.txt', 'text/plain');

        $result = UploadWhitelist::validateDocument($file);

        $this->assertSame('txt', $result['extension']);
        $this->assertSame('doc.txt', $result['filename']);
    }

    public function test_validate_image_rejects_php_extension(): void
    {
        $path = $this->makeJpegFile('evil.php');
        $file = $this->uploaded($path, 'evil.php', 'image/jpeg');

        $this->expectException(InvalidUploadException::class);
        $this->expectExceptionMessageMatches('/不允许的文件扩展名/');
        UploadWhitelist::validateImage($file);
    }

    public function test_validate_document_rejects_phar_extension(): void
    {
        $path = $this->makeRealFile('evil.phar', '<?php echo 1;', 'application/octet-stream');
        $file = $this->uploaded($path, 'evil.phar', 'application/octet-stream');

        $this->expectException(InvalidUploadException::class);
        UploadWhitelist::validateDocument($file);
    }

    public function test_validate_document_rejects_htaccess_extension(): void
    {
        $path = $this->makeRealFile('.htaccess', 'RewriteEngine On', 'text/plain');
        $file = $this->uploaded($path, '.htaccess', 'text/plain');

        $this->expectException(InvalidUploadException::class);
        UploadWhitelist::validateDocument($file);
    }

    public function test_validate_document_rejects_svg(): void
    {
        // SVG 默认在文档通道禁用(XML XSS)
        $path = $this->makeRealFile('doc.svg', '<svg/>', 'image/svg+xml');
        $file = $this->uploaded($path, 'doc.svg', 'image/svg+xml');

        $this->expectException(InvalidUploadException::class);
        UploadWhitelist::validateDocument($file);
    }

    public function test_validate_image_rejects_jpg_extension_with_html_content(): void
    {
        // 客户端扩展名是 jpg,但真实内容是 text/html → real MIME 嗅探命中,阻断
        $path = $this->makeRealFile('fake.jpg', '<html>evil</html>', 'image/jpeg');
        $file = $this->uploaded($path, 'fake.jpg', 'image/jpeg');

        $this->expectException(InvalidUploadException::class);
        $this->expectExceptionMessageMatches('/文件实际内容与声明类型不一致/');
        UploadWhitelist::validateImage($file);
    }

    public function test_validate_image_rejects_oversize_file(): void
    {
        // 真实 JPEG 头 + 大量 padding,确保 MIME 嗅探通过但体积超 5MB
        $jpegHeader = "\xFF\xD8\xFF\xE0" . pack('n', 16) . 'JFIF' . "\x00"
            . "\x01\x01\x00" . "\x00\x01\x00\x00" . "\x00\x00";
        $padding = str_repeat("\xFF", UploadWhitelist::MAX_IMAGE_SIZE + 1024);
        $path = $this->makeRealFile('big.jpg', $jpegHeader . $padding);
        $file = $this->uploaded($path, 'big.jpg', 'image/jpeg');

        $this->expectException(InvalidUploadException::class);
        $this->expectExceptionMessageMatches('/文件过大/');
        UploadWhitelist::validateImage($file);
    }

    public function test_get_safe_filename_strips_path_traversal(): void
    {
        // basename 把目录部分去掉,只留下最后一段
        $this->assertSame('passwd', UploadWhitelist::getSafeFilename('../etc/passwd'));
        $this->assertSame('file.txt', UploadWhitelist::getSafeFilename('..\\..\\windows\\file.txt'));
        $this->assertSame('etc', UploadWhitelist::getSafeFilename('/etc'));
    }

    public function test_get_safe_filename_strips_special_chars(): void
    {
        $name = UploadWhitelist::getSafeFilename('a;b|c<d>e?.txt');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9._\-]+$/', $name);
        $this->assertStringEndsWith('.txt', $name);
    }

    public function test_get_safe_filename_fallback_for_empty_or_dotdot_only(): void
    {
        $name1 = UploadWhitelist::getSafeFilename('');
        $this->assertNotEmpty($name1);
        $this->assertStringStartsWith('upload_', $name1);

        $name2 = UploadWhitelist::getSafeFilename('..');
        $this->assertNotEmpty($name2);
        $this->assertStringStartsWith('upload_', $name2);

        $name3 = UploadWhitelist::getSafeFilename('////');
        $this->assertStringStartsWith('upload_', $name3);
    }

    public function test_get_safe_filename_truncates_overlong_name(): void
    {
        $long = str_repeat('a', 300) . '.txt';
        $name = UploadWhitelist::getSafeFilename($long);
        $this->assertLessThanOrEqual(200, strlen($name));
        $this->assertStringEndsWith('.txt', $name);
    }

    public function test_validate_image_rejects_unsupported_extension_even_if_mime_matches(): void
    {
        // bmp 实际 MIME 是 image/bmp,不在白名单 → 扩展名维度就阻断
        $path = $this->makeRealFile('pic.bmp', 'BM', 'image/bmp');
        $file = $this->uploaded($path, 'pic.bmp', 'image/bmp');

        $this->expectException(InvalidUploadException::class);
        $this->expectExceptionMessageMatches('/文件类型不支持/');
        UploadWhitelist::validateImage($file);
    }
}
