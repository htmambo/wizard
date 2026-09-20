<?php

/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

use App\Support\SentryEventFilter;

/**
 * Sentry Laravel SDK 配置。
 *
 * 在 sentry/sentry-laravel 默认配置基础上:
 *   - dsn / traces_sample_rate / profiles_sample_rate / environment / release / send_default_pii 全部来自 .env
 *   - release 留空时回调 git rev-parse 取短哈希(便于本地/CI 自动追踪)
 *   - before_send 过滤掉 4xx 噪声异常(404 / 401 / 422 / ModelNotFound),避免淹没真实 5xx
 *
 * 当 SENTRY_LARAVEL_DSN 为空时,Sentry SDK 自动 noop,不会向网络发送事件,
 * 但本钩子仍会被调用,只是事件不会经 HttpTransport 出栈 — 故过滤逻辑在此集中维护,
 * 客户端/服务端双重保险。
 *
 * before_send 必须用类名而非闭包引用,否则 config:cache 序列化失败
 * (Closure::__set_state 不存在)。
 */
return [

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    'release' => env('SENTRY_RELEASE') ?: trim((string) @exec('git --git-dir ' . base_path('.git') . ' rev-parse --short HEAD 2>/dev/null')),

    'environment' => env('SENTRY_ENVIRONMENT') ?: (function () {
        try {
            return function_exists('app') && app()->bound('env') ? app()->environment() : 'production';
        } catch (\Throwable $e) {
            return 'production';
        }
    })(),

    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? 0.2 : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? 0.1 : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    'ignore_transactions' => [
        '/up',
    ],

    /**
     * Sentry before_send 钩子 — 通过 SentryEventFilter 类的 __invoke 引用,保证可序列化。
     *
     * 用数组形式 `[$class, $method]`,满足 is_callable() 且能被 config:cache 序列化。
     * 类名静态引用,IDE 自动跳转,无 Closure 序列化风险。
     */
    'before_send' => [SentryEventFilter::class, '__invoke'],

    'breadcrumbs' => [
        'logs'              => env('SENTRY_BREADCRUMBS_LOGS_ENABLED', true),
        'cache'             => env('SENTRY_BREADCRUMBS_CACHE_ENABLED', true),
        'livewire'          => env('SENTRY_BREADCRUMBS_LIVEWIRE_ENABLED', true),
        'sql_queries'       => env('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', true),
        'sql_bindings'      => env('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED', false),
        'queue_info'        => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),
        'command_info'      => env('SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED', true),
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', true),
        'notifications'     => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
    ],

    'tracing' => [
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),
        'queue_jobs'             => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),
        'sql_queries'            => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),
        'sql_bindings'           => env('SENTRY_TRACE_SQL_BINDINGS_ENABLED', false),
        'sql_origin'             => env('SENTRY_TRACE_SQL_ORIGIN_ENABLED', true),
        'sql_origin_threshold_ms' => env('SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS', 100),
        'views'                  => env('SENTRY_TRACE_VIEWS_ENABLED', true),
        'livewire'               => env('SENTRY_TRACE_LIVEWIRE_ENABLED', true),
        'http_client_requests'   => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', true),
        'cache'                  => env('SENTRY_TRACE_CACHE_ENABLED', true),
        'redis_commands'         => env('SENTRY_TRACE_REDIS_COMMANDS', false),
        'redis_origin'           => env('SENTRY_TRACE_REDIS_ORIGIN_ENABLED', true),
        'notifications'          => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),
        'missing_routes'         => env('SENTRY_TRACE_MISSING_ROUTES_ENABLED', false),
        'continue_after_response' => env('SENTRY_TRACE_CONTINUE_AFTER_RESPONSE', true),
        'default_integrations'   => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED', true),
    ],

];