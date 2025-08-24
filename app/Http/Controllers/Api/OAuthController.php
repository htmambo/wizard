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
            // 获取 PSR-7 请求和响应对象
            $serverRequest = app()->make(ServerRequestInterface::class);

            // 创建 PSR-7 响应对象
            $psr17Factory = new Psr17Factory();
            $psrResponse = $psr17Factory->createResponse();

            // 使用 Laravel Passport 的内置控制器处理 OAuth 请求
            $controller = new PassportAccessTokenController(
                app(AuthorizationServer::class),
                app(TokenRepository::class)
            );

            $response = $controller->issueToken($serverRequest, $psrResponse);

            return response()->json(json_decode($response->getContent(), true));

        } catch (\Exception $e) {
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