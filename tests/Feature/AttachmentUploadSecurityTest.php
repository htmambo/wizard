<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace Tests\Feature;

use App\Repositories\Document;
use App\Repositories\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * AttachmentController 上传入口安全测试。
 *
 * 覆盖 UploadWhitelist::validateDocument 在 AttachmentController.upload
 * 中的拦截效果,以及 FileController.imageUpload 的基本拦截。
 */
class AttachmentUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const TMP_DIR = '/tmp/wizard-upload-controller';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(self::TMP_DIR)) {
            mkdir(self::TMP_DIR, 0755, true);
        }
    }

    private function makeRealFile(string $name, string $content): string
    {
        $path = self::TMP_DIR . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private function makePdfFile(string $name): string
    {
        // PDF magic bytes 让 finfo 识别为 application/pdf
        $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        return $this->makeRealFile($name, $pdf);
    }

    private function uploaded(string $path, string $clientName, string $mime): UploadedFile
    {
        return new UploadedFile($path, $clientName, $mime, null, true);
    }

    private function createProjectWithPage($user): array
    {
        $project = Project::create([
            'name'        => 'proj_' . uniqid(),
            'description' => 'test',
            'user_id'     => $user->id,
            'visibility'  => Project::VISIBILITY_PUBLIC,
            'sort_level'  => 1000,
            'catalog_id'  => 0,
        ]);
        $page = Document::create([
            'title'      => 'page_' . uniqid(),
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'type'       => Document::TYPE_DOC,
            'status'     => Document::STATUS_NORMAL,
            'sort_level' => 1000,
        ]);
        return [$project, $page];
    }

    public function test_attachment_upload_rejects_php_file(): void
    {
        $user = $this->createUser();
        [$project, $page] = $this->createProjectWithPage($user);
        $this->actingAs($user);

        $path = $this->makeRealFile('evil.php', '<?php echo 1;');
        $file = $this->uploaded($path, 'evil.php', 'application/x-php');

        $response = $this->postJson(
            "/project/{$project->id}/doc/{$page->id}/attachments",
            ['attachment' => $file]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['extension']);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_attachment_upload_rejects_phar_file(): void
    {
        $user = $this->createUser();
        [$project, $page] = $this->createProjectWithPage($user);
        $this->actingAs($user);

        $path = $this->makeRealFile('evil.phar', '<?php echo 1;');
        $file = $this->uploaded($path, 'evil.phar', 'application/octet-stream');

        $response = $this->postJson(
            "/project/{$project->id}/doc/{$page->id}/attachments",
            ['attachment' => $file]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['extension']);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_attachment_upload_rejects_jpg_with_html_content(): void
    {
        $user = $this->createUser();
        [$project, $page] = $this->createProjectWithPage($user);
        $this->actingAs($user);

        // 客户端扩展名是 jpg,但真实内容是 text/html → real MIME 嗅探命中,阻断
        $path = $this->makeRealFile('fake.jpg', '<html>evil</html>');
        $file = $this->uploaded($path, 'fake.jpg', 'image/jpeg');

        $response = $this->postJson(
            "/project/{$project->id}/doc/{$page->id}/attachments",
            ['attachment' => $file]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['extension']);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_attachment_upload_accepts_legitimate_pdf(): void
    {
        $user = $this->createUser();
        [$project, $page] = $this->createProjectWithPage($user);
        $this->actingAs($user);

        $path = $this->makePdfFile('report.pdf');
        $file = $this->uploaded($path, 'report.pdf', 'application/pdf');

        $response = $this->postJson(
            "/project/{$project->id}/doc/{$page->id}/attachments",
            ['attachment' => $file]
        );

        // 合法 PDF 通过校验 → 302(重定向回附件列表页),且数据库新增一条记录
        $this->assertContains(
            $response->getStatusCode(),
            [200, 201, 302],
            "合法 PDF 应通过校验,实际状态: {$response->getStatusCode()}"
        );
        $this->assertGreaterThanOrEqual(1, \App\Repositories\Attachment::withTrashed()->count());
    }

    public function test_file_controller_image_upload_rejects_php(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $path = $this->makeRealFile('shell.php', '<?php echo 1;');
        $file = $this->uploaded($path, 'shell.php', 'image/jpeg');

        $response = $this->postJson('/upload', [
            'editormd-image-file' => $file,
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(0, $body['success'] ?? null, 'php 文件必须被拒绝');
    }

    public function test_file_controller_image_upload_accepts_real_jpeg(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        // SOI + APP0(JFIF) + EOI,最小可识别 JPEG
        $jpeg = "\xFF\xD8\xFF\xE0" . pack('n', 16) . 'JFIF' . "\x00" . "\x01\x01\x00"
            . "\x00\x01\x00\x00" . "\x00\x00" . "\xFF\xD9";
        $path = $this->makeRealFile('pic.jpg', $jpeg);
        $file = $this->uploaded($path, 'pic.jpg', 'image/jpeg');

        $response = $this->postJson('/upload', [
            'editormd-image-file' => $file,
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(1, $body['success'] ?? null, '合法 jpeg 应通过');
        $this->assertNotEmpty($body['url'] ?? null);
    }
}
