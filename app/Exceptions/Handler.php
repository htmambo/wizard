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
