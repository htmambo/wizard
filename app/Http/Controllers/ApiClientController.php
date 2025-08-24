<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\TokenRepository;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use phpseclib3\Exception\UnsupportedOperationException;

class ApiClientController extends Controller
{
    protected $clientRepository;
    protected $tokenRepository;

    public function __construct(ClientRepository $clientRepository, TokenRepository $tokenRepository)
    {
        $this->clientRepository = $clientRepository;
        $this->tokenRepository = $tokenRepository;
    }

    /**
     * 显示 API 客户端列表
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function clients()
    {
        try {
            $clients = Client::orderBy('created_at', 'desc')->get();
            $op = 'clients';
            return view('api-clients.index', compact('op', 'clients'));
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Laravel Passport 未正确配置：' . $e->getMessage()]);
        }
    }

    /**
     * 创建新的 API 客户端
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function add(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:password,personal,authorization_code',
            'redirect' => 'nullable|url',
        ]);

        try {
            // 检查 ClientRepository 是否正确初始化
            if (!$this->clientRepository) {
                throw new \Exception('ClientRepository 未正确初始化，请检查 Laravel Passport 配置');
            }

            switch ($request->input('type')) {
                case 'password':
                    $client = $this->clientRepository->createPasswordGrantClient(
                        $request->input('name'),
                        'users',
                        false
                    );
                    break;

                case 'personal':
                    throw new UnsupportedOperationException('不支持 Personal Access Client 的创建，请手动创建。');
                    break;

                case 'authorization_code':
                    throw new UnsupportedOperationException('不支持 Authorization Code Client 的创建，请手动创建。');
                    break;
            }

            return redirect()->route('admin:api-clients')
                ->with('success', 'API 客户端创建成功！')
                ->with('client_credentials', [
                    'client_id' => $client->id,
                    'client_secret' => $client->secret,
                ]);

        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['error' => '创建客户端失败：' . $e->getMessage()]);
        }
    }

    /**
     * 显示客户端详情
     *
     * @param string $uuid
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function info($uuid)
    {
        /**
         * @var Client $client
         */
        $client = Client::findOrFail($uuid);

        // 安全地获取令牌统计
        $tokenStats = [
            'active_tokens' => 0,
            'total_tokens' => 0,
            'revoked_tokens' => 0,
        ];

        $recentTokens = collect([]);

        // 检查是否存在令牌关系
        if (method_exists($client, 'tokens')) {
            $tokenStats = [
                'active_tokens' => $client->tokens()->where('revoked', false)->count(),
                'total_tokens' => $client->tokens()->count(),
                'revoked_tokens' => $client->tokens()->where('revoked', true)->count(),
            ];

            // 获取最近的令牌活动
            $recentTokens = $client->tokens()->with('client')
                // ->with('user') // 预加载用户关系，避免 N+1 查询
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
            }
            $op = 'clients';
            return view('api-clients.info', compact('op', 'client', 'tokenStats', 'recentTokens'));
    }

    /**
     * 编辑客户端
     *
     * @param Request $request
     * @param string $uuid
     * @return \Illuminate\Http\RedirectResponse
     */
    public function edit(Request $request, $uuid)
    {
        $client = Client::findOrFail($uuid);

        $request->validate([
            'name' => 'required|string|max:255',
            'redirect' => 'nullable|url',
        ]);

        try {
            $data = [
                'name' => $request->input('name'),
            ];
            $url = $request->input('redirect');
            if(!empty($url)){
                $data['redirect_urls'] = [$url];
            }
            $client->update($data);
            return redirect()->route('api-clients:view', $uuid)
                ->with('success', '客户端信息更新成功！');

        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['error' => '更新失败：' . $e->getMessage()]);
        }
    }

    /**
     * 删除客户端
     *
     * @param string $uuid
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete($uuid)
    {
        $client = Client::findOrFail($uuid);

        // 撤销所有相关的令牌
        $this->revokeAllTokens($client);

        // 删除客户端
        $result = $client->delete();
        if ($result) {
            return response()->json(['message' => '客户端删除成功！']);
        } else {
            return response()->json(['error' => '删除失败，请稍后重试。'], 500);
        }
    }

    /**
     * 撤销客户端的所有访问令牌
     *
     * @param string $uuid
     * @return \Illuminate\Http\JsonResponse
     */
    public function revokeTokens($uuid)
    {
        $client = Client::findOrFail($uuid);
        $revokedCount = $this->revokeAllTokens($client);
        return response()->json([
            'message' => "已撤销 {$revokedCount} 个访问令牌。",
        ]);
    }

    /**
     * 重新生成客户端密钥
     *
     * @param string $uuid
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerateSecret($uuid)
    {
        $client = Client::findOrFail($uuid);
        $client->secret = $newSecret = Str::random(40);
        $result = $client->save();
        if($result){
            return response()->json([
                'message' => '客户端密钥已重新生成！',
                'new_secret' => $newSecret,
            ]);
        } else {
            return response()->json(['error' => '重新生成密钥失败，请稍后重试。'], 500);
        }
    }

    /**
     * 撤销客户端的所有令牌
     *
     * @param Client $client
     * @return int
     */
    private function revokeAllTokens(Client $client): int
    {
        $result = 0;
        if (method_exists($client, 'tokens')) {
            // $tokens = $client->tokens()->where('revoked', false)->get();
            // $result = $tokens->count();
            // $result = $client->tokens()->update(['revoked' => true]);
            $result = $client->tokens()->delete();
        }
        return $result;
    }
}