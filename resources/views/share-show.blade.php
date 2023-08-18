@extends("layouts.single")
@section('title', $pageItem->title)
@section('page-content')
<nav class="wz-page-control clearfix">
    <h1 class="wz-page-title">
        {{ $pageItem->title }}
    </h1>
    @if(Auth::user())
    <a href="{{ wzRoute('project:home', ['id' => $project->id, 'p' => $pageItem->id]) }}"
       title="返回"
       class="btn btn-link float-right print-hide"><i class="fa fa-close"></i></a>
    <hr />
    @endif
</nav>
<div class="markdown-body" id="markdown-body">
    @if($type == 'html')
        {{ print_r($pageItem->content) }}
    @endif
    @if($type == 'markdown')
{{--        @if($pageItem->html_code && config('wizard.markdown.direct_save_html'))--}}
{{--            {!! $pageItem->html_code !!}--}}
{{--        @else--}}
            <textarea id="append-test" style="display:none;">{{ $pageItem->content }}</textarea>
{{--        @endif--}}
    @endif
    @if($type == 'table')
        <textarea id="x-spreadsheet-content" class="d-none">{{ processSpreedSheet($pageItem->content) }}</textarea>
        <div id="x-spreadsheet"></div>
    @endif
</div>

<div class="text-center wz-panel-limit mt-3">~ END ~</div>
@endsection

@includeIf("components.{$type}-show")

@push('script-pre')
    <script>
        @if($type == 'table')
        $(function () {
            $('.wz-body').css('max-width', '100%');
            $('.wz-panel-limit').css('max-width', '100%');
        });
        @endif

        // 自动检查文档是否过期
        (function () {
            var lastModifiedAt = '{{ $pageItem->updated_at }}';
            var checkExpiredURL = '{{ wzRoute('share:expired', ['hash' => $hash]) }}';
            var continueCheck = function () {
                window.document_check_task = window.setTimeout(function () {
                    $.wz.request('get', checkExpiredURL, {l: lastModifiedAt}, function (data) {
                        // 没有过期则继续检查
                        if (!data.expired) {
                            continueCheck();
                            return false;
                        }
                        $('body').append('<div class="alert alert-danger" role="alert" id="wz-error-box" style="display: none;position:fixed;top:0;width:100%;"></div>');
                        $('#wz-error-box').fadeIn('fast').html(data.message);
                    }, continueCheck);
                }, 60000);

                return true;
            };

            continueCheck();
        })();
    </script>
@endpush
