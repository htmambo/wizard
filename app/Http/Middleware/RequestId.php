<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 请求追踪中间件:为每个请求生成(或沿用上游)唯一 X-Request-Id。
 *
 * 行为:
 *   - 读取 X-Request-Id 请求头;为空则生成 UUID v4
 *   - 写入 $request->attributes('request_id') 供业务与日志读取
 *   - 通过 Log::withContext() 注入到所有后续日志
 *   - 响应头回传 X-Request-Id,便于客户端排错
 *
 * 头名称遵循常见网关约定(DO / Heroku / Cloudflare 等),长度上限 128 防滥用。
 */
class RequestId
{
    public const HEADER     = 'X-Request-Id';
    public const ATTRIBUTE  = 'request_id';
    private const MAX_LEN   = 128;

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $requestId = $this->sanitize($incoming);

        $request->headers->set(self::HEADER, $requestId);
        $request->attributes->set(self::ATTRIBUTE, $requestId);

        Log::withContext([self::ATTRIBUTE => $requestId]);

        $response = $next($request);
        if ($response instanceof Response && isset($response->headers) && method_exists($response->headers, 'set')) {
            $response->headers->set(self::HEADER, $requestId);
        }

        return $response;
    }

    /**
     * 校验/清洗入参 ID。空字符串 / 超长 / 危险字符则重新生成 UUID v4。
     */
    private function sanitize(?string $value): string
    {
        if (is_string($value) && $value !== '' && strlen($value) <= self::MAX_LEN && preg_match('/^[A-Za-z0-9._\-]+$/', $value) === 1) {
            return $value;
        }

        return (string) Str::uuid();
    }
}
