@extends('layouts.blog')

@section('title', '果农笔记 - 博客首页')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <!-- 主内容区域 -->
            <div class="col-md-8 col-lg-9">
                <!-- 目录导航 -->
                @if($catalogs->count() > 0)
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

                <!-- 项目列表 -->
                <div class="row">
                    @forelse($projects as $project)
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100">
                                @if($project->visibility == \App\Repositories\Project::VISIBILITY_PRIVATE)
                                    <div class="card-header">
                                        <small class="text-muted">
                                            <i class="fas fa-lock"></i> 私有项目
                                        </small>
                                    </div>
                                @endif
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <a href="{{ wzRoute('project:home', $project->id) }}" class="text-decoration-none">
                                            {{ $project->name }}
                                        </a>
                                    </h5>
                                    @if($project->description)
                                        <p class="card-text text-muted small">{{ $project->description }}</p>
                                    @endif

                                    <!-- 博客文章列表 -->
                                    @if($project->pages->count() > 0)
                                        <div class="list-group list-group-flush">
                                            @foreach($project->pages->take(3) as $page)
                                                <div class="list-group-item px-0 py-2 border-0">
                                                    <h6 class="mb-1">
                                                        <a href="{{ wzRoute('blog:post', [$project->id, $page->id]) }}"
                                                           class="text-decoration-none">
                                                            {{ $page->title }}
                                                        </a>
                                                    </h6>
                                                    <small class="text-muted">
                                                        {{ $page->updated_at->diffForHumans() }}
                                                    </small>
                                                </div>
                                            @endforeach
                                            @if($project->pages->count() > 3)
                                                <div class="list-group-item px-0 py-2 border-0">
                                                    <small>
                                                        <a href="{{ wzRoute('project:home', $project->id) }}">
                                                            查看更多 ({{ $project->pages->count() - 3 }} 篇)
                                                        </a>
                                                    </small>
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <p class="card-text text-muted">暂无博客文章</p>
                                    @endif
                                </div>
                                <div class="card-footer text-muted small">
                                    <i class="fas fa-file-alt"></i> {{ $project->pages->count() }} 篇文章
                                    @if($project->catalog)
                                        · <i class="fas fa-folder"></i> {{ $project->catalog->name }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-12">
                            <div class="alert alert-info text-center">
                                <h4>暂无项目</h4>
                                <p class="mb-0">当前目录下还没有任何项目，或者您没有权限查看。</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                <!-- 分页 -->
                <div class="d-flex justify-content-center">
                    {{ $projects->appends(request()->query())->links() }}
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
                                        {{ $post->title }}
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
                @if($popularTags->count() > 0)
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-tags"></i> 热门标签
                            </h5>
                        </div>
                        <div class="card-body">
                            @foreach($popularTags as $tag)
                                <span class="badge badge-secondary mr-2 mb-2">{{ $tag->name }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection