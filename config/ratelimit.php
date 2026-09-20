<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

/*
|--------------------------------------------------------------------------
| Rate Limiter Thresholds
|--------------------------------------------------------------------------
|
| Wizard 的命名限速器阈值集中配置。
|
| 在 AppServiceProvider::boot() 中通过 RateLimiter::for(...) 注册,
| 配合路由的 throttle:<name> 中间件生效。
|
| key 字段说明每个限速器的限速键策略:
|   - user:    Limit::perMinute(N)->by($request->user()?->id)
|   - ip:      Limit::perMinute(N)->by($request->ip())
|   - default: 已登录按 user.id,匿名按 IP (默认行为,无需在 limiter 中显式 ->by)
|
*/

return [
    /*
    | 通用只读 API:60/min 的细粒度 GET,默认按 user/ip 自动分流。
    | 用于 GET /api/project/{id},/api/project/{id}/documents 等大多数读接口。
    */
    'api-read' => 120,

    /*
    | 通用写 API:30/min,按 user/ip 自动分流。
    | 用于 POST/PUT/PATCH/DELETE 的通用写操作。
    */
    'api-write' => 30,

    /*
    | 项目列表高频拉取:300/min,按 user.id 限速。
    | 浏览器扩展(Chrome / Firefox)轮询列表时使用,阈值较高。
    */
    'api-lists' => 300,

    /*
    | 文档搜索:60/min,按 user.id (匿名按 IP) 限速。
    */
    'api-search' => 60,

    /*
    | 批量导出:5/min,按 user.id 限速(成本高,严格管控)。
    */
    'api-export' => 5,

    /*
    | OAuth 令牌申请:10/min,按 IP 限速(防爆破)。
    | 包含 /api/oauth/token 与 Passport 自动注册的 /oauth/v2/token。
    */
    'oauth-token' => 10,

    /*
    | Web 登录:5/min,按 name|ip 限速。
    | 同一用户名在同一 IP 下 5 次错误后,再请求一律 429。
    */
    'web-login' => 5,

    /*
    | Web 注册:3/min,按 IP 限速。
    */
    'web-register' => 3,

    /*
    | 密码找回 / 重置:5/min,按 IP 限速。
    | 覆盖 /password/email 和 /password/reset。
    */
    'web-password' => 5,
];
