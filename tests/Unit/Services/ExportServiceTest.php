<?php

namespace Tests\Unit\Services;

use App\Notifications\ExportCompleted;
use App\Repositories\Document;
use App\Repositories\Project;
use App\Repositories\User;
use App\Services\ExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use ZipArchive;

class ExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExportService();
    }

    // ---------- collectNavigators ----------

    public function test_collect_navigators_returns_full_tree_when_pid_is_null(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user);

        $parent  = $this->createDocument($user, $project, ['title' => 'parent', 'pid' => 0]);
        $child   = $this->createDocument($user, $project, ['title' => 'child',  'pid' => $parent->id]);
        $sibling = $this->createDocument($user, $project, ['title' => 'sibling', 'pid' => 0]);

        $tree = $this->service->collectNavigators($project);

        $ids = $this->flattenIds($tree);
        $this->assertContains($parent->id, $ids);
        $this->assertContains($child->id, $ids);
        $this->assertContains($sibling->id, $ids);
        // 顶层只有 parent 与 sibling 两个根节点
        $this->assertCount(2, $tree);

        $parentEntry = $this->findNavById($tree, $parent->id);
        $this->assertNotNull($parentEntry);
        $this->assertArrayHasKey('nodes', $parentEntry);
        $this->assertCount(1, $parentEntry['nodes']);
        $this->assertSame($child->id, (int)$parentEntry['nodes'][0]['id']);

        $siblingEntry = $this->findNavById($tree, $sibling->id);
        $this->assertNotNull($siblingEntry);
        $this->assertArrayNotHasKey('nodes', $siblingEntry);
    }

    public function test_collect_navigators_returns_subtree_when_pid_is_given(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user);

        $parent = $this->createDocument($user, $project, ['title' => 'parent', 'pid' => 0]);
        $childA = $this->createDocument($user, $project, ['title' => 'child-a', 'pid' => $parent->id]);
        $childB = $this->createDocument($user, $project, ['title' => 'child-b', 'pid' => $parent->id]);
        // 不属于子树，应当被过滤掉
        $unrelated = $this->createDocument($user, $project, ['title' => 'unrelated', 'pid' => 0]);

        $tree = $this->service->collectNavigators($project, $parent->id);

        $ids = array_map(fn ($n) => (int)$n['id'], $tree);
        $this->assertCount(2, $ids);
        $this->assertContains($childA->id, $ids);
        $this->assertContains($childB->id, $ids);
        // unrelated（pid=0）不应出现
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_collect_navigators_returns_empty_when_pid_not_found(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user);
        $this->createDocument($user, $project, ['title' => 'only', 'pid' => 0]);

        $tree = $this->service->collectNavigators($project, 999999);

        $this->assertSame([], $tree);
    }

    // ---------- exportBatch ----------

    public function test_export_batch_raw_returns_zip_file(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user, ['name' => 'batch-raw']);
        $this->createDocument($user, $project, ['title' => 'a', 'pid' => 0, 'content' => 'A']);
        $this->createDocument($user, $project, ['title' => 'b', 'pid' => 0, 'content' => 'B']);

        $tree    = $this->service->collectNavigators($project);
        $filePath = $this->service->exportBatch($user, $project, $tree, 'raw');

        try {
            $this->assertFileExists($filePath);
            $this->assertStringEndsWith('.zip', $filePath);

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($filePath) === true);
            // 至少包含 1 个文档条目
            $this->assertGreaterThanOrEqual(1, $zip->numFiles);
            $zip->close();
        } finally {
            @unlink($filePath);
        }
    }

    public function test_export_batch_pdf_returns_pdf_file(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user, ['name' => 'batch-pdf']);
        $this->createDocument($user, $project, [
            'title'   => 'p',
            'pid'     => 0,
            'type'    => Document::TYPE_DOC,
            'content' => '# hello',
        ]);

        $tree     = $this->service->collectNavigators($project);
        $filePath = $this->service->exportBatch($user, $project, $tree, 'pdf');

        try {
            $this->assertFileExists($filePath);
            $this->assertStringEndsWith('.pdf', $filePath);
            // PDF 头四个字节固定为 %PDF
            $this->assertSame('%PDF', substr(file_get_contents($filePath), 0, 4));
        } finally {
            @unlink($filePath);
        }
    }

    public function test_export_batch_throws_on_unsupported_format(): void
    {
        $user    = $this->createUser();
        $project = $this->createProject($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->exportBatch($user, $project, [], 'docx');
    }

    // ---------- exportSingle ----------

    public function test_export_single_pdf_returns_pdf_file(): void
    {
        $user     = $this->createUser();
        $project  = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'    => Document::TYPE_DOC,
            'content' => '# hi',
        ]);

        $filePath = $this->service->exportSingle($document->fresh(), 'pdf');

        try {
            $this->assertFileExists($filePath);
            $this->assertStringEndsWith('.pdf', $filePath);
            $this->assertSame('%PDF', substr(file_get_contents($filePath), 0, 4));
        } finally {
            @unlink($filePath);
        }
    }

    public function test_export_single_raw_returns_zip_file(): void
    {
        $user     = $this->createUser();
        $project  = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'    => Document::TYPE_DOC,
            'content' => '# raw',
        ]);

        $filePath = $this->service->exportSingle($document->fresh(), 'raw');

        try {
            $this->assertFileExists($filePath);
            $this->assertStringEndsWith('.zip', $filePath);

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($filePath) === true);
            $this->assertSame(1, $zip->numFiles);
            $zip->close();
        } finally {
            @unlink($filePath);
        }
    }

    public function test_export_single_throws_on_unsupported_format(): void
    {
        $user     = $this->createUser();
        $project  = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->exportSingle($document->fresh(), 'docx');
    }

    // ---------- packageAsZip ----------

    public function test_package_as_zip_bundles_multiple_files(): void
    {
        $fileA = tempnam(sys_get_temp_dir(), 'wz_test_a_');
        $fileB = tempnam(sys_get_temp_dir(), 'wz_test_b_');
        file_put_contents($fileA, "alpha\n");
        file_put_contents($fileB, "beta\n");

        try {
            $zipPath = $this->service->packageAsZip(
                ['a.txt' => $fileA, 'sub/b.txt' => $fileB],
                'bundle'
            );

            try {
                $this->assertFileExists($zipPath);

                $zip = new ZipArchive();
                $this->assertTrue($zip->open($zipPath) === true);
                $this->assertSame(2, $zip->numFiles);

                $this->assertSame("alpha\n", $zip->getFromName('a.txt'));
                $this->assertSame("beta\n", $zip->getFromName('sub/b.txt'));
                $zip->close();
            } finally {
                @unlink($zipPath);
            }
        } finally {
            @unlink($fileA);
            @unlink($fileB);
        }
    }

    public function test_package_as_zip_writes_empty_placeholder_when_source_missing(): void
    {
        $fileA = tempnam(sys_get_temp_dir(), 'wz_test_a_');
        file_put_contents($fileA, "alpha\n");

        try {
            // 第二个源文件故意不存在，期望占位空内容而非整体失败
            $zipPath = $this->service->packageAsZip(
                ['exists.txt' => $fileA, 'missing.txt' => '/tmp/wz_does_not_exist_' . uniqid()],
                'bundle2'
            );

            try {
                $this->assertFileExists($zipPath);

                $zip = new ZipArchive();
                $this->assertTrue($zip->open($zipPath) === true);
                $this->assertSame(2, $zip->numFiles);
                $this->assertSame("alpha\n", $zip->getFromName('exists.txt'));
                $this->assertSame('', $zip->getFromName('missing.txt'));
                $zip->close();
            } finally {
                @unlink($zipPath);
            }
        } finally {
            @unlink($fileA);
        }
    }

    // ---------- notifyExportComplete ----------

    public function test_notify_export_complete_dispatches_notification_with_payload(): void
    {
        Notification::fake();

        $user = $this->createUser();
        $filePath = '/tmp/wizard_export_fake_' . uniqid() . '.zip';

        $this->service->notifyExportComplete($user, $filePath, 'archive.zip');

        Notification::assertSentTo(
            $user,
            ExportCompleted::class,
            function (ExportCompleted $notification) use ($user, $filePath) {
                $payload = $notification->toArray($user);
                return $payload['file_path'] === $filePath
                    && $payload['archive_name'] === 'archive.zip';
            }
        );
    }

    public function test_notify_export_complete_falls_back_to_basename_when_archive_name_null(): void
    {
        Notification::fake();

        $user = $this->createUser();
        $filePath = '/tmp/wizard_export_named_' . uniqid() . '.zip';

        $this->service->notifyExportComplete($user, $filePath);

        Notification::assertSentTo(
            $user,
            ExportCompleted::class,
            function (ExportCompleted $notification) use ($user, $filePath) {
                $payload = $notification->toArray($user);
                return $payload['file_path'] === $filePath
                    && $payload['archive_name'] === basename($filePath);
            }
        );
    }

    // ---------- helpers ----------

    private function createDocument(User $user, Project $project, array $attrs = []): Document
    {
        return Document::create(array_merge([
            'pid'               => 0,
            'title'             => 'doc_' . uniqid(),
            'content'           => 'content',
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'last_modified_uid' => $user->id,
            'type'              => Document::TYPE_DOC,
            'status'            => Document::STATUS_NORMAL,
            'sort_level'        => 1000,
        ], $attrs));
    }

    private function flattenIds(array $tree): array
    {
        $ids = [];
        foreach ($tree as $nav) {
            $ids[] = (int)$nav['id'];
            if (!empty($nav['nodes'])) {
                $ids = array_merge($ids, $this->flattenIds($nav['nodes']));
            }
        }
        return $ids;
    }

    private function findNavById(array $tree, int $id): ?array
    {
        foreach ($tree as $nav) {
            if ((int)$nav['id'] === $id) {
                return $nav;
            }
            if (!empty($nav['nodes'])) {
                $found = $this->findNavById($nav['nodes'], $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }
}
