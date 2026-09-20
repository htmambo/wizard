<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * 统一异常日志记录器(AD4 + AD6)。
 *
 * 用法:
 *   } catch (\Throwable $e) {
 *       \App\Support\ErrorLogger::record($e, ['context' => 'document_sync', 'doc_id' => $id]);
 *       throw $e;  // 重新抛出或按业务处理
 *   }
 *
 * 自动追加的运行时上下文:
 *   - http_method / url / route_name / ip / user_id / user_agent / referer / request_id
 *   - call_stack: 异常发生位置之上的 15 帧 PHP 调用栈(便于定位)
 *
 * 通道:
 *   - 默认 'stack'(Laravel 默认日志通道)
 *   - 安全相关异常(SSRF 拒绝、XSS 拦截)走 'security'(AD6)
 *     见 config/logging.php,可在生产环境单独配置告警/转发
 */
class ErrorLogger
{
    /**
     * 占位符:无 HTTP 请求上下文时(CLI、队列、Artisan 等)。
     */
    private const PLACEHOLDER = '-';

    /**
     * 记录异常到日志。
     *
     * 自动合并当前请求上下文(方法/URL/IP/UA/User/RequestId 等),
     * 调用方可继续传入业务上下文(context、doc_id、user_id 等),冲突时调用方优先。
     *
     * @param  Throwable  $e       异常实例
     * @param  array      $context 额外上下文(用户ID、请求路径、业务ID 等)
     * @param  string     $channel 日志通道,默认 'stack'
     * @param  string     $level   日志级别(debug/info/notice/warning/error/critical),默认 'error'
     */
    public static function record(
        Throwable $e,
        array $context = [],
        string $channel = 'stack',
        string $level = 'error'
    ): void {
        $requestContext = self::resolveRequestContext();

        $payload = array_merge([
            'exception'  => get_class($e),
            'message'    => $e->getMessage(),
            'file'       => $e->getFile(),
            'line'       => $e->getLine(),
            'trace'      => collect($e->getTrace())->take(15)->all(),
            'call_stack' => self::captureCallStack(),
        ], $requestContext, $context);

        try {
            \Log::channel($channel)->{$level}($e->getMessage(), $payload);
        } catch (\Throwable $logFailure) {
            // 不调 ErrorLogger::record 自身,避免递归循环
            // 日志写入失败时写入独立文件,避免污染 Web Server 错误日志
            $fallbackPath = storage_path('logs/error_logger_fallback.log');
            $line = '[' . now()->format('Y-m-d H:i:s') . '] ErrorLogger Fallback: '
                  . $logFailure->getMessage()
                  . ' | original: ' . get_class($e) . ' - ' . $e->getMessage()
                  . PHP_EOL;
            @error_log($line, 3, $fallbackPath);
        }
    }

    /**
     * 解析当前请求上下文。若无 HTTP 请求(CLI / 队列 worker / 测试),使用安全占位符。
     *
     * @return array<string, mixed>
     */
    private static function resolveRequestContext(): array
    {
        $defaults = [
            'http_method' => self::PLACEHOLDER,
            'url'         => self::PLACEHOLDER,
            'route_name'  => self::PLACEHOLDER,
            'ip'          => self::PLACEHOLDER,
            'user_id'     => self::PLACEHOLDER,
            'user_agent'  => self::PLACEHOLDER,
            'referer'     => self::PLACEHOLDER,
            'request_id'  => self::PLACEHOLDER,
            'runtime'     => 'cli',
        ];

        try {
            $request = function_exists('request') ? request() : null;
        } catch (\Throwable $ignored) {
            $request = null;
        }

        if (!$request instanceof Request) {
            return $defaults;
        }

        $routeName = self::PLACEHOLDER;
        try {
            $route = $request->route();
            if (is_object($route) && method_exists($route, 'getName')) {
                $routeName = $route->getName() ?: self::PLACEHOLDER;
            }
        } catch (\Throwable $ignored) {
            // 路由未绑定(中间件触发时可能尚未分发),忽略
        }

        $userId = self::PLACEHOLDER;
        try {
            $authId = \Auth::id();
            if ($authId !== null && $authId !== '') {
                $userId = (int) $authId;
            }
        } catch (\Throwable $ignored) {
            // Auth facade 未就绪(早期启动异常)或请求不是真实 Request(CLI)时,忽略
        }

        $requestId = self::PLACEHOLDER;
        try {
            $attrId = $request->attributes->get('request_id');
            if (is_string($attrId) && $attrId !== '') {
                $requestId = $attrId;
            } else {
                $headerId = $request->headers->get('X-Request-Id');
                if (is_string($headerId) && $headerId !== '') {
                    $requestId = $headerId;
                }
            }
        } catch (\Throwable $ignored) {
            // 不可达则保留占位符
        }

        return [
            'http_method' => self::valueOrPlaceholder(strtoupper($request->getMethod() ?: '')),
            'url'         => self::valueOrPlaceholder($request->fullUrl() ?: ''),
            'route_name'  => $routeName,
            'ip'          => self::valueOrPlaceholder($request->ip() ?: ''),
            'user_id'     => $userId,
            'user_agent'  => self::valueOrPlaceholder((string) $request->headers->get('user-agent', '')),
            'referer'     => self::valueOrPlaceholder((string) $request->headers->get('referer', '')),
            'request_id'  => $requestId,
            'runtime'     => 'http',
        ];
    }

    /**
     * 将空字符串归一为占位符,并对过长的字符串做截断,防止日志/响应被滥用。
     * Symfony Request 在缺少客户端头时会回退默认 UA("Symfony"),这里一并视为空。
     */
    private static function valueOrPlaceholder(string $value, int $maxLen = 512): string
    {
        $value = trim($value);
        if ($value === '' || $value === 'Symfony') {
            return self::PLACEHOLDER;
        }
        return mb_substr($value, 0, $maxLen);
    }

    /**
     * 捕获异常抛出位置之上的 PHP 调用栈,过滤掉本类自身避免噪音。
     *
     * @return array<int, array<string, mixed>>
     */
    private static function captureCallStack(): array
    {
        try {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16);
        } catch (\Throwable $ignored) {
            return [];
        }

        $filtered = [];
        foreach ($frames as $frame) {
            $class = $frame['class'] ?? '';
            if ($class === self::class) {
                continue;
            }
            $filtered[] = [
                'file'     => $frame['file'] ?? null,
                'line'     => $frame['line'] ?? null,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
            if (count($filtered) >= 15) {
                break;
            }
        }

        return $filtered;
    }
}
