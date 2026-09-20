<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Providers;

use App\Observers\DocumentObserver;
use App\Observers\GroupObserver;
use App\Observers\ProjectObserver;
use App\Repositories\Document;
use App\Repositories\Group;
use App\Repositories\InvitationCode;
use App\Repositories\Project;
use App\Repositories\Template;
use App\Repositories\User;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Laravel\Passport\Passport;
use Carbon\CarbonInterval;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Scramble::configure()
                ->withDocumentTransformers(function (OpenApi $openApi) {
                    // 定义安全方案
                    $openApi->secure(
                        SecurityScheme::http('Bearer', 'JWT')
                    );
                });

        // 用于解决某些版本的mysql下，由于默认编码为utf8mb4而导致出现错误
        // Syntax error or access violation: 1071 Specified key was too long; max key length is 767 bytes
        Schema::defaultStringLength(191);

        // 启用所有的授权类型

        // 配置令牌过期时间
        Passport::tokensExpireIn(CarbonInterval::days(15));
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::personalAccessTokensExpireIn(CarbonInterval::months(6));

        // 启用密码授权类型
        // 注意：Passport 13 的 Password Grant 默认未注册，必须显式启用，
        // 否则 grant_type=password 的令牌请求（浏览器扩展登录）会返回 unsupported_grant_type
        Passport::enablePasswordGrant();

        \Illuminate\Pagination\Paginator::useBootstrap();
        $this->addProjectExistRules('project_exist');
        $this->addPageExistRules('page_exist');
        $this->addTemplateUniqueRules('template_unique');
        $this->addGroupExistRule('group_exist');
        $this->addCheckUserPasswordRules('user_password');
        $this->addUsernameUniqueRules('username_unique');
        $this->addInvitationCodeRules('invitation_code');

        // 在日志中输出sql历史
        if (config('app.debug')) {
            \DB::listen(function (QueryExecuted $query) {
                \Log::debug('sql_execute', [
                    'sql'   => $query->sql,
                    'binds' => $query->bindings,
                ]);
            });
        }

        // 注册导航缓存 Observer(AD3:文档/项目/用户组变更触发失效)
        Document::observe(DocumentObserver::class);
        Project::observe(ProjectObserver::class);
        Group::observe(GroupObserver::class);

        $this->registerRateLimiters();
    }

    /**
     * 注册命名限速器(throttle:<name> 中间件引用)。
     *
     * 阈值集中在 config/ratelimit.php,便于调整。
     * 429 响应统一为 ApiResponse 格式(success/message/request_id),
     * 同时复用 RequestId 中间件写入的 X-Request-Id 属性,
     * 方便客户端排错与日志关联。
     */
    private function registerRateLimiters(): void
    {
        $buildJsonResponse = static function (Request $request, array $headers): \Illuminate\Http\JsonResponse {
            return response()->json([
                'success'    => false,
                'message'    => 'Too Many Requests',
                'request_id' => $request->attributes->get('request_id', '-'),
            ], 429)->withHeaders($headers);
        };

        // 通用只读 API:已登录按 user.id,匿名按 IP(Limit 默认行为)
        RateLimiter::for('api-read', function (Request $request) use ($buildJsonResponse) {
            $limit = (int) config('ratelimit.api-read', 120);
            return Limit::perMinute($limit)
                ->by($request->user()?->id ?: $request->ip())
                ->response($buildJsonResponse);
        });

        // 通用写 API:已登录按 user.id,匿名按 IP
        RateLimiter::for('api-write', function (Request $request) use ($buildJsonResponse) {
            $limit = (int) config('ratelimit.api-write', 30);
            return Limit::perMinute($limit)
                ->by($request->user()?->id ?: $request->ip())
                ->response($buildJsonResponse);
        });

        // 项目列表:按 user.id(浏览器扩展高频轮询)
        RateLimiter::for('api-lists', function (Request $request) use ($buildJsonResponse) {
            $limit = (int) config('ratelimit.api-lists', 300);
            return Limit::perMinute($limit)
                ->by($request->user()?->id ?: $request->ip())
                ->response($buildJsonResponse);
        });

        // 文档搜索:按 user.id (匿名按 IP)
        RateLimiter::for('api-search', function (Request $request) use ($buildJsonResponse) {
            $limit = (int) config('ratelimit.api-search', 60);
            return Limit::perMinute($limit)
                ->by($request->user()?->id ?: $request->ip())
                ->response($buildJsonResponse);
        });

        // 批量导出:按 user.id,严格管控
        RateLimiter::for('api-export', function (Request $request) use ($buildJsonResponse) {
            $limit = (int) config('ratelimit.api-export', 5);
            return Limit::perMinute($limit)
                ->by($request->user()?->id ?: $request->ip())
                ->response($buildJsonResponse);
        });

        // OAuth 令牌:按 IP(防爆破)
        RateLimiter::for('oauth-token', function (Request $request) use ($buildJsonResponse) {
            $limit = (int) config('ratelimit.oauth-token', 10);
            return Limit::perMinute($limit)
                ->by($request->ip())
                ->response($buildJsonResponse);
        });

        // Web 登录:按 name|ip,允许匿名(name 不一定存在,使用请求体 name 字段作为辅助)
        RateLimiter::for('web-login', function (Request $request) use ($buildJsonResponse) {
            $limit = (int) config('ratelimit.web-login', 5);
            $key = (string) ($request->input('name') ?? '') . '|' . $request->ip();
            return Limit::perMinute($limit)
                ->by($key)
                ->response($buildJsonResponse);
        });

        // Web 注册:按 IP
        RateLimiter::for('web-register', function (Request $request) use ($buildJsonResponse) {
            $limit = (int) config('ratelimit.web-register', 3);
            return Limit::perMinute($limit)
                ->by($request->ip())
                ->response($buildJsonResponse);
        });

        // Web 密码找回/重置:按 IP
        RateLimiter::for('web-password', function (Request $request) use ($buildJsonResponse) {
            $limit = (int) config('ratelimit.web-password', 5);
            return Limit::perMinute($limit)
                ->by($request->ip())
                ->response($buildJsonResponse);
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        Passport::ignoreRoutes();
    }

    /**
     * 检查页面是否存在
     *
     * 参数：项目ID,是否验证0值
     *
     * @param string $ruleName
     */
    private function addPageExistRules(string $ruleName)
    {
        $this->registerValidationRule(
            $ruleName,
            '参数 %s 对应的页面不存在',
            function ($attribute, $value, $parameters, $validator) {
                $projectID = $parameters[0] ?? 0;
                $validateZeroValue = isset($parameters[1]) ? ($parameters[1] == 'true') : true;

                if (empty($value)) {
                    return !$validateZeroValue;
                }

                $conditions = [];
                $conditions[] = ['id', $value];

                if (!empty($projectID)) {
                    $conditions[] = ['project_id', $projectID];
                }

                return Document::where($conditions)->exists();
            }
        );
    }

    /**
     * 添加检查项目是否存在的规则
     *
     * @param string $ruleName
     */
    private function addProjectExistRules(string $ruleName)
    {
        $this->registerValidationRule(
            $ruleName,
            '参数 %s 对应的项目不存在',
            function ($attribute, $value, $parameters, $validator) {
                return Project::where('id', $value)->exists();
            }
        );
    }

    /**
     * 新增用户名唯一校验规则
     *
     * @param string $ruleName
     */
    private function addUsernameUniqueRules(string $ruleName)
    {
        $this->registerValidationRule(
            $ruleName,
            '用户名已经存在',
            function ($attribute, $value, $parameters, $validator) {
                $excludeId = $parameters[0] ?? 0;

                $user = User::where('name', $value);
                if (!empty($excludeId)) {
                    $user = $user->where('id', '!=', $excludeId);
                }

                return !$user->exists();
            }
        );
    }

    /**
     * 添加检查用户密码是否合法的规则
     *
     * @param string $ruleName
     */
    private function addCheckUserPasswordRules(string $ruleName)
    {
        $this->registerValidationRule(
            $ruleName,
            '密码 % 不合法',
            function ($attribute, $value, $parameters, $validator) {
                $user = \Auth::user()->makeVisible('password');
                return \Hash::check($value, $user->password);
            }
        );
    }

    /**
     * 添加邀请码校验规则
     *
     * @param string $ruleName
     */
    private function addInvitationCodeRules(string $ruleName)
    {
        $this->registerValidationRule(
            $ruleName,
            '邀请码无效',
            function ($attribute, $value, $parameters, $validator) {
                $staticCode = config('wizard.register_invitation_static');
                if (empty($staticCode)) {
                    return false;
                }

                if ($value === $staticCode) {
                    return true;
                }

                /** @var InvitationCode $invitationCode */
                $invitationCode = InvitationCode::where('code', $value)->first();
                if (empty($invitationCode)) {
                    return false;
                }

                return $invitationCode->expired_at === null
                       || Carbon::now()->isBefore(Carbon::createFromTimeString($invitationCode->expired_at));
            }
        );
    }

    /**
     * 添加检查分组名称是否存在的规则
     *
     * @param string $ruleName
     */
    private function addGroupExistRule(string $ruleName)
    {
        $this->registerValidationRule(
            $ruleName,
            '参数 %s 对应的用户组不存在',
            function ($attribute, $value, $parameters, $validator) {
                return Group::where('id', $value)->exists();
            }
        );
    }

    /**
     * 模板名称是否唯一
     *
     * @param string $ruleName
     */
    private function addTemplateUniqueRules(string $ruleName)
    {
        $this->registerValidationRule(
            $ruleName,
            '模板名称已经存在',
            function ($attribute, $value, $parameters, $validator) {
                $excludeId = $parameters[0] ?? 0;

                $template = Template::where('name', $value);
                if (!empty($excludeId)) {
                    $template = $template->where('id', '!=', $excludeId);
                }

                return !$template->exists();
            }
        );
    }

    /**
     * 注册校验规则
     *
     * @param string $ruleName
     * @param string $validationMessage
     * @param \Closure $callback
     */
    private function registerValidationRule(
        string $ruleName,
        string $validationMessage,
        \Closure $callback
    ) {
        \Validator::extend($ruleName, $callback);
        \Validator::replacer($ruleName,
            function ($message, $attribute, $rule, $parameters) use (
                $ruleName,
                $validationMessage
            ) {
                if ($message == "validation.{$ruleName}") {
                    return sprintf($validationMessage, $attribute);
                }

                return $message;
            });
    }
}
