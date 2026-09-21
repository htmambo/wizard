@extends('layouts.user')

@section('title', __('common.user_info'))
@section('breadcrumb')
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ wzRoute('user:home') }}">@lang('common.home')</a></li>
        <li class="breadcrumb-item">个人中心</li>
        <li class="breadcrumb-item active">@lang('common.user_info')</li>
    </ol>
@endsection
@section('user-content')
    <div class="card card-white">
        <div class="card-body">
            <form class="form-horizontal" method="post" action="{{ wzRoute('user:basic:handle') }}">
                {{ csrf_field() }}
                <div class="mb-3">
                    <label for="editor-email" class="bmd-label-floating">@lang('common.email')</label>
                    <input type="text" class="form-control" value="{{ $user->email }}" id="editor-email"
                           name="email" readonly>
                </div>
                <div class="mb-3">
                    <label for="editor-username" class="bmd-label-floating">@lang('common.username')</label>
                    <input type="text" class="form-control" value="{{ $user->name }}" id="editor-username"
                           name="username">
                </div>
                <div class="mb-3">
                    <div>
                        <button type="submit"
                                class="btn btn-success btn-raised">@lang('common.btn_save')</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-white">
        <div class="card-body">
            <h4 class="card-title">两步验证（TOTP）</h4>
            @if (is_null($user->totp_enabled_at))
                <p class="text-muted">
                    当前账号未启用两步验证。建议启用,以提升账号安全（启用后登录需输入验证器中的 6 位数字）。
                </p>
                <a class="btn btn-primary btn-raised"
                   href="{{ wzRoute('auth.2fa.enable.show') }}">启用两步验证</a>
            @else
                <p class="text-muted">
                    已在 {{ $user->totp_enabled_at->format('Y-m-d H:i:s') }} 启用两步验证。
                </p>
                <form method="post" action="{{ wzRoute('auth.2fa.disable') }}"
                      onsubmit="return confirm('确定要禁用两步验证吗？禁用后只需账号密码即可登录。');">
                    {{ csrf_field() }}
                    <button type="submit" class="btn btn-warning btn-raised">禁用两步验证</button>
                </form>
            @endif

            @if (session()->has('2fa_backup_codes'))
                <hr>
                <div class="alert alert-warning">
                    <strong>请妥善保存以下备用码（每个仅可使用一次）:</strong>
                    <ul class="mt-2 mb-0">
                        @foreach (session('2fa_backup_codes') as $code)
                            <li><code>{{ $code }}</code></li>
                        @endforeach
                    </ul>
                    <small class="text-muted d-block mt-2">
                        备用码用于无法使用验证器时登录。建议打印或抄写在安全的地方。
                        本提示仅显示一次,刷新后将不可见。
                    </small>
                </div>
            @endif
        </div>
    </div>
@endsection