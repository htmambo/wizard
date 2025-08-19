@extends('layouts.default')
@section('title', $project->name)
@section('container-style', 'container-fluid')
@section('content')

    <div class="row marketing wz-main-container-full">
        <form style="width: 100%;" method="POST" id="wz-doc-edit-form"
              action="{{ $newPage ? wzRoute('project:doc:new:show', ['id' => $project->id]) : wzRoute('project:doc:edit:show', ['id' => $project->id, 'page_id' => $pageItem->id]) }}">

            @include('components.doc-edit', ['project' => $project, 'pageItem' => $pageItem ?? null, 'navigator' => $navigator])
            <input type="hidden" name="type" value="table" />

            <div id="xspreadsheet-content" style="display: none;">{{ base64_encode(processSpreedSheet($pageItem->content ?? '')) }}</div>
            <div class="col-row" id="x-spreadsheet"></div>
        </form>
    </div>
@endsection

@push('bottom')

@endpush

@push('stylesheet')
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/plugins/css/pluginsCss.css' />
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/plugins/plugins.css' />
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/css/luckysheet.css' />
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/assets/iconfont/iconfont.css' />
    <style>
        #luckysheet-icon-font-size {display:inline;}
    </style>
    <script src="https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/plugins/js/plugin.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/luckysheet.umd.js"></script>
@endpush

@push('script')
    <script src="{{ cdn_resource('/assets/vendor/base64.min.js') }}"></script>

    <script>
        $(function() {

            var savedContent = $('#xspreadsheet-content').html();
            if (savedContent === '') {
                savedContent = "{}";
            } else {
                savedContent = Base64.decode(savedContent);
            }
            $('#x-spreadsheet').height(document.documentElement.clientHeight - $('#x-spreadsheet').offset().top - $('.footer').height() - 45);
            $('#x-spreadsheet').width(document.documentElement.clientWidth - 20);
            var options = {
                container: 'x-spreadsheet', // 设定DOM容器的id
                showinfobar: false,
                lang: 'zh',
                mode: 'edit',
                row: '{{ config("wizard.spreedsheet.max_rows") }}',
                column: '{{ config("wizard.spreedsheet.max_cols") }}',
                enableAddRow: false,
                enableAddBackTop: false,
                enableAddSheet: false,
            };
            if(savedContent) {
                var data = JSON.parse(savedContent);
                if(data.hasOwnProperty('data')) options.data = data.data;
            }
            luckysheet.create(options);

            // 获取编辑器中的内容
            $.global.getEditorContent = function () {
                return JSON.stringify(luckysheet.toJson());
            };

            // 获取swagger编辑器本次存储内容的key
            $.global.getDraftKey = function() {
                return 'x-spreadsheet-content-{{ $project->id ?? '' }}-{{ $pageItem->id ?? '' }}';
            };

            // 更新编辑器内容
            $.global.updateEditorContent = function (content) {
                var data = JSON.parse(content);
                if(data) {
                    window['tableData'] = data;
                    luckysheet.create(data);
                }
            };

        });
    </script>
@endpush
