<?php

namespace Tests\Feature\Api;

use App\Repositories\Group;
use App\Repositories\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProjectListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_gets_401(): void
    {
        $this->getJson('/api/project/lists.json')->assertStatus(401);
    }

    public function test_public_projects_are_visible_to_authenticated_user(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PUBLIC]);
        $viewer = $this->createUser();

        Passport::actingAs($viewer);
        $response = $this->getJson('/api/project/lists.json');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertContains(
            $project->id,
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_own_private_project_is_visible(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);

        Passport::actingAs($owner);
        $response = $this->getJson('/api/project/lists.json');

        $response->assertStatus(200);
        $this->assertContains(
            $project->id,
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_other_users_private_project_is_not_visible(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);
        $viewer = $this->createUser();

        Passport::actingAs($viewer);
        $response = $this->getJson('/api/project/lists.json');

        $response->assertStatus(200);
        $this->assertNotContains(
            $project->id,
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    /**
     * 回归测试：别人的私有项目通过 project_group_ref 关联了当前用户所在的用户组时必须可见
     */
    public function test_private_project_shared_with_users_group_is_visible(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);
        $viewer = $this->createUser();

        $group = Group::create(['name' => 'group_' . uniqid(), 'user_id' => $owner->id]);
        $group->users()->attach($viewer->id);
        $project->groups()->attach($group->id, ['privilege' => Project::PRIVILEGE_RO]);

        Passport::actingAs($viewer);
        $response = $this->getJson('/api/project/lists.json');

        $response->assertStatus(200);
        $this->assertContains(
            $project->id,
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    /**
     * 反向用例：私有项目关联的用户组与当前用户无关时仍不可见
     */
    public function test_private_project_shared_with_other_group_is_not_visible(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, ['visibility' => Project::VISIBILITY_PRIVATE]);
        $viewer = $this->createUser();

        $projectGroup = Group::create(['name' => 'group_' . uniqid(), 'user_id' => $owner->id]);
        $viewerGroup = Group::create(['name' => 'group_' . uniqid(), 'user_id' => $viewer->id]);
        $project->groups()->attach($projectGroup->id, ['privilege' => Project::PRIVILEGE_RO]);
        $viewerGroup->users()->attach($viewer->id);

        Passport::actingAs($viewer);
        $response = $this->getJson('/api/project/lists.json');

        $response->assertStatus(200);
        $this->assertNotContains(
            $project->id,
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_response_fields_include_id_name_catalog_id_and_sort_level(): void
    {
        $owner = $this->createUser();
        $this->createProject($owner, ['catalog_id' => 0, 'sort_level' => 1234]);

        Passport::actingAs($owner);
        $response = $this->getJson('/api/project/lists.json');

        $response->assertStatus(200);
        $project = collect($response->json('data'))->first();
        $this->assertNotNull($project);
        foreach (['id', 'name', 'catalog_id', 'sort_level'] as $field) {
            $this->assertArrayHasKey($field, $project);
        }
    }
}
