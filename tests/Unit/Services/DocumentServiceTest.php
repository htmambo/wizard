<?php

namespace Tests\Unit\Services;

use App\Events\DocumentCreated;
use App\Events\DocumentDeleted;
use App\Events\DocumentMarkModified;
use App\Events\DocumentModified;
use App\Repositories\Document;
use App\Repositories\DocumentHistory;
use App\Repositories\DocumentScore;
use App\Repositories\PageShare;
use App\Repositories\Project;
use App\Repositories\User;
use App\Services\DocumentService;
use App\Services\SyncUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentService();
    }

    // ---------- create ----------

    public function test_create_persists_markdown_document(): void
    {
        Event::fake([DocumentCreated::class]);

        $user    = $this->createUser();
        $project = $this->createProject($user);

        $this->service->create($user, $project, [
            'title'        => '新文档',
            'type'         => 'markdown',
            'pid'          => 0,
            'sort_level'   => 1000,
            'content'      => '# hello',
            'content_html' => '<h1>hello</h1>',
            'sync_url'     => null,
        ]);

        $stored = Document::where('title', '新文档')->first();
        $this->assertNotNull($stored);
        $this->assertSame(Document::TYPE_DOC, (int)$stored->type);
        $this->assertSame('<h1>hello</h1>', $stored->html_code);
        $this->assertSame('hello', strip_tags($stored->description));
        $this->assertSame($user->id, $stored->user_id);
        $this->assertSame($user->id, $stored->last_modified_uid);
        $this->assertSame($project->id, $stored->project_id);
        $this->assertSame(Document::STATUS_NORMAL, (int)$stored->status);

        Event::assertDispatched(DocumentCreated::class);
    }

    public function test_create_purifies_html_content(): void
    {
        Event::fake([DocumentCreated::class]);

        $user    = $this->createUser();
        $project = $this->createProject($user);

        $this->service->create($user, $project, [
            'title'   => 'html-doc',
            'type'    => 'html',
            'content' => '<p>safe<script>alert(1)</script></p>',
        ]);

        $stored = Document::where('title', 'html-doc')->first();
        $this->assertNotNull($stored);
        // <script> 标签必须被 HtmlPurifierService 过滤掉
        $this->assertStringNotContainsString('<script', $stored->content);
        $this->assertStringContainsString('<p>safe</p>', $stored->content);
    }

    public function test_create_sets_user_as_creator_and_last_modifier(): void
    {
        Event::fake([DocumentCreated::class]);

        $user    = $this->createUser();
        $project = $this->createProject($user);

        $this->service->create($user, $project, [
            'title'   => 'creator-test',
            'type'    => 'markdown',
            'content' => 'body',
        ]);

        $stored = Document::where('title', 'creator-test')->first();
        $this->assertNotNull($stored);
        $this->assertSame($user->id, $stored->user_id);
        $this->assertSame($user->id, $stored->last_modified_uid);
    }

    // ---------- update ----------

    public function test_update_does_not_dispatch_when_nothing_changed(): void
    {
        Event::fake([DocumentModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'title'   => 'unchanged',
            'content' => 'body',
        ]);

        $this->service->update($document->fresh(), $user, [
            'pid'        => 0,
            'project_id' => $project->id,
            'title'      => 'unchanged',
            'content'    => 'body',
            'sort_level' => 1000,
            'sync_url'   => null,
        ]);

        Event::assertNotDispatched(DocumentModified::class);
    }

    public function test_update_persists_changes_when_dirty(): void
    {
        Event::fake([DocumentModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'title'   => 'before',
            'content' => 'body',
        ]);

        $this->service->update($document->fresh(), $user, [
            'pid'        => 0,
            'project_id' => $project->id,
            'title'      => 'after',
            'content'    => 'new body',
            'sort_level' => 1000,
            'sync_url'   => null,
        ]);

        $stored = $document->fresh();
        $this->assertSame('after', $stored->title);
        $this->assertSame('new body', $stored->content);
        Event::assertDispatched(DocumentModified::class);
    }

    public function test_update_persists_title_change(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'title'   => 'v1',
            'content' => 'c1',
        ]);

        $this->service->update($document->fresh(), $user, [
            'pid'        => 0,
            'project_id' => $project->id,
            'title'      => 'v2',
            'content'    => 'c2',
            'sort_level' => 1000,
            'sync_url'   => null,
        ]);

        $this->assertDatabaseHas('pages', [
            'id'    => $document->id,
            'title' => 'v2',
        ]);
    }

    public function test_update_sets_html_code_for_markdown(): void
    {
        Event::fake([DocumentModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'    => Document::TYPE_DOC,
            'title'   => 'md',
            'content' => '# title',
        ]);

        $this->service->update($document->fresh(), $user, [
            'pid'           => 0,
            'project_id'    => $project->id,
            'title'         => 'md',
            'content'       => '# title',
            'sort_level'    => 1000,
            'sync_url'      => null,
            'content_html'  => '<h1>title</h1>',
        ]);

        $stored = $document->fresh();
        $this->assertSame('<h1>title</h1>', $stored->html_code);
    }

    public function test_update_purifies_html_for_html_type(): void
    {
        Event::fake([DocumentModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'    => Document::TYPE_HTML,
            'title'   => 'html',
            'content' => '<p>safe</p>',
        ]);

        $this->service->update($document->fresh(), $user, [
            'pid'        => 0,
            'project_id' => $project->id,
            'title'      => 'html',
            'content'    => '<p>safe<script>alert(1)</script></p>',
            'sort_level' => 1000,
            'sync_url'   => null,
        ]);

        $stored = $document->fresh();
        $this->assertStringNotContainsString('<script', $stored->content);
    }

    public function test_update_ignores_leanote_trailing_whitespace_diff(): void
    {
        Event::fake([DocumentModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        // 模拟 LEANOTE 迁移遗留：content 末尾有空格
        $document = $this->createDocument($user, $project, [
            'type'    => Document::TYPE_DOC,
            'title'   => 'ws',
            'content' => "hello  ", // 两个尾部空格
        ]);

        $this->service->update($document->fresh(), $user, [
            'pid'        => 0,
            'project_id' => $project->id,
            'title'      => 'ws',
            'content'    => 'hello', // 去掉空格
            'sort_level' => 1000,
            'sync_url'   => null,
        ]);

        // 内容与 trim 后的原内容相等，视为未变化，不应触发事件
        Event::assertNotDispatched(DocumentModified::class);
    }

    // ---------- delete ----------

    public function test_delete_soft_deletes_and_dispatches_event(): void
    {
        Event::fake([DocumentDeleted::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, ['title' => 'to-delete']);

        $this->service->delete($document->fresh(), $user);

        $this->assertSoftDeleted('pages', ['id' => $document->id]);
        Event::assertDispatched(
            DocumentDeleted::class,
            fn (DocumentDeleted $e) => $e->getDocument()->id === $document->id
        );
    }

    public function test_delete_promotes_subpages_to_parent_pid(): void
    {
        Event::fake([DocumentDeleted::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $parent = $this->createDocument($user, $project, ['title' => 'parent', 'pid' => 0]);
        $child  = $this->createDocument($user, $project, ['title' => 'child', 'pid' => $parent->id]);
        $grand  = $this->createDocument($user, $project, ['title' => 'grand', 'pid' => $child->id]);

        $this->service->delete($child->fresh(), $user);

        $this->assertSoftDeleted('pages', ['id' => $child->id]);
        // grand 应该上移到 parent（pid 改为 parent.id）
        $this->assertDatabaseHas('pages', [
            'id'  => $grand->id,
            'pid' => $parent->id,
        ]);
    }

    // ---------- markStatus ----------

    public function test_mark_status_changes_status_and_dispatches_event(): void
    {
        Event::fake([DocumentMarkModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'status' => Document::STATUS_NORMAL,
        ]);

        $result = $this->service->markStatus($document->fresh(), $user, (string)Document::STATUS_OUTDATED);

        $this->assertSame(Document::STATUS_OUTDATED, (int)$result->status);
        Event::assertDispatched(
            DocumentMarkModified::class,
            fn (DocumentMarkModified $e) => $e->getDocument()->id === $document->id
        );
    }

    public function test_mark_status_does_not_dispatch_when_status_unchanged(): void
    {
        Event::fake([DocumentMarkModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'status' => Document::STATUS_NORMAL,
        ]);

        $this->service->markStatus($document->fresh(), $user, (string)Document::STATUS_NORMAL);

        Event::assertNotDispatched(DocumentMarkModified::class);
    }

    // ---------- checkExpired ----------

    public function test_check_expired_returns_expired_true_when_updated_at_differs(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $staleTime = $document->updated_at->copy()->subMinutes(5);

        $result = $this->service->checkExpired($document->fresh(), $staleTime);

        $this->assertTrue($result['expired']);
        $this->assertNotSame('ok', $result['message']);
    }

    public function test_check_expired_returns_expired_false_when_updated_at_matches(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $result = $this->service->checkExpired(
            $document->fresh(),
            $document->updated_at->copy()
        );

        $this->assertFalse($result['expired']);
        $this->assertSame('ok', $result['message']);
    }

    // ---------- syncFromRemote ----------

    public function test_sync_from_remote_returns_noop_when_sync_url_is_empty(): void
    {
        Event::fake([DocumentModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'     => Document::TYPE_SWAGGER,
            'sync_url' => null,
        ]);

        $result = $this->service->syncFromRemote($document->fresh(), $user);

        $this->assertFalse($result['synced']);
        Event::assertNotDispatched(DocumentModified::class);
    }

    public function test_sync_from_remote_throws_when_ssrf_guard_rejects_url(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'     => Document::TYPE_SWAGGER,
            'sync_url' => 'http://127.0.0.1/swagger.json',
        ]);

        // SSRF 守卫应在 HTTP 请求前就拒绝内网 IP
        $this->expectException(InvalidArgumentException::class);
        $this->service->syncFromRemote($document->fresh(), $user);
    }

    public function test_sync_from_remote_throws_when_ssrf_guard_rejects_loopback_hostname(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'     => Document::TYPE_SWAGGER,
            'sync_url' => 'http://localhost/swagger.json',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->syncFromRemote($document->fresh(), $user);
    }

    public function test_sync_url_guard_blocks_private_ipv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SyncUrlGuard::validate('http://10.0.0.1/swagger.json');
    }

    public function test_sync_url_guard_blocks_metadata_endpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SyncUrlGuard::validate('http://169.254.169.254/latest/meta-data/');
    }

    public function test_sync_url_guard_blocks_ipv6_loopback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SyncUrlGuard::validate('http://[::1]/swagger.json');
    }

    // ---------- move ----------

    public function test_move_updates_project_id_and_pid(): void
    {
        $user = $this->createUser();
        $projectA = $this->createProject($user, ['name' => 'A']);
        $projectB = $this->createProject($user, ['name' => 'B']);
        $document = $this->createDocument($user, $projectA, ['title' => 'mover', 'pid' => 0]);

        $this->service->move(
            $document->fresh(),
            $user,
            $projectB,
            null,
            false,
            []
        );

        $fresh = $document->fresh();
        $this->assertSame($projectB->id, $fresh->project_id);
        $this->assertSame(0, (int)$fresh->pid);
    }

    public function test_move_to_target_parent_sets_new_pid(): void
    {
        $user = $this->createUser();
        $projectA = $this->createProject($user, ['name' => 'A']);
        $projectB = $this->createProject($user, ['name' => 'B']);
        $newParent = $this->createDocument($user, $projectB, ['title' => 'new-parent', 'pid' => 0]);
        $document  = $this->createDocument($user, $projectA, ['title' => 'child', 'pid' => 0]);

        $this->service->move(
            $document->fresh(),
            $user,
            $projectB,
            $newParent->fresh(),
            false,
            []
        );

        $this->assertSame($newParent->id, (int)$document->fresh()->pid);
        $this->assertSame($projectB->id, $document->fresh()->project_id);
    }

    public function test_move_cascades_project_id_to_children(): void
    {
        $user = $this->createUser();
        $projectA = $this->createProject($user, ['name' => 'A']);
        $projectB = $this->createProject($user, ['name' => 'B']);
        $parent = $this->createDocument($user, $projectA, ['title' => 'parent']);
        $child  = $this->createDocument($user, $projectA, ['title' => 'child', 'pid' => $parent->id]);

        $this->service->move(
            $parent->fresh(),
            $user,
            $projectB,
            null,
            false,
            [$child->id]
        );

        $this->assertSame($projectB->id, (int)$child->fresh()->project_id);
        $this->assertSame($projectB->id, (int)$parent->fresh()->project_id);
    }

    public function test_move_updates_related_share_records_project_id(): void
    {
        $user = $this->createUser();
        $projectA = $this->createProject($user, ['name' => 'A']);
        $projectB = $this->createProject($user, ['name' => 'B']);
        $document = $this->createDocument($user, $projectA);

        // 直接通过 DB 写入 share 记录(避免 DocumentHistory::write 调用)
        PageShare::create([
            'code'       => 'abc',
            'project_id' => $projectA->id,
            'page_id'    => $document->id,
            'user_id'    => $user->id,
        ]);

        $this->service->move(
            $document->fresh(),
            $user,
            $projectB,
            null,
            false,
            []
        );

        $this->assertDatabaseHas('page_share', [
            'page_id'    => $document->id,
            'project_id' => $projectB->id,
        ]);
    }

    public function test_move_does_not_update_updated_at_when_dont_save_updated(): void
    {
        $user = $this->createUser();
        $projectA = $this->createProject($user, ['name' => 'A']);
        $projectB = $this->createProject($user, ['name' => 'B']);
        $document = $this->createDocument($user, $projectA);
        $originalUpdatedAt = $document->fresh()->updated_at;

        // 等待足够长时间以确保 updated_at 不会意外相同
        Carbon::setTestNow(Carbon::now()->addMinutes(10));

        $this->service->move(
            $document->fresh(),
            $user,
            $projectB,
            null,
            true, // dontSaveUpdated
            []
        );

        $this->assertSame(
            $originalUpdatedAt->toDateTimeString(),
            $document->fresh()->updated_at->toDateTimeString()
        );
    }

    // ---------- updateScore ----------

    public function test_update_score_creates_new_score_when_none_exists(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $score = $this->service->updateScore($document->fresh(), $user, DocumentScore::SCORE_USEFUL);

        $this->assertNotNull($score);
        $this->assertSame(DocumentScore::SCORE_USEFUL, (int)$score->score_type);
        $this->assertDatabaseHas('page_score', [
            'user_id'    => $user->id,
            'page_id'    => $document->id,
            'score_type' => DocumentScore::SCORE_USEFUL,
        ]);
    }

    public function test_update_score_toggles_off_when_same_score_submitted(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $this->service->updateScore($document->fresh(), $user, DocumentScore::SCORE_USEFUL);
        $result = $this->service->updateScore($document->fresh(), $user, DocumentScore::SCORE_USEFUL);

        $this->assertNull($result);
        $this->assertDatabaseMissing('page_score', [
            'user_id' => $user->id,
            'page_id' => $document->id,
        ]);
    }

    public function test_update_score_replaces_existing_score_when_different(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $this->service->updateScore($document->fresh(), $user, DocumentScore::SCORE_USEFUL);
        $result = $this->service->updateScore($document->fresh(), $user, DocumentScore::SCORE_HARD_TO_READ);

        $this->assertNotNull($result);
        $this->assertSame(DocumentScore::SCORE_HARD_TO_READ, (int)$result->score_type);
        $this->assertSame(1, DocumentScore::where('page_id', $document->id)->count());
    }

    // ---------- setBlogStatus ----------

    public function test_set_blog_status_true_dispatches_event_and_persists(): void
    {
        Event::fake([DocumentMarkModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        // is_blog / alias 不在 Document::$fillable 中，需用 query builder 绕过 fillable 限制
        $document = $this->createDocument($user, $project, [
            'type'    => Document::TYPE_DOC,
        ]);
        \DB::table('pages')->where('id', $document->id)->update(['is_blog' => 0]);

        $result = $this->service->setBlogStatus($document->fresh(), $user, true, 'custom-alias');

        $this->assertSame(1, (int)$result->is_blog);
        $this->assertSame('custom-alias', $result->alias);
        Event::assertDispatched(
            DocumentMarkModified::class,
            fn (DocumentMarkModified $e) => $e->getDocument()->id === $document->id
        );
    }

    public function test_set_blog_status_false_clears_is_blog(): void
    {
        Event::fake([DocumentMarkModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'    => Document::TYPE_DOC,
        ]);
        // 直接通过 DB 写入 is_blog=1（绕过 fillable 限制）
        \DB::table('pages')->where('id', $document->id)->update(['is_blog' => 1]);

        $result = $this->service->setBlogStatus($document->fresh(), $user, false);

        $this->assertSame(0, (int)$result->is_blog);
        Event::assertDispatched(
            DocumentMarkModified::class,
            fn (DocumentMarkModified $e) => $e->getDocument()->id === $document->id
        );
    }

    public function test_set_blog_status_does_not_dispatch_when_value_unchanged(): void
    {
        Event::fake([DocumentMarkModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'    => Document::TYPE_DOC,
        ]);
        // 设置初始 is_blog=1
        \DB::table('pages')->where('id', $document->id)->update(['is_blog' => 1]);

        // 再次 setBlogStatus(true) 值未变 → 不应派发事件
        $this->service->setBlogStatus($document->fresh(), $user, true);

        Event::assertNotDispatched(DocumentMarkModified::class);
    }

    public function test_set_blog_status_without_alias_does_not_overwrite_existing_alias(): void
    {
        Event::fake([DocumentMarkModified::class]);

        $user = $this->createUser();
        $project = $this->createProject($user);
        $document = $this->createDocument($user, $project, [
            'type'    => Document::TYPE_DOC,
        ]);
        // 直接通过 DB 写入 alias（绕过 fillable）
        \DB::table('pages')->where('id', $document->id)->update([
            'is_blog' => 1,
            'alias'   => 'keep-me',
        ]);

        $result = $this->service->setBlogStatus($document->fresh(), $user, false);

        $this->assertSame('keep-me', $result->alias);
        $this->assertSame(0, (int)$result->is_blog);
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
}
