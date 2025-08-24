@extends('layouts.admin')

@section('title', 'API 客户端管理')

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title">API 客户端管理</h3>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#createClientModal">
                        <i class="fas fa-plus"></i> 创建新客户端
                    </button>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible">
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                            {{ session('success') }}
                        </div>
                    @endif

                    @if(session('client_credentials'))
                        <div class="alert alert-warning alert-dismissible">
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                            <h5><i class="icon fas fa-exclamation-triangle"></i> 重要！请妥善保存以下信息：</h5>
                            <strong>Client ID:</strong> {{ session('client_credentials.client_id') }}<br>
                            <strong>Client Secret:</strong> <code>{{ session('client_credentials.client_secret') }}</code><br>
                            <small class="text-muted">客户端密钥只会显示一次，请立即复制保存。</small>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>名称</th>
                                    <th>类型</th>
                                    <th>回调地址</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($clients as $client)
                                    <tr>
                                        <td>{{ $client->id }}</td>
                                        <td>{{ $client->name }}</td>
                                        <td>
                                            @if($client->password_client)
                                                <span class="badge badge-primary">密码授权</span>
                                            @elseif($client->personal_access_client)
                                                <span class="badge badge-info">个人访问令牌</span>
                                            @else
                                                <span class="badge badge-success">授权码</span>
                                            @endif
                                        </td>
                                        <td>
                                            <small class="text-muted">{{ $client->redirect }}</small>
                                        </td>
                                        <td>
                                            @if($client->revoked)
                                                <span class="badge badge-danger">已撤销</span>
                                            @else
                                                <span class="badge badge-success">活跃</span>
                                            @endif
                                        </td>
                                        <td>{{ $client->created_at->format('Y-m-d H:i') }}</td>
                                        <td>
                                            <div class="btn-group-smxs" role="group">
                                                <a href="{{ wzRoute('admin:api-clients:view', $client->id) }}"
                                                   class="btn btn-sm btn-info">
                                                    <i class="fas fa-eye"></i> 查看
                                                </a>
                                                <button type="button" class="btn btn-sm btn-danger" 
                                                        onclick="deleteClient('{{ $client->id }}')">
                                                    <i class="fas fa-trash"></i> 删除
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">
                                            暂无 API 客户端，点击上方按钮创建您的第一个客户端。
                                        </td>
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

<!-- 创建客户端模态框 -->
<div class="modal fade" id="createClientModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="{{ wzRoute('admin:api-clients:add') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">创建 API 客户端</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="name">客户端名称 *</label>
                        <input type="text" class="form-control" id="name" name="name" 
                               placeholder="例如：我的移动应用" required>
                    </div>
                    <div class="form-group">
                        <label for="type">授权类型 *</label>
                        <select class="form-control" id="type" name="type" required>
                            <option value="">请选择授权类型</option>
                            <option value="password">密码授权 (适用于第一方应用)</option>
                            <option value="personal">个人访问令牌</option>
                            <option value="authorization_code">授权码 (适用于第三方应用)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="redirect">回调地址</label>
                        <input type="url" class="form-control" id="redirect" name="redirect" 
                               placeholder="http://localhost:3000/callback">
                        <small class="form-text text-muted">授权码类型需要指定回调地址</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">创建客户端</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('script')
<script>
function deleteClient(clientId) {
    if (confirm('确定要删除这个 API 客户端吗？删除后将无法恢复，且所有相关的访问令牌都会被撤销。')) {
        $.ajax(
            {
                url: `{{ wzRoute('admin:api-clients:delete', 'clientId') }}`.replace('clientId', clientId),
                type: 'DELETE',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    location.reload();
                },
                error: function(xhr) {
                    alert('删除失败，请稍后重试。');
                }
            }
        )
    }
}
</script>
@endpush
