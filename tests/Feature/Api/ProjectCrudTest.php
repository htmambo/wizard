<?php

namespace Tests\Feature\Api;

use App\Repositories\Document;
use App\Repositories\Group;
use App\Repositories\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProjectCrudTest extends TestCase
{
    use RefreshDatabase;

    // ---------- create ----------

    public function test_create_project_successfully(): void
    {
        $user = $this->createUser();

        Passport::actingAs($user);
        $response = $this->postJson('/api/project/create', [
            'name'        => '我的新项目',
            'description' => '描述',
            'visibility'  => Project::VISIBILITY_PUBLIC,
            'catalog'     => 0,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', '我的新项目');
        $this->assertDatabaseHas('projects', [
            'name'    => '我的新项目',
            'user_id' => $user->id,
        ]);
    }

    public function test_create_project_fails_validation_when_name_is_empty(): void
    {
        Passport::actingAs($this->createUser());
        $response = $this->postJson('/api/project/create', [
            'name'       => '',
            'visibility' => Project::VISIBILITY_PUBLIC,
            'catalog'    => 0,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('projects', ['visibility' => Project::VISIBILITY_PUBLIC]);
    }

    public function test_create_project_forbidden_for_inactive_user(): void
    {
        $user = $this->createUser(['status' => \App\Repositories\User::STATUS_NONE]);

        Passport::actingAs($user);
        $response = $this->postJson('/api/project/create', [
            'name'       => '不允许创建',
            'visibility' => Project::VISIBILITY_PUBLIC,
            'catalog'    => 0,
        ]);

        $response->assertStatus(403);
    }

    // ---------- view ----------

    public function test_view_existing_project(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);

        Passport::actingAs($owner);
        $response = $this->getJson("/api/project/{$project->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.name', $project->name);
    }

    public function test_view_nonexistent_project_returns_404(): void
    {
        Passport::actingAs($this->createUser());
        $this->getJson('/api/project/99999')->assertStatus(404);
    }

    public function test_view_private_project_of_other_user_returns_403(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);
        $viewer = $this->createUser();

        Passport::actingAs($viewer);
        $this->getJson("/api/project/{$project->id}")->assertStatus(403);
    }

    // ---------- update ----------

    public function test_owner_can_update_project(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);

        Passport::actingAs($owner);
        $response = $this->putJson("/api/project/update/{$project->id}", [
            'name'       => '改名后的项目',
            'visibility' => Project::VISIBILITY_PRIVATE,
            'catalog'    => 0,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('projects', [
            'id'         => $project->id,
            'name'       => '改名后的项目',
            'visibility' => Project::VISIBILITY_PRIVATE,
        ]);
    }

    public function test_non_owner_cannot_update_project(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $other = $this->createUser();

        Passport::actingAs($other);
        $response = $this->putJson("/api/project/update/{$project->id}", [
            'name'       => '越权修改',
            'visibility' => Project::VISIBILITY_PUBLIC,
            'catalog'    => 0,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => $project->name]);
    }

    // ---------- delete ----------

    public function test_owner_can_soft_delete_project(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);

        Passport::actingAs($owner);
        $response = $this->deleteJson("/api/project/delete/{$project->id}");

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_non_owner_cannot_delete_project(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $other = $this->createUser();

        Passport::actingAs($other);
        $this->deleteJson("/api/project/delete/{$project->id}")->assertStatus(403);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'deleted_at' => null]);
    }

    // ---------- members ----------

    public function test_owner_can_add_group_member_to_project(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'group_' . uniqid(), 'user_id' => $owner->id]);

        Passport::actingAs($owner);
        $response = $this->postJson("/api/project/{$project->id}/members/add", [
            'group_id'  => $group->id,
            'privilege' => 'wr',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.group_id', $group->id)
            ->assertJsonPath('data.privilege', Project::PRIVILEGE_WR);
        $this->assertDatabaseHas('project_group_ref', [
            'project_id' => $project->id,
            'group_id'   => $group->id,
            'privilege'  => Project::PRIVILEGE_WR,
        ]);
    }

    public function test_add_member_forbidden_for_non_owner(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'group_' . uniqid(), 'user_id' => $owner->id]);
        $other = $this->createUser();

        Passport::actingAs($other);
        $response = $this->postJson("/api/project/{$project->id}/members/add", [
            'group_id' => $group->id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('project_group_ref', [
            'project_id' => $project->id,
            'group_id'   => $group->id,
        ]);
    }

    public function test_add_member_fails_validation_when_group_not_exists(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);

        Passport::actingAs($owner);
        $this->postJson("/api/project/{$project->id}/members/add", [
            'group_id' => 99999,
        ])->assertStatus(422);
    }

    public function test_list_project_members(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'group_' . uniqid(), 'user_id' => $owner->id]);
        $project->groups()->attach($group->id, ['privilege' => Project::PRIVILEGE_RO]);

        Passport::actingAs($owner);
        $response = $this->getJson("/api/project/{$project->id}/members");

        $response->assertStatus(200)->assertJson(['success' => true]);
        $members = collect($response->json('data'));
        $this->assertTrue($members->contains('id', $group->id));
        $this->assertEquals(
            Project::PRIVILEGE_RO,
            $members->firstWhere('id', $group->id)['privilege']
        );
    }

    public function test_members_forbidden_for_user_without_view_permission(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);
        $other = $this->createUser();

        Passport::actingAs($other);
        $this->getJson("/api/project/{$project->id}/members")->assertStatus(403);
    }

    public function test_owner_can_delete_group_member(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'group_' . uniqid(), 'user_id' => $owner->id]);
        $project->groups()->attach($group->id, ['privilege' => Project::PRIVILEGE_RO]);

        Passport::actingAs($owner);
        $response = $this->deleteJson("/api/project/{$project->id}/members/delete/{$group->id}");

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseMissing('project_group_ref', [
            'project_id' => $project->id,
            'group_id'   => $group->id,
        ]);
    }

    public function test_delete_member_returns_404_when_not_a_member(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'group_' . uniqid(), 'user_id' => $owner->id]);

        Passport::actingAs($owner);
        $this->deleteJson("/api/project/{$project->id}/members/delete/{$group->id}")
            ->assertStatus(404);
    }

    public function test_delete_member_forbidden_for_non_owner(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $group = Group::create(['name' => 'group_' . uniqid(), 'user_id' => $owner->id]);
        $project->groups()->attach($group->id, ['privilege' => Project::PRIVILEGE_RO]);
        $other = $this->createUser();

        Passport::actingAs($other);
        $this->deleteJson("/api/project/{$project->id}/members/delete/{$group->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('project_group_ref', [
            'project_id' => $project->id,
            'group_id'   => $group->id,
        ]);
    }

    // ---------- logs ----------

    public function test_project_logs_return_paginated_structure(): void
    {
        $owner = $this->createUser();

        Passport::actingAs($owner);
        // 创建项目会触发 ProjectCreated 事件并写入操作日志
        $projectId = $this->postJson('/api/project/create', [
            'name'       => '带日志的项目',
            'visibility' => Project::VISIBILITY_PUBLIC,
            'catalog'    => 0,
        ])->json('data.id');

        $response = $this->getJson("/api/project/{$projectId}/logs");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data',
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);
        $this->assertGreaterThanOrEqual(1, $response->json('meta.total'));
    }

    public function test_logs_forbidden_for_user_without_view_permission(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);
        $other = $this->createUser();

        Passport::actingAs($other);
        $this->getJson("/api/project/{$project->id}/logs")->assertStatus(403);
    }

    public function test_logs_of_nonexistent_project_returns_404(): void
    {
        Passport::actingAs($this->createUser());
        $this->getJson('/api/project/99999/logs')->assertStatus(404);
    }

    // ---------- documents ----------

    public function test_project_documents_return_paginated_structure(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        foreach (['文档A', '文档B'] as $title) {
            Document::create([
                'pid'        => 0,
                'title'      => $title,
                'content'    => 'content',
                'project_id' => $project->id,
                'user_id'    => $owner->id,
                'type'       => Document::TYPE_DOC,
                'status'     => Document::STATUS_NORMAL,
                'sort_level' => 1000,
            ]);
        }

        Passport::actingAs($owner);
        $response = $this->getJson("/api/project/{$project->id}/documents?per_page=1");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data',
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);
        $this->assertEquals(2, $response->json('meta.total'));
        $this->assertEquals(1, $response->json('meta.per_page'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_documents_forbidden_for_user_without_view_permission(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);
        $other = $this->createUser();

        Passport::actingAs($other);
        $this->getJson("/api/project/{$project->id}/documents")->assertStatus(403);
    }

    public function test_documents_of_nonexistent_project_returns_404(): void
    {
        Passport::actingAs($this->createUser());
        $this->getJson('/api/project/99999/documents')->assertStatus(404);
    }
}
