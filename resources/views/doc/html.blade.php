@extends('layouts.default')
@section('title', $project->name)
@section('container-style', 'container-fluid')
@section('content')

    <div class="row marketing wz-main-container-full">
        <form class="w-100" method="POST" id="wz-doc-edit-form"
              action="{{ $newPage ? wzRoute('project:doc:new:show', ['id' => $project->id]) : wzRoute('project:doc:edit:show', ['id' => $project->id, 'page_id' => $pageItem->id]) }}">

            @include('components.doc-edit', ['project' => $project, 'pageItem' => $pageItem ?? null, 'navigator' => $navigator])
            <div class="row">
                <input type="hidden" name="type" value="html"/>
                <div class="col" style="padding-left: 0; padding-right: 0;">
                    <div id="editormd">
			{!! $pageItem->content ?? '' !!}
                    </div>
                    <textarea name="content" id="content" style="display:none;"></textarea>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('stylesheet')
    <link href="{{ cdn_resource('/assets/vendor/editor-md/css/editormd.min.css') }}" rel="stylesheet"/>
@endpush

@push('script')
    <script type="text/javascript" charset="utf-8" src="{{ cdn_resource('/assets/vendor/wangEditor.min.js') }}?{{ resourceVersion() }}"></script>
    <script type="text/javascript">
        $(function () {
    const E = window.wangEditor
    const editor = new E('#editormd')
    const $text1 = $('#content')
    editor.config.onchange = function (html) {
        $text1.val(html)
    }
    editor.create()

    $text1.val(editor.txt.html())
            $.global.markdownEditor = editor;

            $.global.getEditorContent = function () {
                try {
                    return editor.txt.html();
                } catch (e) {
                }

                return '';
            };

            $.global.getDraftKey = function () {
                return 'html-editor-content-{{ $project->id ?? '' }}-{{ $pageItem->id ?? '' }}';
            };

            $.global.updateEditorContent = function (content) {
                editor.txt.html(content)
            };

        });
    </script>
@endpush

@section('bootstrap-material-init')
    <!-- 没办法，material-design与editor-md的js冲突，导致editor-md无法自动滚动 -->
@endsection