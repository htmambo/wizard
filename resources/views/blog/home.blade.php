@extends('layouts.blog')

@section('title', '果农笔记 - 博客首页')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <!-- 主内容区域 -->
            <div class="col-md-8 col-lg-9">
                <!-- 目录导航 -->
                @if($catalogs && $catalogs->count() > 0)
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-folder"></i> 项目目录
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="btn-group-toggle" data-toggle="buttons">
                                <label class="btn btn-outline-primary {{ empty($catalog) ? 'active' : '' }}">
                                    <a href="{{ wzRoute('blog:home') }}" class="text-decoration-none">全部</a>
                                </label>
                                @foreach($catalogs as $cat)
                                    <label class="btn btn-outline-primary {{ $catalog == $cat->id ? 'active' : '' }}">
                                        <a href="{{ wzRoute('blog:home', $cat->id) }}" class="text-decoration-none">{{ $cat->name }}</a>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                <!-- 当前目录信息 -->
                @if($currentCatalog)
                    <div class="alert alert-info">
                        <h4><i class="fas fa-folder-open"></i> {{ $currentCatalog->name }}</h4>
                        @if($currentCatalog->description)
                            <p class="mb-0">{{ $currentCatalog->description }}</p>
                        @endif
                    </div>
                @endif

                <!-- 博客文章列表 -->
                <div class="row">
                    @forelse($pages as $page)
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <a href="{{ wzRoute('blog:post', ['project' => $page->project_id, 'id' => $page->id, 'alias' => $page->alias? ':' . trim($page->alias):null]) }}" class="text-decoration-none">
                                            {{ $page->title }}
                                        </a>
                                    </h5>
                                    @if($page->description)
                                        <p class="card-text text-muted">{{ Str::limit($page->description, 100) }}</p>
                                    @endif

                                    <!-- 标签 -->
                                    @if($page->tags->count() > 0)
                                        <div class="mb-2">
                                            @foreach($page->tags->take(3) as $tag)
                                                <span class="badge badge-secondary badge-sm mr-1">{{ $tag->name }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <div class="card-footer text-muted small">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>
                                            <i class="fas fa-user"></i> {{ $page->user->name ?? '匿名' }}
                                        </span>
                                        <span>
                                            <i class="fas fa-clock"></i> {{ $page->updated_at->diffForHumans() }}
                                        </span>
                                    </div>
                                    @if($page->project)
                                        <div class="mt-1">
                                            <i class="fas fa-folder"></i>
                                            <a href="{{ wzRoute('blog:home', $page->project_id) }}" class="text-muted">
                                                {{ $page->project->name }}
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-12">
                            <div class="alert alert-info text-center">
                                <h4>暂无博客文章</h4>
                                <p class="mb-0">当前没有任何博客文章，或者您没有权限查看。</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                <!-- 分页 -->
                <div class="d-flex justify-content-center">
                    {{ $pages->appends(request()->query())->links() }}
                </div>
            </div>

            <!-- 侧边栏 -->
            <div class="col-md-4 col-lg-3">
                <!-- 最新文章 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-clock"></i> 最新文章
                        </h5>
                    </div>
                    <div class="card-body">
                        @forelse($recentPosts as $post)
                            <div class="mb-3 pb-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                                <h6 class="mb-1">
                                    <a href="{{ wzRoute('blog:post', [$post->project_id, $post->id]) }}"
                                       class="text-decoration-none">
                                        {{ Str::limit($post->title, 40) }}
                                    </a>
                                </h6>
                                <small class="text-muted">
                                    {{ $post->project->name }} · {{ $post->updated_at->diffForHumans() }}
                                </small>
                            </div>
                        @empty
                            <p class="text-muted mb-0">暂无文章</p>
                        @endforelse
                    </div>
                </div>

                <!-- 热门标签 -->
                @if($popularTags && $popularTags->count() > 0)
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-tags"></i> 热门标签
                            </h5>
                        </div>
                        <div class="card-body">
                            @foreach($popularTags as $tag)
                                <a href="#" class="badge badge-secondary mr-2 mb-2 text-decoration-none">
                                    {{ $tag->name }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection