@extends('layouts.default')
@section('title', $project->name)
@section('container-style', 'container-fluid')
@section('content')

    <div class="marketing wz-main-container-full">
        <form class="w-100" method="POST" id="wz-doc-edit-form"
              action="{{ $newPage ? wzRoute('project:doc:new:show', ['id' => $project->id]) : wzRoute('project:doc:edit:show', ['id' => $project->id, 'page_id' => $pageItem->id]) }}">

            @include('components.doc-edit', ['project' => $project, 'pageItem' => $pageItem ?? null, 'navigator' => $navigator])
            <div class="">
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
    <link href="{{ cdn_resource('/assets/vendor/editor-md/css/editormd.css') }}" rel="stylesheet"/>
    <style>
        #editormd {border: none;}
    </style>
@endpush

@push('script')
    <script type="text/javascript" charset="utf-8" src="{{ cdn_resource('/assets/vendor/wangEditor.min.js') }}?{{ resourceVersion() }}"></script>
    <script type="text/javascript" charset="utf-8" src="{{ cdn_resource('/assets/js/wangEditor.ext.js') }}?{{ resourceVersion() }}"></script>
    <script type="text/javascript" charset="utf-8" src="{{ cdn_resource('/assets/vendor/beautify/beautify.js') }}?{{ resourceVersion() }}"></script>
    <script type="text/javascript" charset="utf-8" src="{{ cdn_resource('/assets/vendor/beautify/beautify-css.js') }}?{{ resourceVersion() }}"></script>
    <script type="text/javascript" charset="utf-8" src="{{ cdn_resource('/assets/vendor/beautify/beautify-html.js') }}?{{ resourceVersion() }}"></script>
    <script type="text/javascript">
        $(function () {
            const E = window.wangEditor;

            const editor = new E('#editormd');

            const menuKey = 'SplitPageMenuKey'
            editor.menus.extend(menuKey, SplitPageMenu)
            editor.config.menus = editor.config.menus.concat(menuKey)


            const $text1 = $('#content');
            editor.config.onchange = function (html) {
                $text1.val(html_beautify(html));
            }
            // 编辑器工具栏高度：42，底部：35
            editor.config.height = window.innerHeight - 77;
            editor.config.uploadImgServer = '/upload';
            editor.config.uploadFileName = 'editormd-image-file';
            editor.config.uploadImgMaxLength = 1;
            editor.config.uploadImgParams = {
                from: 'wangEditor'
            };
            editor.create();
            if(typeof currentTheme == 'undefined') {
                var currentTheme = store.get('wizard-theme');
                if (currentTheme === undefined) {
                    currentTheme = '{{ config('wizard.theme') }}';
                }
            }
            if (currentTheme === 'dark') {
                $('#editormd').children().css({
                    'background-color':'#000',
                    'color':'#fff',
                    'border-color':'#5c5c5c'
                });
            }

            $text1.val(editor.txt.html());
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