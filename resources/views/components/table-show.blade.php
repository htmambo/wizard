@push('stylesheet')
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/plugins/css/pluginsCss.css' />
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/plugins/plugins.css' />
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/css/luckysheet.css' />
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/assets/iconfont/iconfont.css' />
    <style>
        #luckysheet-icon-font-size {display:inline;}
        .xluckysheet-wa-functionbox {
            display: none;
        }
        #xluckysheet-functionbox-container {
            left: 100px;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/plugins/js/plugin.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luckysheet@latest/dist/luckysheet.umd.js"></script>

@endpush

@push('script')
    <script src="{{ cdn_resource('/assets/vendor/x-spreadsheet/xspreadsheet.js') }}"></script>
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