<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry before_send 事件过滤器。
 *
 * 集中维护 4xx 噪声过滤列表,与 ErrorLogger::isReportingException /
 * Exceptions\Handler::reportable 共享同一规则(单一可信源)。
 *
 * 通过 SentryEventFilter::class 作为字符串引用,避免 config:cache 序列化
 * Closure 时的 __set_state 错误。
 */
class SentryEventFilter
{
    /**
     * Sentry before_send 钩子入口。
     *
     * 返回 null 时,Sentry SDK 丢弃该事件。
     *
     * @param Event      $event
     * @param EventHint|null $hint
     * @return Event|null
     */
    public function __invoke(?Event $event, ?EventHint $hint = null): ?Event
    {
        if ($event === null) {
            return null;
        }

        try {
            $exception = $hint?->exception;
            if ($exception instanceof \Throwable) {
                foreach (self::ignoredClasses() as $class) {
                    if ($exception instanceof $class || get_class($exception) === $class) {
                        return null;
                    }
                }
            }
        } catch (\Throwable $ignored) {
            // 钩子自身异常不应阻断事件上报,放行
        }

        return $event;
    }

    /**
     * 4xx 噪声异常类列表 — 单一可信源。
     *
     * ErrorLogger::isReportingException 与
     * Exceptions\Handler::register() 的 reportable 闭包都引用本列表。
     *
     * @return array<int, class-string<\Throwable>>
     */
    public static function ignoredClasses(): array
    {
        return [
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        ];
    }
}