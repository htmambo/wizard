<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use Throwable;

/**
 * 统一异常日志记录器(AD4 + AD6)。
 *
 * 用法:
 *   } catch (\Throwable $e) {
            \App\Support\ErrorLogger::record($e, ['context' => 'ErrorLogger']);
 *       ErrorLogger::record($e, ['context' => 'document_sync', 'doc_id' => $id]);
 *       throw $e;  // 重新抛出或按业务处理
 *   }
 *
 * 通道:
 *   - 默认 'stack'(Laravel 默认日志通道)
 *   - 安全相关异常(SSRF 拒绝、XSS 拦截)走 'security'(AD6)
 *     见 config/logging.php,可在生产环境单独配置告警/转发
 */
class ErrorLogger
{
    /**
     * 记录异常到日志。
     *
     * @param  Throwable  $e       异常实例
     * @param  array      $context  额外上下文(用户ID、请求路径、业务ID 等)
     * @param  string     $channel 日志通道,默认 'stack'
     * @param  string     $level   日志级别(debug/info/notice/warning/error/critical),默认 'error'
     */
    public static function record(
        Throwable $e,
        array $context = [],
        string $channel = 'stack',
        string $level = 'error'
    ): void {
        $payload = array_merge([
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => collect($e->getTrace())->take(15)->all(),
        ], $context);

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
}
