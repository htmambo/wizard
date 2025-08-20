@push('stylesheet')
    <link rel="stylesheet" href="{{ cdn_resource('/assets/vendor/luckysheet/plugins/css/pluginsCss.css') }}">
    <link rel="stylesheet" href="{{ cdn_resource('/assets/vendor/luckysheet/plugins/plugins.css') }}">
    <link rel="stylesheet" href="{{ cdn_resource('/assets/vendor/luckysheet/css/luckysheet.css') }}">
    <link rel="stylesheet" href="{{ cdn_resource('/assets/vendor/luckysheet/assets/iconfont/iconfont.css') }}">
    <style>
        #luckysheet-icon-font-size {display:inline;}
    </style>
@endpush

@push('script')
    <script src="{{ cdn_resource('/assets/vendor/luckysheet/plugins/js/plugin.js') }}"></script>
    <script src="{{ cdn_resource('/assets/vendor/luckysheet/luckysheet.umd.js') }}"></script>
    <script>
        $(function () {
            var savedContent = $('#x-spreadsheet-content').val();
            if (savedContent === '') {
                savedContent = "{}";
            }
            // var savedContent = $('#xspreadsheet-content').html();
            $('#x-spreadsheet').height(document.documentElement.clientHeight - $('#x-spreadsheet').offset().top - $('.footer').height() - 45);
            $('#x-spreadsheet').width($('#x-spreadsheet').width() - 20);
            var options = {
                container: 'x-spreadsheet', // 设定DOM容器的id
                showinfobar: false,
                showtoolbar: false,
                showtoolbarConfig: false,
                showstatisticBar: false,
                allowEditStatus: false,
                allowCopy: false,
                allowUpdate: false,
                sheetFormulaBar: false,
                lang: 'zh',
                mode: 'read',
                row: '{{ config("wizard.spreedsheet.max_rows") }}',
                column: '{{ config("wizard.spreedsheet.max_cols") }}',
                allowEdit: false,
                allowEditFormula: false,
                enableAddRow: false,
                enableAddBackTop: false,
                enableAddSheet: false,
            };
            if(savedContent) {
                var data = JSON.parse(savedContent);
                if(data.hasOwnProperty('data')) options.data = data.data;
            }
            luckysheet.create(options);

        });
    </script>
@endpush