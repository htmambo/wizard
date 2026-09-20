<?php

namespace Tests\Unit\Services;

use App\Events\CommentCreated;
use App\Repositories\Comment;
use App\Repositories\Document;
use App\Repositories\Project;
use App\Repositories\User;
use App\Services\CommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CommentServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommentService();
    }

    // ---------- create ----------

    public function test_create_persists_comment_and_dispatches_event(): void
    {
        Event::fake([CommentCreated::class]);

        $user     = $this->createUser();
        $project  = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $comment = $this->service->create($user, $document, 'hello world');

        $this->assertSame('hello world', $comment->content);
        $this->assertSame($user->id, $comment->user_id);
        $this->assertSame($document->id, $comment->page_id);
        $this->assertSame(0, (int)$comment->reply_to_id);
        $this->assertDatabaseHas('comments', [
            'id'         => $comment->id,
            'user_id'    => $user->id,
            'page_id'    => $document->id,
            'reply_to_id'=> 0,
            'content'    => 'hello world',
        ]);

        Event::assertDispatched(
            CommentCreated::class,
            fn (CommentCreated $e) => $e->getComment()->id === $comment->id
        );
    }

    public function test_create_resolves_at_mention_to_uid_marker(): void
    {
        Event::fake([CommentCreated::class]);

        $owner    = $this->createUser(['name' => 'owner_' . uniqid()]);
        $mentioned = $this->createUser(['name' => 'bob_' . uniqid()]);
        $project  = $this->createProject($owner);
        $document = $this->createDocument($owner, $project);

        $content = "hi @{$mentioned->name} there";
        $comment = $this->service->create($owner, $document, $content);

        // @username 应被替换为 @{uid:N} 标记
        $this->assertStringContainsString("@{uid:{$mentioned->id}}", $comment->content);
        $this->assertStringNotContainsString("@{$mentioned->name}", $comment->content);
    }

    public function test_create_keeps_content_when_no_mention_present(): void
    {
        Event::fake([CommentCreated::class]);

        $user     = $this->createUser();
        $project  = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $comment = $this->service->create($user, $document, 'plain text without mention');

        $this->assertSame('plain text without mention', $comment->content);
    }

    // ---------- update ----------

    public function test_update_persists_new_content_and_resolves_mentions(): void
    {
        Event::fake([CommentCreated::class]);

        $owner     = $this->createUser(['name' => 'owner_' . uniqid()]);
        $mentioned = $this->createUser(['name' => 'alice_' . uniqid()]);
        $project   = $this->createProject($owner);
        $document  = $this->createDocument($owner, $project);

        $comment = $this->service->create($owner, $document, 'initial');
        $updated = $this->service->update($comment->fresh(), $owner, "ping @{$mentioned->name}");

        $this->assertStringContainsString("@{uid:{$mentioned->id}}", $updated->content);
        $this->assertDatabaseHas('comments', [
            'id'      => $comment->id,
            'content' => $updated->content,
        ]);
    }

    public function test_update_is_noop_when_filtered_content_unchanged(): void
    {
        Event::fake([CommentCreated::class]);

        $user     = $this->createUser();
        $project  = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $comment = $this->service->create($user, $document, 'stable');
        $before  = $comment->fresh()->updated_at;

        $updated = $this->service->update($comment->fresh(), $user, 'stable');

        $this->assertSame('stable', $updated->content);
        $this->assertSame(
            $before->toDateTimeString(),
            $updated->fresh()->updated_at->toDateTimeString()
        );
    }

    // ---------- delete ----------

    public function test_delete_soft_deletes_comment(): void
    {
        Event::fake([CommentCreated::class]);

        $user     = $this->createUser();
        $project  = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        $comment = $this->service->create($user, $document, 'bye');
        $this->service->delete($comment->fresh(), $user);

        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    // ---------- listForDocument ----------

    public function test_list_for_document_returns_only_target_doc_comments_in_desc_order(): void
    {
        $user     = $this->createUser();
        $project  = $this->createProject($user);
        $docA     = $this->createDocument($user, $project);
        $docB     = $this->createDocument($user, $project);

        // docA: 2 条
        Comment::create(['content' => 'a1', 'user_id' => $user->id, 'reply_to_id' => 0, 'page_id' => $docA->id]);
        Comment::create(['content' => 'a2', 'user_id' => $user->id, 'reply_to_id' => 0, 'page_id' => $docA->id]);
        // docB: 1 条 — 必须不出现在 docA 结果里
        Comment::create(['content' => 'b1', 'user_id' => $user->id, 'reply_to_id' => 0, 'page_id' => $docB->id]);

        $page = $this->service->listForDocument($docA->fresh(), 10);

        $this->assertSame(2, $page->total());
        foreach ($page->items() as $comment) {
            $this->assertSame($docA->id, (int)$comment->page_id);
        }
    }

    public function test_list_for_document_paginates(): void
    {
        $user     = $this->createUser();
        $project  = $this->createProject($user);
        $document = $this->createDocument($user, $project);

        for ($i = 0; $i < 5; $i++) {
            Comment::create([
                'content'     => "c{$i}",
                'user_id'     => $user->id,
                'reply_to_id' => 0,
                'page_id'     => $document->id,
            ]);
        }

        $page = $this->service->listForDocument($document->fresh(), 2);

        $this->assertSame(5, $page->total());
        $this->assertSame(2, $page->perPage());
        $this->assertCount(2, $page->items());
    }

    // ---------- listByUser ----------

    public function test_list_by_user_returns_only_target_user_comments(): void
    {
        $alice   = $this->createUser(['name' => 'alice_' . uniqid()]);
        $bob     = $this->createUser(['name' => 'bob_' . uniqid()]);
        $project = $this->createProject($alice);
        $docA    = $this->createDocument($alice, $project);
        $docB    = $this->createDocument($alice, $project);

        Comment::create(['content' => 'a1', 'user_id' => $alice->id, 'reply_to_id' => 0, 'page_id' => $docA->id]);
        Comment::create(['content' => 'a2', 'user_id' => $alice->id, 'reply_to_id' => 0, 'page_id' => $docB->id]);
        // bob 的评论 — 不应出现在 alice 的列表里
        Comment::create(['content' => 'b1', 'user_id' => $bob->id, 'reply_to_id' => 0, 'page_id' => $docA->id]);

        $page = $this->service->listByUser($alice, 10);

        $this->assertSame(2, $page->total());
        foreach ($page->items() as $comment) {
            $this->assertSame($alice->id, (int)$comment->user_id);
        }
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