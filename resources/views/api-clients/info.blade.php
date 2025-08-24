@extends('layouts.admin')

@section('title', 'API 客户端详情')

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- 客户端基本信息 -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ $client->name }}</h3>
                    <div class="card-tools">
                        <a href="{{ wzRoute('admin:api-clients') }}" class="btn btn-default">
                            <i class="fas fa-arrow-left"></i> 返回列表
                        </a>
                    </div>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible">
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                            {{ session('success') }}
                        </div>
                    @endif

                    @if(session('new_secret'))
                        <div class="alert alert-warning alert-dismissible">
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                            <h5><i class="icon fas fa-exclamation-triangle"></i> 新的客户端密钥</h5>
                            <strong>Client Secret:</strong> <code>{{ session('new_secret') }}</code><br>
                            <small class="text-muted">请立即复制保存，密钥不会再次显示。</small>
                        </div>
                    @endif

                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-bordered">
                                <tr>
                                    <th width="30%">客户端 ID</th>
                                    <td><code>{{ $client->id }}</code></td>
                                </tr>
                                <tr>
                                    <th>客户端名称</th>
                                    <td>{{ $client->name }}</td>
                                </tr>
                                <tr>
                                    <th>授权类型</th>
                                    <td>
                                        @if($client->password_client)
                                            <span class="badge badge-primary">密码授权</span>
                                        @elseif($client->personal_access_client)
                                            <span class="badge badge-info">个人访问令牌</span>
                                        @else
                                            <span class="badge badge-success">授权码</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>回调地址</th>
                                    <td><small class="text-muted">{{ $client->redirect ?: '无' }}</small></td>
                                </tr>
                                <tr>
                                    <th>状态</th>
                                    <td>
                                        @if($client->revoked)
                                            <span class="badge badge-danger">已撤销</span>
                                        @else
                                            <span class="badge badge-success">活跃</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>创建时间</th>
                                    <td>{{ $client->created_at->format('Y-m-d H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-6">
                            <div class="card card-outline card-info">
                                <div class="card-header">
                                    <h3 class="card-title">令牌统计</h3>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div class="description-block border-right">
                                                <span class="description-percentage text-success">
                                                    <i class="fas fa-check-circle"></i>
                                                </span>
                                                <h5 class="description-header">{{ $tokenStats['active_tokens'] }}</h5>
                                                <span class="description-text">活跃令牌</span>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="description-block border-right">
                                                <span class="description-percentage text-warning">
                                                    <i class="fas fa-exclamation-circle"></i>
                                                </span>
                                                <h5 class="description-header">{{ $tokenStats['revoked_tokens'] }}</h5>
                                                <span class="description-text">已撤销</span>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="description-block">
                                                <span class="description-percentage text-info">
                                                    <i class="fas fa-info-circle"></i>
                                                </span>
                                                <h5 class="description-header">{{ $tokenStats['total_tokens'] }}</h5>
                                                <span class="description-text">总计</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="btn-group">
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#editClientModal">
                                    <i class="fas fa-edit"></i> 编辑客户端
                                </button>
                                <button type="button" class="btn btn-warning" onclick="regenerateSecret()">
                                    <i class="fas fa-key"></i> 重新生成密钥
                                </button>
                                <button type="button" class="btn btn-danger" onclick="revokeAllTokens()">
                                    <i class="fas fa-ban"></i> 撤销所有令牌
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 最近令牌活动 -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">最近令牌活动</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>令牌 ID</th>
                                    <th>用户</th>
                                    <th>作用域</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>过期时间</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentTokens as $token)
                                    <tr>
                                        <td><code>{{ substr($token->id, 0, 8) }}...</code></td>
                                        <td>{{ $token->user->name ?? '系统' }}</td>
                                        <td>
                                            @if(empty($token->scopes))
                                                <span class="badge badge-secondary">无限制</span>
                                            @else
                                                @foreach($token->scopes as $scope)
                                                    <span class="badge badge-primary">{{ $scope }}</span>
                                                @endforeach
                                            @endif
                                        </td>
                                        <td>
                                            @if($token->revoked)
                                                <span class="badge badge-danger">已撤销</span>
                                            @else
                                                <span class="badge badge-success">活跃</span>
                                            @endif
                                        </td>
                                        <td>{{ $token->created_at->format('m-d H:i') }}</td>
                                        <td>{{ $token->expires_at ? $token->expires_at->format('m-d H:i') : '永不过期' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">暂无令牌活动记录</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 编辑客户端模态框 -->
<div class="modal fade" id="editClientModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="{{ wzRoute('admin:api-clients:edit', $client->id) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">编辑客户端</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_name">客户端名称 *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" 
                               value="{{ $client->name }}" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_redirect">回调地址</label>
                        <input type="url" class="form-control" id="edit_redirect" name="redirect" 
                               value="{{ $client->redirect }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存更改</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('script')
<script>
function regenerateSecret() {
    layer.confirm('重新生成密钥将导致使用旧密钥的应用无法正常工作，确定要继续吗？', function(){
        $.ajax(
            {
                url: `{{ wzRoute('admin:api-clients:regenerate-secret', $client->id) }}`,
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    layer.closeAll();
                    layer.msg(
                        '新的客户端密钥已生成：<br><code>' + response.new_secret + '</code><br><small class="text-muted">请立即复制保存，密钥不会再次显示。</small>',
                        {time: 0, btn: ['关闭']},
                    );
                },
                error: function(xhr) {
                    alert('请求失败，请稍后重试。');
                }
            }
        )
    });
}

function revokeAllTokens() {
    if (confirm('确定要撤销此客户端的所有访问令牌吗？此操作不可撤销。')) {
        $.ajax(
            {
                url: `{{ wzRoute('admin:api-clients:tokens:revoke', $client->id) }}`,
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    _method: 'DELETE'
                },
                success: function(response) {
                    alert(response.message?response.message:'已成功撤销所有令牌。');
                    location.reload();
                },
                error: function(xhr) {
                    alert('请求失败，请稍后重试。');
                }
            }
        )
    }
}
</script>
@endpush
