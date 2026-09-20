<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace Tests\Unit\Support;

use App\Http\Middleware\RequestId;
use App\Support\ErrorLogger;
use Illuminate\Http\Request;
use Illuminate\Log\Logger as LogWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * ErrorLogger 与 RequestId 中间件单元测试。
 */
class ErrorLoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 通过 Mockery 拦截 Log::channel('stack') 调用,捕获最终 payload。
     *
     * @param  array<string, mixed>  $captured  引用,接收捕获到的上下文
     */
    private function capturePayload(array &$captured): void
    {
        $captured = [];
        $writer = Mockery::mock(LogWriter::class);
        $writer->shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) use (&$captured) {
                $captured = $context;
                return true;
            });
        Log::shouldReceive('channel')->with('stack')->andReturn($writer);
    }

    public function test_record_captures_request_context(): void
    {
        $request = Request::create('http://example.com/api/foo/bar', 'POST');
        $request->headers->set('User-Agent', 'phpunit-agent/1.0');
        $request->headers->set('Referer', 'http://referer.test/page');
        $request->attributes->set(RequestId::ATTRIBUTE, 'rid-from-middleware');
        $request->server->set('REMOTE_ADDR', '203.0.113.42');
        app()->instance('request', $request);

        $captured = [];
        $this->capturePayload($captured);

        ErrorLogger::record(new RuntimeException('boom'));

        $this->assertSame('POST', $captured['http_method']);
        $this->assertSame('http://example.com/api/foo/bar', $captured['url']);
        $this->assertSame('phpunit-agent/1.0', $captured['user_agent']);
        $this->assertSame('http://referer.test/page', $captured['referer']);
        $this->assertSame('rid-from-middleware', $captured['request_id']);
        $this->assertSame('203.0.113.42', $captured['ip']);
        $this->assertSame('http', $captured['runtime']);
        $this->assertSame('RuntimeException', $captured['exception']);
        $this->assertSame('boom', $captured['message']);
        $this->assertIsArray($captured['call_stack']);
        $this->assertNotEmpty($captured['call_stack']);
    }

    public function test_record_with_empty_request_uses_safe_defaults(): void
    {
        // 绑定一个"裸" Request(无方法/无 IP/无 UA),验证所有可选字段
        // 都会退化为安全占位符 '-',而不是抛出或写入原始空字符串。
        $empty = Request::create('/', 'GET');
        $empty->server->remove('REMOTE_ADDR');
        app()->instance('request', $empty);

        $captured = [];
        $this->capturePayload($captured);

        ErrorLogger::record(new RuntimeException('boom-empty'));

        $this->assertSame('-', $captured['ip']);
        $this->assertSame('-', $captured['user_agent']);
        $this->assertSame('-', $captured['referer']);
        $this->assertSame('-', $captured['request_id']);
        $this->assertSame('-', $captured['route_name']);
        $this->assertSame('-', $captured['user_id']);
        $this->assertSame('boom-empty', $captured['message']);
        $this->assertArrayHasKey('http_method', $captured);
        $this->assertArrayHasKey('url', $captured);
    }

    public function test_request_id_middleware_assigns_and_echoes_id(): void
    {
        $middleware = new RequestId();

        // 入参带 X-Request-Id → 沿用,并写入响应头
        $request = Request::create('/api/anything', 'GET');
        $request->headers->set(RequestId::HEADER, 'client-supplied-123');
        $response = $middleware->handle($request, fn () => response('ok'));
        $this->assertSame('client-supplied-123', $request->attributes->get(RequestId::ATTRIBUTE));
        $this->assertSame('client-supplied-123', $response->headers->get(RequestId::HEADER));

        // 入参无 X-Request-Id → 生成 UUID v4,并写入响应头
        $request2 = Request::create('/api/anything', 'GET');
        $this->assertNull($request2->headers->get(RequestId::HEADER));
        $response2 = $middleware->handle($request2, fn () => response('ok'));
        $generated = $response2->headers->get(RequestId::HEADER);
        $this->assertNotEmpty($generated);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $generated,
            '生成的 request_id 必须是 UUID v4'
        );
        $this->assertSame($generated, $request2->attributes->get(RequestId::ATTRIBUTE));

        // 防止误用字符串拼接导致 UUID 退化
        $this->assertNotSame(Str::uuid()->toString() . Str::uuid()->toString(), $generated);
    }

    public function test_request_id_middleware_rejects_oversize_or_unsafe_value(): void
    {
        $middleware = new RequestId();

        $request = Request::create('/api/anything', 'GET');
        $request->headers->set(RequestId::HEADER, str_repeat('a', 200)); // 超长
        $response = $middleware->handle($request, fn () => response('ok'));
        $this->assertNotSame(str_repeat('a', 200), $response->headers->get(RequestId::HEADER));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $response->headers->get(RequestId::HEADER)
        );

        $request2 = Request::create('/api/anything', 'GET');
        $request2->headers->set(RequestId::HEADER, "abc\ndef"); // 包含换行,可能污染日志
        $response2 = $middleware->handle($request2, fn () => response('ok'));
        $this->assertNotSame("abc\ndef", $response2->headers->get(RequestId::HEADER));
    }
}
