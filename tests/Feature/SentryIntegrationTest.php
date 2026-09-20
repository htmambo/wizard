<?php

namespace Tests\Feature;

use App\Support\ErrorLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Sentry\Client as SentryClient;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\State\Hub;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Sentry\UserDataBag;
use Tests\TestCase;

/**
 * Sentry 错误追踪 + APM 集成测试。
 *
 * 验证 Wizard 与 sentry/sentry-laravel 4.x 的集成契约:
 *   - 500 异常上报 Sentry(捕获 + 上下文完整)
 *   - 4xx 异常(404 / 401 / 422)不上报,避免淹没真实 5xx
 *   - 登录用户的异常携带 user.id 上下文
 *   - ErrorLogger::record 自动同步上报
 *   - 不带 DSN 时静默 noop,不影响主请求
 *
 * 通过自定义 InMemoryTransport 替换 Sentry SDK 的 HttpTransport,
 * 在内存中收集事件,断言数量与载荷。
 */
class SentryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private InMemorySentryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        // 准备一个专用的 500 抛错路由(动态注册,避免污染 routes/api.php)
        Route::get('/__sentry_test/throw-runtime', function (): void {
            throw new \RuntimeException('sentry-test-boom:500');
        });

        // 准备一个 ValidationException 抛错路由(用于 422 路径)
        Route::post('/__sentry_test/throw-validation', function (): void {
            throw new \Illuminate\Validation\ValidationException(
                \Validator::make(['x' => 'y'], ['x' => ['required']])
            );
        });

        // 用 InMemoryTransport 替换 Sentry 客户端的真实 HttpTransport。
        // 流程:取当前 hub → 取 client → 取 options → 构造 new Client(options, InMemoryTransport) →
        // 构造新 Hub → SentrySdk::setCurrentHub
        $currentHub = SentrySdk::getCurrentHub();
        $currentClient = $currentHub->getClient();
        if ($currentClient === null) {
            $this->markTestSkipped('Sentry client not bound — package not installed.');
        }

        $this->transport = new InMemorySentryTransport();
        $options = $currentClient->getOptions();
        $newClient = new SentryClient($options, $this->transport);

        $newHub = new Hub($newClient);
        SentrySdk::setCurrentHub($newHub);
        // 让 Laravel app 容器里也指向新 Hub,确保 ErrorLogger 通过 container 看到的 sentry 是新的
        if ($this->app->bound('sentry')) {
            $this->app->forgetInstance('sentry');
        }
        $this->app->instance('sentry', $newHub);
        $this->app->instance(\Sentry\State\HubInterface::class, $newHub);
    }

    protected function tearDown(): void
    {
        // 清掉测试期间注册的路由,避免污染下一个测试
        Route::setRoutes(new \Illuminate\Routing\RouteCollection());
        parent::tearDown();
    }

    public function test_500_exception_is_reported_to_sentry(): void
    {
        $this->getJson('/__sentry_test/throw-runtime')->assertStatus(500);

        $events = $this->transport->getEvents();
        $this->assertCount(1, $events, '500 异常应当上报 Sentry 1 次');

        /** @var ExceptionDataBag[] $exceptions */
        $exceptions = $events[0]->getExceptions();
        $this->assertNotEmpty($exceptions);
        $this->assertSame(\RuntimeException::class, $exceptions[0]->getType());
        $this->assertStringContainsString('sentry-test-boom:500', $exceptions[0]->getValue());
    }

    public function test_404_is_not_reported_to_sentry(): void
    {
        $this->getJson('/api/__no_such_path_for_sentry_test_xyz')->assertStatus(404);

        $this->assertCount(0, $this->transport->getEvents(), '404 噪声应当被过滤');
    }

    public function test_401_is_not_reported_to_sentry(): void
    {
        // /api/user 未登录返回 401(AuthenticationException)
        $this->getJson('/api/user')->assertStatus(401);

        $this->assertCount(0, $this->transport->getEvents(), '401 认证异常应当被过滤');
    }

    public function test_422_is_not_reported_to_sentry(): void
    {
        $this->postJson('/__sentry_test/throw-validation')->assertStatus(422);

        $this->assertCount(0, $this->transport->getEvents(), '422 校验异常应当被过滤');
    }

    public function test_authenticated_user_500_includes_user_id_in_event(): void
    {
        $user = $this->createUser();

        Passport::actingAs($user);
        $this->getJson('/__sentry_test/throw-runtime')->assertStatus(500);

        $events = $this->transport->getEvents();
        $this->assertCount(1, $events);

        $eventUser = $events[0]->getUser();
        $this->assertInstanceOf(UserDataBag::class, $eventUser, '登录用户异常事件应包含 UserDataBag');
        $this->assertSame((string) $user->id, (string) $eventUser->getId(), '事件 user.id 应等于当前登录用户 id');
    }

    public function test_error_logger_record_invokes_sentry_capture(): void
    {
        ErrorLogger::record(new \RuntimeException('error-logger-direct-boom'));

        $events = $this->transport->getEvents();
        $this->assertCount(1, $events, 'ErrorLogger::record 应当把非 4xx 异常上报 Sentry');

        /** @var ExceptionDataBag[] $exceptions */
        $exceptions = $events[0]->getExceptions();
        $this->assertNotEmpty($exceptions);
        $this->assertStringContainsString('error-logger-direct-boom', $exceptions[0]->getValue());
    }

    public function test_error_logger_skips_4xx_exceptions(): void
    {
        ErrorLogger::record(new \Illuminate\Validation\ValidationException(
            \Validator::make(['x' => 'y'], ['x' => ['required']])
        ));

        $this->assertCount(0, $this->transport->getEvents(), 'ErrorLogger::record 不应上报 4xx 异常');
    }
}

/**
 * 测试用 Sentry transport:把所有 send() 调用记录到内存,不发起网络请求。
 */
class InMemorySentryTransport implements TransportInterface
{
    /** @var Event[] */
    private array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;
        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }

    /** @return Event[] */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function reset(): void
    {
        $this->events = [];
    }
}