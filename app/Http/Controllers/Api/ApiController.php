<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Parameter;
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
            ->select('tags.id', 'tags.name', DB::raw('COUNT(`wz_page_tag`.tag_id) as nb_entries'))
            ->leftJoin('page_tag', 'tags.id', '=', 'page_tag.tag_id')
            ->groupBy('tags.id', 'tags.name')
            ->get()
            ->map(function ($tag) {
                return [
                    'id'   => $tag->id,
                    'label' => $tag->name,
                    'slug'  => 't:' . urlencode($tag->name),
                    'nbEntries' => (int) $tag->nb_entries,
                ];
            })
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
        return response()->json([
            'access_token' => $apiToken->token,
            'type'  => 'Bearer',
            'expires_in' => 86400, // 1小时有效期
            'refresh_token' => $apiToken->token
        ]);
    }

    /**
     * 获取版本信息
     * @unauthenticated
     */
    public function version(Request $request){
        return response()->json(config('wizard.version', '1.0.0'));
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