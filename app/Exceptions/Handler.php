<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
    ];

    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthenticated',
                'message' => 'Authentication required'
            ], 401);
        }

        return redirect()->guest(wzRoute('login'));
    }

    /**
     * AD2:渲染 TokenExpiredException。
     *
     * Web 请求 → redirect 到登录页 + flash 友好提示
     * API 请求 → 401 JSON
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Exceptions\TokenExpiredException  $exception
     */
    protected function renderTokenExpired($request, TokenExpiredException $exception)
    {
        // 用 expectsJson() 精确判断,覆盖 XHR/Ajax + Accept: application/json + api 路径
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error'   => 'token_expired',
                'message' => $exception->getMessage(),
            ], 401);
        }

        return redirect()
            ->guest(wzRoute('login'))
            ->withErrors(['token' => $exception->getMessage()]);
    }

    /**
     * Register the exception handling callbacks for the application.
     *
     * AD2:把 TokenExpiredException 渲染到自定义方法。
     *
     * AD-X:api/* 路径下未捕获异常统一返回
     *      {success:false, message:'Internal Server Error', request_id}
     *      跳过 ValidationException(交给 Laravel 默认 422 渲染,保证 FormRequest 不变)。
     */
    public function register(): void
    {
        $this->renderable(function (TokenExpiredException $e, $request) {
            return $this->renderTokenExpired($request, $e);
        });

        // Sentry 上报钩子:对未预期的 5xx 异常显式上报。
        // sentry-laravel 已通过 reporter() 钩子自动接管,但本闭包提供额外保障:
        //   - 显式过滤 4xx 噪声(与 config/sentry.php::before_send 共享 SentryEventFilter)
        //   - 即使 SDK 因 DSN 缺失被禁用,本闭包也安全 noop(仅做过滤)
        //   - 注入登录用户的 user.id 到 Sentry scope,便于在控制台按用户追踪
        $this->reportable(function (\Throwable $e): bool {
            try {
                if (!function_exists('\\Sentry\\captureException')) {
                    return true;
                }
                $app = function_exists('app') ? \app() : null;
                if ($app === null || !$app->bound('sentry')) {
                    return true;
                }
                foreach (\App\Support\SentryEventFilter::ignoredClasses() as $class) {
                    if ($e instanceof $class) {
                        return true;
                    }
                }

                // 注入用户上下文到当前 scope(登录用户的 user.id 索引)
                try {
                    $authId = \Auth::id();
                    if ($authId !== null && $authId !== '') {
                        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($authId): void {
                            $userData = ['id' => (string) $authId];
                            try {
                                $email = \Auth::user()?->email ?? \Auth::user()?->mail;
                                if (is_string($email) && $email !== '') {
                                    $userData['email'] = $email;
                                }
                                $username = \Auth::user()?->username ?? \Auth::user()?->name;
                                if (is_string($username) && $username !== '') {
                                    $userData['username'] = $username;
                                }
                            } catch (\Throwable $ignored) {
                            }
                            $scope->setUser($userData);
                        });
                    }
                } catch (\Throwable $ignored) {
                    // Auth facade 未就绪时跳过
                }

                \Sentry\captureException($e);
            } catch (\Throwable $sentryFailure) {
                // Sentry 异常不能阻塞 Laravel 自有的 report 流,放行
            }
            return true;
        });

        $this->renderable(function (\Throwable $e, $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            // 保留 Laravel/项目已有的特殊渲染:Validation 422 / TokenExpired 401 / Auth 401 等
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }
            if ($e instanceof \App\Exceptions\TokenExpiredException) {
                return null;
            }
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return null;
            }
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return null;
            }
            if ($e instanceof \Illuminate\Session\TokenMismatchException) {
                return null;
            }
            // HttpResponseException 用于中间件携带预构建的 Response(例如
            // ThrottleRequests 的 429 responseCallback)。框架默认 render()
            // 会调用 $e->getResponse(),但本闭包先匹配 Throwable,会拦截它。
            // 透传:返回内嵌的 Response。
            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return $e->getResponse();
            }

            $requestId = $request->attributes->get('request_id');
            if (!is_string($requestId) || $requestId === '') {
                $requestId = '-';
            }

            $status = 500;
            $message = 'Internal Server Error';

            return response()->json([
                'success'    => false,
                'message'    => $message,
                'request_id' => $requestId,
            ], $status);
        });
    }
}
