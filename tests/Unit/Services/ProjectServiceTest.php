<?php

namespace Tests\Unit\Services;

use App\Events\ProjectCreated;
use App\Events\ProjectDeleted;
use App\Events\ProjectModified;
use App\Repositories\Group;
use App\Repositories\Project;
use App\Repositories\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProjectServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectService();
    }

    // ---------- listVisibleForUser ----------

    public function test_list_includes_public_projects(): void
    {
        $owner = $this->createUser();
        $public = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PUBLIC]);
        $viewer = $this->createUser();

        $result = $this->service->listVisibleForUser($viewer);

        $this->assertTrue($result->contains(fn (Project $p) => $p->id === $public->id));
    }

    public function test_list_includes_owners_own_private_project(): void
    {
        $owner = $this->createUser();
        $private = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);

        $result = $this->service->listVisibleForUser($owner);

        $this->assertTrue($result->contains(fn (Project $p) => $p->id === $private->id));
    }

    public function test_list_excludes_other_users_private_project(): void
    {
        $owner = $this->createUser();
        $private = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);
        $viewer = $this->createUser();

        $result = $this->service->listVisibleForUser($viewer);

        $this->assertFalse($result->contains(fn (Project $p) => $p->id === $private->id));
    }

    /**
     * 关键回归：别人私有项目若关联当前用户所在用户组，则必须可见
     */
    public function test_list_includes_private_project_shared_via_users_group(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);
        $viewer = $this->createUser();

        $group = Group::create(['name' => 'g_' . uniqid(), 'user_id' => $owner->id]);
        $group->users()->attach($viewer->id);
        $project->groups()->attach($group->id, ['privilege' => Project::PRIVILEGE_RO]);

        $result = $this->service->listVisibleForUser($viewer);

        $this->assertTrue($result->contains(fn (Project $p) => $p->id === $project->id));
    }

    public function test_list_orders_by_catalog_id_then_sort_level_then_id(): void
    {
        $owner = $this->createUser();

        // catalog 1: sort_level 2000 后于 1000
        $a = $this->createProject($owner, ['catalog_id' => 1, 'sort_level' => 2000]);
        $b = $this->createProject($owner, ['catalog_id' => 1, 'sort_level' => 1000]);
        // catalog 2: sort_level 500
        $c = $this->createProject($owner, ['catalog_id' => 2, 'sort_level' => 500]);

        $result = $this->service->listVisibleForUser($owner);

        $ids = $result->pluck('id')->all();
        $this->assertSame([$b->id, $a->id, $c->id], $ids);
    }

    public function test_list_selects_only_required_fields(): void
    {
        $owner = $this->createUser();
        $this->createProject($owner);

        $result = $this->service->listVisibleForUser($owner);

        $first = $result->first();
        $this->assertNotNull($first);
        foreach (['id', 'name', 'catalog_id', 'sort_level'] as $field) {
            $this->assertArrayHasKey($field, $first->getAttributes());
        }
    }

    // ---------- create ----------

    public function test_create_persists_project_and_dispatches_event(): void
    {
        Event::fake([ProjectCreated::class]);

        $user = $this->createUser();
        $project = $this->service->create($user, [
            'name'        => 'svc-create',
            'description' => 'd',
            'visibility'  => Project::VISIBILITY_PUBLIC,
            'sort_level'  => 1234,
            'catalog' => 0,
        ]);

        $this->assertSame('svc-create', $project->name);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'user_id' => $user->id]);
        Event::assertDispatched(ProjectCreated::class, fn (ProjectCreated $e) => $e->getProject()->id === $project->id);
    }

    public function test_create_overrides_sort_level_to_1000_when_user_lacks_project_sort(): void
    {
        Event::fake([ProjectCreated::class]);

        $user = $this->createUser(); // ROLE_NORMAL -> no project-sort
        $project = $this->service->create($user, [
            'name'        => 'no-sort',
            'description' => 'd',
            'visibility'  => Project::VISIBILITY_PUBLIC,
            'sort_level'  => 5,
            'catalog' => 0,
        ]);

        $this->assertSame(1000, (int)$project->sort_level);
    }

    public function test_create_keeps_sort_level_when_user_has_project_sort(): void
    {
        Event::fake([ProjectCreated::class]);

        $admin = $this->createAdmin(); // ROLE_ADMIN -> has project-sort
        $project = $this->service->create($admin, [
            'name'        => 'admin-sort',
            'description' => 'd',
            'visibility'  => Project::VISIBILITY_PUBLIC,
            'sort_level'  => 5,
            'catalog' => 0,
        ]);

        $this->assertSame(5, (int)$project->sort_level);
    }

    // ---------- update ----------

    public function test_update_does_not_dispatch_event_when_nothing_changed(): void
    {
        Event::fake([ProjectModified::class]);

        $owner = $this->createUser();
        $created = $this->createProject($owner, [
            'name'        => 'unchanged',
            'description' => 'd',
            'visibility'  => Project::VISIBILITY_PUBLIC,
            'sort_level'  => 1000,
            'catalog_id'  => 5,
        ]);
        $project = $created->fresh();

        $this->service->update($project, $owner, [
            'name'               => 'unchanged',
            'description'        => 'd',
            'visibility'         => Project::VISIBILITY_PUBLIC,
            'catalog'            => 5,
            'catalog_sort_style' => Project::SORT_STYLE_DIR_FIRST,
            'catalog_fold_style' => Project::FOLD_STYLE_AUTO,
        ]);

        Event::assertNotDispatched(ProjectModified::class);
    }

    public function test_update_dispatches_basic_event_when_dirty(): void
    {
        Event::fake([ProjectModified::class]);

        $owner = $this->createUser();
        $project = $this->createProject($owner, ['name' => 'before']);

        $this->service->update($project, $owner, [
            'name'               => 'after',
            'description'        => 'd',
            'visibility'         => Project::VISIBILITY_PUBLIC,
            'catalog'            => 0,
            'catalog_sort_style' => Project::SORT_STYLE_DIR_FIRST,
            'catalog_fold_style' => Project::FOLD_STYLE_AUTO,
        ]);

        Event::assertDispatched(
            ProjectModified::class,
            fn (ProjectModified $e) => $e->isBasicUpdate() && $e->getProject()->id === $project->id
        );
    }

    public function test_update_respects_project_sort_ability(): void
    {
        Event::fake([ProjectModified::class]);

        $user = $this->createUser(); // ROLE_NORMAL -> no project-sort
        $project = $this->createProject($user, ['sort_level' => 1000]);

        $this->service->update($project, $user, [
            'name'               => 'x',
            'description'        => 'd',
            'visibility'         => Project::VISIBILITY_PUBLIC,
            'catalog'            => 0,
            'catalog_sort_style' => Project::SORT_STYLE_DIR_FIRST,
            'catalog_fold_style' => Project::FOLD_STYLE_AUTO,
            'sort_level'         => 5,
        ]);

        $this->assertSame(1000, (int)$project->fresh()->sort_level);
    }

    // ---------- delete ----------

    public function test_delete_soft_deletes_and_dispatches_event(): void
    {
        Event::fake([ProjectDeleted::class]);

        $owner = $this->createUser();
        $project = $this->createProject($owner);

        $this->service->delete($project);

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        Event::assertDispatched(ProjectDeleted::class, fn (ProjectDeleted $e) => $e->getProject()->id === $project->id);
    }

    // ---------- addMember ----------

    public function test_add_member_wr_privilege_maps_to_PRIVILEGE_WR(): void
    {
        Event::fake([ProjectModified::class]);

        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'g_' . uniqid(), 'user_id' => $owner->id]);

        $this->service->addMember($project, $group->id, 'wr');

        $this->assertDatabaseHas('project_group_ref', [
            'project_id' => $project->id,
            'group_id'   => $group->id,
            'privilege'  => Project::PRIVILEGE_WR,
        ]);
        Event::assertDispatched(ProjectModified::class, fn (ProjectModified $e) => $e->isPrivilegeUpdate());
    }

    public function test_add_member_r_privilege_maps_to_PRIVILEGE_RO(): void
    {
        Event::fake([ProjectModified::class]);

        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'g_' . uniqid(), 'user_id' => $owner->id]);

        $this->service->addMember($project, $group->id, 'r');

        $this->assertDatabaseHas('project_group_ref', [
            'project_id' => $project->id,
            'group_id'   => $group->id,
            'privilege'  => Project::PRIVILEGE_RO,
        ]);
    }

    public function test_add_member_replaces_existing_privilege(): void
    {
        Event::fake([ProjectModified::class]);

        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'g_' . uniqid(), 'user_id' => $owner->id]);
        $project->groups()->attach($group->id, ['privilege' => Project::PRIVILEGE_RO]);

        $this->service->addMember($project, $group->id, 'wr');

        $this->assertDatabaseHas('project_group_ref', [
            'project_id' => $project->id,
            'group_id'   => $group->id,
            'privilege'  => Project::PRIVILEGE_WR,
        ]);
        $this->assertDatabaseCount('project_group_ref', 1);
    }

    // ---------- removeMember ----------

    public function test_remove_member_returns_true_and_dispatches_event_when_member_exists(): void
    {
        Event::fake([ProjectModified::class]);

        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'g_' . uniqid(), 'user_id' => $owner->id]);
        $project->groups()->attach($group->id, ['privilege' => Project::PRIVILEGE_RO]);

        $removed = $this->service->removeMember($project, $group->id);

        $this->assertTrue($removed);
        $this->assertDatabaseMissing('project_group_ref', [
            'project_id' => $project->id,
            'group_id'   => $group->id,
        ]);
        Event::assertDispatched(ProjectModified::class, fn (ProjectModified $e) => $e->isPrivilegeUpdate());
    }

    public function test_remove_member_returns_false_and_does_not_dispatch_when_member_missing(): void
    {
        Event::fake([ProjectModified::class]);

        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'g_' . uniqid(), 'user_id' => $owner->id]);

        $removed = $this->service->removeMember($project, $group->id);

        $this->assertFalse($removed);
        Event::assertNotDispatched(ProjectModified::class);
    }

    // ---------- getMembers ----------

    public function test_get_members_returns_pivot_fields(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'g_' . uniqid(), 'user_id' => $owner->id]);
        $project->groups()->attach($group->id, ['privilege' => Project::PRIVILEGE_RO]);

        $members = $this->service->getMembers($project);

        $this->assertCount(1, $members);
        $row = $members->first();
        $this->assertSame($group->id, $row['id']);
        $this->assertSame($group->name, $row['name']);
        $this->assertSame(Project::PRIVILEGE_RO, $row['privilege']);
        $this->assertNotNull($row['created_at']);
    }

    // ---------- listDocuments ----------

    public function test_list_documents_orders_by_sort_level_and_paginates(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);

        $titles = ['B-sort2000', 'A-sort1000', 'C-sort3000'];
        foreach ($titles as $i => $title) {
            \App\Repositories\Document::create([
                'pid'        => 0,
                'title'      => $title,
                'content'    => '',
                'project_id' => $project->id,
                'user_id'    => $owner->id,
                'type'       => \App\Repositories\Document::TYPE_DOC,
                'status'     => \App\Repositories\Document::STATUS_NORMAL,
                'sort_level' => [2000, 1000, 3000][$i],
            ]);
        }

        $page = $this->service->listDocuments($project, 2);

        $this->assertSame(3, $page->total());
        $this->assertSame(2, $page->perPage());
        $this->assertSame('A-sort1000', $page->items()[0]->title);
        $this->assertSame('B-sort2000', $page->items()[1]->title);
    }
}
