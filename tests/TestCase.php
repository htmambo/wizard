<?php

namespace Tests;

use App\Repositories\Project;
use App\Repositories\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * 测试方法 setUp: 清理限速计数器,避免测试间串扰。
     *
     * 命名限速器(api-read/api-write/api-lists/api-search/api-export/
     * oauth-token/web-login/web-register/web-password)使用 Cache 存储 hit 计数。
     * phpunit.xml 已将 CACHE_DRIVER 设为 array,array cache 一般会在每次
     * Application 重建时清空,但同一进程内多个测试可能复用缓存,故 defensive
     * 主动 flush。
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('api-read');
        RateLimiter::clear('api-write');
        RateLimiter::clear('api-lists');
        RateLimiter::clear('api-search');
        RateLimiter::clear('api-export');
        RateLimiter::clear('oauth-token');
        RateLimiter::clear('web-login');
        RateLimiter::clear('web-register');
        RateLimiter::clear('web-password');
    }

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
