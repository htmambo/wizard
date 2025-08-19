<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Parameter;
use App\Repositories\ApiToken;
use App\Repositories\Document;

#[Group('基础接口', 'API相关接口', 1)]
class ApiController extends Controller
{

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
        $this->validate(
            $request, [
                // 账号或邮箱
                'username'     => 'required|string',
                // 密码
                'password'     => 'required|string',
                // 授权类型
                'grant_type'   => 'required|string|in:password',
                // 客户端ID
                'client_id'    => 'required|string',
                // 客户端密钥
                'client_secret' => 'required|string',
            ]
        );
        $username = $request->get('username', '');
        $password = $request->get('password', '');
        $grandtType = $request->get('grant_type', 'password');
        $clientId = $request->get('client_id', '');
        $clientSecret = $request->get('client_secret', '');
        if ($grandtType !== 'password') {
            return $this->error('Unsupported grant type', 400);
        }
        if (empty($username) || empty($password)) {
            return $this->error('Username and password are required', 400);
        }
        if (empty($clientSecret)) {
            return $this->error('Client secret is required', 400);
        }
        // 验证客户端ID和密钥
        if ($clientId !== config('wizard.client_id', 'default_client') || $clientSecret !== config('wizard.client_secret', 'default_secret')) {
            // return $this->error('Invalid client credentials', 401);
        }
        // 验证用户凭据
        if (!\Auth::attempt(['email' => $username, 'password' => $password])) {
            return $this->error('Invalid username or password', 401);
        }
        $user = \Auth::user();
        if (!$user) {
            return $this->error('Unauthorized', 401);
        }
        // 检查用户是否已存在token
        $apiToken = ApiToken::where('user_id', $user->id)->first();
        if (!$apiToken) {
            // 创建新的token
            $apiToken = new ApiToken();
            $apiToken->user_id = $user->id;
            $apiToken->token = bin2hex(random_bytes(40)); // 生成新的token
        }
        $apiToken->expires_at = now()->addHour();
        $apiToken->save();
        // 返回token信息
        return $this->success([
            'token' => $apiToken->token,
            'user'  => [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email
            ],
            'expires_in' => 3600, // 1小时有效期
        ]);
    }

    /**
     * 获取版本信息
     * @unauthenticated
     */
    public function version(Request $request){
        // 返回应用的版本信息
        return $this->success([
                                    'version'         => config('wizard.version', '1.0.0'),
                                    'app_name'        => config('app.name', '果农笔记'),
                                    'laravel_version' => app()->version(),
                                    'timestamp'       => now()->toISOString(),
                                    'environment'     => app()->environment(),
                                    'php_version'     => phpversion(),
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