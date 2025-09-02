<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', '果农笔记')</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <!-- Custom styles for this template -->
    <link href="/assets/css/style.css?{{ resourceVersion() }}" rel="stylesheet">
    @stack('stylesheet')
    @stack('style')

</head>
<body>
<!-- 导航栏 -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="{{ wzRoute('blog:home') }}">
            <i class="fas fa-seedling"></i> 果农笔记
        </a>

        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mr-auto">
                <li class="nav-item">
                    <a class="nav-link" href="{{ wzRoute('blog:home') }}">
                        <i class="fas fa-home"></i> 首页
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ wzRoute('home') }}">
                        <i class="fas fa-book"></i> 文档系统
                    </a>
                </li>
            </ul>

            <ul class="navbar-nav">
                @guest
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('login') }}">
                            <i class="fas fa-sign-in-alt"></i> 登录
                        </a>
                    </li>
                    @if(register_enabled())
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('register') }}">
                                <i class="fas fa-user-plus"></i> 注册
                            </a>
                        </li>
                    @endif
                @else
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-toggle="dropdown">
                            <i class="fas fa-user"></i> {{ Auth::user()->name }}
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="{{ wzRoute('user:home') }}">
                                <i class="fas fa-home"></i> 个人首页
                            </a>
                            <a class="dropdown-item" href="{{ wzRoute('user:basic') }}">
                                <i class="fas fa-cog"></i> 账号设置
                            </a>
                            @if(Auth::user()->isAdmin())
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="{{ wzRoute('admin:dashboard') }}">
                                    <i class="fas fa-tachometer-alt"></i> 管理后台
                                </a>
                            @endif
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="{{ route('logout') }}"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                <i class="fas fa-sign-out-alt"></i> 退出登录
                            </a>
                            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                @csrf
                            </form>
                        </div>
                    </li>
                @endguest
            </ul>
        </div>
    </div>
</nav>

<!-- 主内容区域 -->
<main class="py-4">
    @yield('content')
</main>

<!-- 页脚 -->
<footer class="bg-light py-4 mt-5">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12 text-center">
                <p class="text-muted mb-0">
                    &copy; {{ date('Y') }} 果农笔记 - 让知识分享更简单
                </p>
            </div>
        </div>
    </div>
</footer>

<!-- jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>

@stack('script')
</body>
</html>