@extends('layouts.login')
@section('title', '两步验证')
@section('content')
    <form class="form-signin" method="POST" action="{{ wzRoute('auth.2fa.verify') }}">
        <h1 class="h3 mb-3 font-weight-normal">两步验证</h1>
        <p class="text-muted small">已通过账号密码验证,请输入身份验证器中的 6 位数字,或备用码(格式 XXXX-XXXX)完成登录。</p>

        {{ csrf_field() }}

        <div class="text-left form-group{{ $errors->has('code') ? ' has-error' : '' }}">
            <label for="code" class="bmd-label-floating">验证码 / 备用码</label>
            <input id="code" type="text" class="form-control" name="code" value="{{ old('code') }}"
                   required autofocus autocomplete="one-time-code" inputmode="numeric">

            @if ($errors->has('code'))
                <div class="invalid-feedback" style="display: block;">
                    {{ $errors->first('code') }}
                </div>
            @endif
        </div>

        <button type="submit" class="btn btn-lg btn-primary btn-block btn-raised">
            验证并登录
        </button>

        <a class="btn btn-link" href="{{ wzRoute('login') }}">返回重新登录</a>

        <p class="mt-5 mb-3 text-muted">&copy; {{ date('Y') }} {{ config('wizard.copyright', 'IMZHP.COM') }}</p>
    </form>
@endsection