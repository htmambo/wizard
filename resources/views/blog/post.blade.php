@extends('layouts.blog')

@section('title', $post->title . ' - 果农笔记')

@section('content')
    <div class="container-fluid">
        <div class="row">            <!-- 主内容区域 -->
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
                <div class="wz-project-main card mb-4">
                    <div class="card-body markdown-body wz-panel-limit {{ $post->isMarkDown() ? 'wz-markdown-style-fix' : '' }}" id="markdown-body">
                        @if($post->isHtml())
                            {!! $post->content ?? '' !!}
                        @endif
                        @if($post->isMarkDown())
                            <textarea class="d-none wz-markdown-content">{{ str_replace('[SUB]', '<div class="wz-nav-container-in-doc"></div>', processMarkdown($post->content ?? '')) }}</textarea>
                        @endif
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
                            <a href="{{ wzRoute('blog:home', $post->project_id) }}" class="text-decoration-none">
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
@if($post->isMarkDown())
    @include("components.markdown-show")
@endif
@push('style')
    <style>
        #wz-toc-container > ul {
            display: block;
            /*background: #f0f0f0;*/
            padding: 0;
            margin: 0;
        }
        #wz-toc-container:hover > ul {
            margin-left: 0 !important;
        }
        #wz-toc-container ul li a {
            padding-left: 0 !important;
        }
        #wz-toc-container span {
            display: none;
        }
        #wz-toc-container,#wz-toc-container:hover {
            top: 20px;
            background: rgba(255, 255, 255, 0.9);
            padding: 15px;
            border: 1px solid #eaeaea;
            background: #fafafa;
            border-radius: 5px;
        }
    </style>
@endpush