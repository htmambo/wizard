<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Http\Controllers\AccessTokenController as PassportAccessTokenController;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Laravel\Passport\TokenRepository;
use Nyholm\Psr7\Factory\Psr17Factory;

class OAuthController extends Controller
{
    /**
     * 获取 OAuth Token
     *
     * @unauthenticated
     * @requestMediaType multipart/form-data
     *
     * @param Request $request
     *
     * @return JsonResponse
     * @throws ValidationException
     */
    public function token(Request $request)
    {
        // 验证请求参数
        $this->validate($request, [
            'grant_type' => 'required|in:password,refresh_token,client_credentials',
            'client_id' => 'required',
            'client_secret' => 'required',
            'username' => 'required_if:grant_type,password',
            'password' => 'required_if:grant_type,password',
            'refresh_token' => 'required_if:grant_type,refresh_token',
        ]);

        try {
            // 将 Laravel Request 转为 PSR-7 请求
            $psr17Factory = new Psr17Factory();
            $psrHttpFactory = new \Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory(
                $psr17Factory,
                $psr17Factory,
                $psr17Factory,
                $psr17Factory
            );
            $psrRequest = $psrHttpFactory->createRequest($request);

            // 创建 PSR-7 响应对象
            $psrResponse = $psr17Factory->createResponse();

            // 使用 Laravel Passport 的内置控制器处理 OAuth 请求
            $controller = new PassportAccessTokenController(
                app(AuthorizationServer::class),
                app(TokenRepository::class)
            );

            $response = $controller->issueToken($psrRequest, $psrResponse);

            return response()->json(json_decode((string) $response->getBody(), true));

        } catch (\Exception $e) {
            \App\Support\ErrorLogger::record($e, ['context' => 'OAuthController']);
            return response()->json([
                                        'error' => 'invalid_request',
                                        'error_description' => $e->getMessage(),
                                    ], 400);
        }
    }

    /**
     * 获取当前用户信息
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function user(Request $request)
    {
        return response()->json([
                                    'user' => $request->user(),
                                ]);
    }

    /**
     * 撤销令牌
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function revoke(Request $request)
    {
        $request->user()->token()->revoke();

        return response()->json([
                                    'message' => 'Token has been revoked successfully',
                                ]);
    }
}