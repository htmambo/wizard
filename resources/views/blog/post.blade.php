@extends('layouts.blog')

@section('title', $post->title . ' - 果农笔记')

@section('content')
    <div class="container-fluid">
        <div class="row">
            <!-- 主内容区域 -->
            <div class="col-md-8 col-lg-9">
                <!-- 文章头部信息 -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="mb-3">
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb bg-transparent p-0 mb-2">
                                    <li class="breadcrumb-item">
                                        <a href="{{ wzRoute('blog:home') }}">首页</a>
                                    </li>
                                    <li class="breadcrumb-item">
                                        <a href="{{ wzRoute('blog:home', $post->project_id) }}">{{ $post->project->name }}</a>
                                    </li>
                                    <li class="breadcrumb-item active">{{ $post->title }}</li>
                                </ol>
                            </nav>
                        </div>

                        <h1 class="display-4">{{ $post->title }}</h1>

                        <div class="text-muted mb-3">
                            <small>
                                <i class="fas fa-user"></i> {{ $post->user->name ?? $post->project->user->name }}
                                <i class="fas fa-calendar ml-3"></i> {{ $post->updated_at->format('Y-m-d H:i') }}
                                <i class="fas fa-eye ml-3"></i> {{ $post->view_count ?? 0 }} 次阅读
                            </small>
                        </div>

                        @if($tags->count() > 0)
                            <div class="mb-3">
                                @foreach($tags as $tag)
                                    <span class="badge badge-primary mr-2">{{ $tag->name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <!-- 文章内容 -->
                <div class="card mb-4">
                    <div class="card-body wz-markdown-body">
                        {!! $post->html_code !!}
                    </div>
                </div>

                <!-- 相关文章 -->
                @if($relatedPosts->count() > 0)
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-link"></i> 相关文章
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                @foreach($relatedPosts as $relatedPost)
                                    <div class="col-md-6 mb-3">
                                        <div class="card">
                                            <div class="card-body">
                                                <h6 class="card-title">
                                                    <a href="{{ wzRoute('blog:post', [$relatedPost->project_id, $relatedPost->id]) }}"
                                                       class="text-decoration-none">
                                                        {{ $relatedPost->title }}
                                                    </a>
                                                </h6>
                                                <small class="text-muted">
                                                    {{ $relatedPost->project->name }} · {{ $relatedPost->updated_at->diffForHumans() }}
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                <!-- 评论区 -->
                @if($post->comments->count() > 0)
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-comments"></i> 评论 ({{ $post->comments->count() }})
                            </h5>
                        </div>
                        <div class="card-body">
                            @foreach($post->comments as $comment)
                                <div class="media mb-3 {{ !$loop->last ? 'border-bottom pb-3' : '' }}">
                                    <div class="media-body">
                                        <h6 class="mt-0">{{ $comment->user->name }}</h6>
                                        <p>{{ $comment->content }}</p>
                                        <small class="text-muted">{{ $comment->created_at->diffForHumans() }}</small>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <!-- 侧边栏 -->
            <div class="col-md-4 col-lg-3">
                <!-- 项目信息 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-project-diagram"></i> 项目信息
                        </h5>
                    </div>
                    <div class="card-body">
                        <h6>
                            <a href="{{ wzRoute('project:home', $post->project_id) }}" class="text-decoration-none">
                                {{ $post->project->name }}
                            </a>
                        </h6>
                        @if($post->project->description)
                            <p class="text-muted small">{{ $post->project->description }}</p>
                        @endif
                        <small class="text-muted">
                            <i class="fas fa-user"></i> {{ $post->project->user->name }}
                        </small>
                    </div>
                </div>

                <!-- 同项目其他文章 -->
                @if($projectPosts->count() > 0)
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list"></i> 项目其他文章
                            </h5>
                        </div>
                        <div class="card-body">
                            @foreach($projectPosts as $projectPost)
                                <div class="mb-3 pb-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                                    <h6 class="mb-1">
                                        <a href="{{ wzRoute('blog:post', [$projectPost->project_id, $projectPost->id]) }}"
                                           class="text-decoration-none">
                                            {{ $projectPost->title }}
                                        </a>
                                    </h6>
                                    <small class="text-muted">
                                        {{ $projectPost->updated_at->diffForHumans() }}
                                    </small>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('style')
    <style>
        .wz-markdown-body {
            line-height: 1.6;
        }
        .wz-markdown-body h1, .wz-markdown-body h2, .wz-markdown-body h3,
        .wz-markdown-body h4, .wz-markdown-body h5, .wz-markdown-body h6 {
            margin-top: 1.5rem;
            margin-bottom: 1rem;
        }
        .wz-markdown-body pre {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 0.25rem;
            overflow-x: auto;
        }
        .wz-markdown-body blockquote {
            border-left: 4px solid #007bff;
            background-color: #f8f9fa;
            padding: 1rem;
            margin: 1rem 0;
        }
    </style>
@endpush