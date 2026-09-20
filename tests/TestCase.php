<?php

namespace Tests;

use App\Repositories\Project;
use App\Repositories\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * 创建一个已激活的测试用户
     */
    protected function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name'     => 'user_' . uniqid(),
            'email'    => uniqid('user_') . '@example.com',
            'password' => bcrypt('password'),
            'role'     => User::ROLE_NORMAL,
            'status'   => User::STATUS_ACTIVATED,
        ], $attributes));
    }

    /**
     * 创建一个管理员用户
     */
    protected function createAdmin(array $attributes = []): User
    {
        return $this->createUser(array_merge(['role' => User::ROLE_ADMIN], $attributes));
    }

    /**
     * 创建一个测试项目
     */
    protected function createProject(User $owner, array $attributes = []): Project
    {
        return Project::create(array_merge([
            'name'        => 'project_' . uniqid(),
            'description' => 'test project',
            'user_id'     => $owner->id,
            'visibility'  => Project::VISIBILITY_PUBLIC,
            'sort_level'  => 1000,
            'catalog_id'  => 0,
        ], $attributes));
    }
}
