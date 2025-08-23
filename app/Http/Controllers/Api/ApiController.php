<?php
/**
 * 基础接口
 */

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Dedoc\Scramble\Attributes\Group;
use App\Repositories\ApiToken;
use App\Repositories\Document;
use App\Repositories\Tag;
use App\Repositories\PageTag;


#[Group('基础接口', 'API相关接口', 1)]
class ApiController extends Controller
{

    public function tags(Request $request)
    {
        // 使用 LEFT JOIN 和 COUNT 合并查询，避免 N+1 查询问题
        $tags = Tag::query()
            ->select('id', 'name')
            ->get()
            ->toArray();
        return response()->json($tags);
    }

    /**
     * 获取API Token
     *
     * @unauthenticated
     * @requestMediaType multipart/form-data
     * @requestMediaType application/json
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Random\RandomException
     */
    public function token(Request $request){
        //懒得去加载其它第三方包了，就简单的实现个access_token和refresh_token算了
        // 基础验证规则
        $this->validate($request, [
            // 授权类型
            'grant_type'   => 'required|string|in:password,refresh_token',
            // 客户端ID
            'client_id'    => 'required|string',
            // 客户端密钥
            'client_secret' => 'required|string',
            // 刷新令牌
            'refresh_token' => 'required_if:grant_type,refresh_token|string',
            // 用户名
            'username' => 'required_if:grant_type,password|string',
            // 密码
            'password' => 'required_if:grant_type,password|string',
        ]);

        // 根据grant_type添加条件验证规则
        $grantType = $request->get('grant_type');

        $clientId = $request->get('client_id', '');
        $clientSecret = $request->get('client_secret', '');
        $grandtType = $request->get('grant_type', 'password');
        $username = $request->get('username', '');
        $password = $request->get('password', '');
        $refreshToken = $request->get('refresh_token', '');
        if ($grandtType === 'refresh_token') {
            // 刷新token - 使用refresh_token字段进行验证
            $apiToken = ApiToken::where('refresh_token', $refreshToken)->first();
            if (!$apiToken) {
                return $this->error('Invalid refresh token', 401);
            }
            if ($apiToken->refresh_expires_at && $apiToken->refresh_expires_at < now()) {
                return $this->error('Refresh token expired', 401);
            }
            // 直接设置用户，无需重新验证密码
            $user = $apiToken->user;
            Auth::setUser($user);
        } else {
            // 验证用户凭据
            if (!Auth::attempt(['email' => $username, 'password' => $password])) {
                return $this->error('Invalid username or password', 401);
            }
        }
        $user = Auth::user();
        if (!$user) {
            return $this->error('Unauthorized', 401);
        }

        // 检查用户是否已存在token
        $apiToken = ApiToken::where('user_id', $user->id)->first();
        if (!$apiToken || $apiToken->expires_at < now()) {
            // 创建新的token
            if (!$apiToken) {
                $apiToken = new ApiToken();
                $apiToken->user_id = $user->id;
            }

            // 生成access_token：用户ID + 时间戳 + 随机值的哈希
            $accessTokenString = $user->id . '_access_' . $user->email . '_' . time() . '_' . bin2hex(random_bytes(16));
            $apiToken->token = hash('sha256', $accessTokenString);
            $apiToken->expires_at = now()->addHours(2); // access_token 2小时过期

            // 生成refresh_token：用户ID + 时间戳 + 随机值的哈希
            $refreshTokenString = $user->id . '_refresh_' . $user->email . '_' . time() . '_' . bin2hex(random_bytes(16));
            $apiToken->refresh_token = hash('sha256', $refreshTokenString);
            $apiToken->refresh_expires_at = now()->addDays(30); // refresh_token 30天过期

            $apiToken->save();
        }

        return response()->json([
            'access_token' => $apiToken->token,
            'token_type'  => 'Bearer',
            'expires_in' => -$apiToken->expires_at->diffInSeconds(now()),
            'refresh_token' => $apiToken->refresh_token,
        ]);
    }

    /**
     * 获取版本信息
     * @unauthenticated
     */
    public function version(Request $request){
        return $this->success([
            'version' => config('wizard.version', '1.0.0'),
            'api_version' => config('wizard.api_version', '1.0.0'),
            'name'    => config('app.name', 'Wizard'),
            'env'     => config('app.env', 'production'),
            'debug'   => config('app.debug', false),
            'url'     => config('app.url', 'https://wzard.com'),
            'timezone' => config('app.timezone', 'UTC'),
            'PHP_VERSION' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ]);
    }

    /**
     * 搜索功能
     */
    public function Search(Request $request){
        $query = $request->get('q', '');
        $type  = $request->get('type', 'all'); // all, project, document
        $limit = min($request->get('limit', 20), 100);

        if (empty($query)) {
            return $this->error('Search query is required', 400);
        }

        try {
            $results = [];
            if ($type === 'all' || $type === 'document') {
                $documents = Document::where('title', 'like', "%{$query}%")
                                     ->orWhere('content', 'like', "%{$query}%")
                                     ->limit($limit)
                                     ->get(['id', 'title', 'project_id', 'created_at']);

                $results['documents'] = $documents;
            }
            return $this->success($results, 'Search results', [
                'query' => $query,
                'type'  => $type,
                'limit' => $limit,
            ]);
        } catch (\Exception $e) {
            return $this->error(config('app.debug')?$e->getMessage():'Search failed', 500);
        }
    }
}