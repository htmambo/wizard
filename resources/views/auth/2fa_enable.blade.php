@extends('layouts.user')

@section('title', '启用两步验证')
@section('breadcrumb')
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ wzRoute('user:home') }}">@lang('common.home')</a></li>
        <li class="breadcrumb-item"><a href="{{ wzRoute('user:basic') }}">@lang('common.user_info')</a></li>
        <li class="breadcrumb-item active">启用两步验证</li>
    </ol>
@endsection
@section('user-content')
    <div class="card card-white">
        <div class="card-body">
            <h4 class="card-title">启用两步验证（TOTP）</h4>
            <p class="text-muted">
                使用 Google Authenticator / Microsoft Authenticator / Authy 等 TOTP 应用扫描下方二维码,
                然后输入应用显示的 6 位数字完成启用。
            </p>

            <div class="form-group">
                <label>otpauth URI（可粘贴到任意支持 otpauth 的应用）</label>
                <input type="text" class="form-control" value="{{ $uri }}" readonly>
            </div>

            <div class="form-group">
                <label>或手动输入密钥</label>
                <input type="text" class="form-control" value="{{ $secret }}" readonly>
            </div>

            <form method="POST" action="{{ wzRoute('auth.2fa.enable') }}">
                {{ csrf_field() }}
                <div class="text-left form-group{{ $errors->has('code') ? ' has-error' : '' }}">
                    <label for="code" class="bmd-label-floating">验证器中的 6 位数字</label>
                    <input id="code" type="text" class="form-control" name="code" value="{{ old('code') }}"
                           required autofocus autocomplete="one-time-code" inputmode="numeric">
                    @if ($errors->has('code'))
                        <div class="invalid-feedback" style="display: block;">
                            {{ $errors->first('code') }}
                        </div>
                    @endif
                </div>

                <button type="submit" class="btn btn-success btn-raised">确认启用</button>
                <a class="btn btn-link" href="{{ wzRoute('user:basic') }}">取消</a>
            </form>
        </div>
    </div>
@endsection