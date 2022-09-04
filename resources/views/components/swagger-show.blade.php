@push('stylesheet')
<link href="{{ cdn_resource('/assets/vendor/swagger-ui/swagger-ui.css') }}" rel="stylesheet">
@endpush

@push('script')
<script src="{{ cdn_resource('/assets/vendor/swagger-ui/swagger-ui-bundle.js') }}"></script>
<script src="{{ cdn_resource('/assets/vendor/swagger-ui/swagger-ui-standalone-preset.js') }}"></script>
<script>
    $(function () {
        window.ui = SwaggerUIBundle({
            dom_id: '#markdown-body',
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIStandalonePreset
            ],
            plugins: [
                SwaggerUIBundle.plugins.DownloadUrl
            ],
            onrender: function(a,b,c) {console.log(a,b,c);},
            validatorUrl: "",
            layout: "BaseLayout",
            @if(isset($isHistoryPage) && $isHistoryPage)
            url: "{!! wzRoute('project:doc:history:json', ['code' => $code ?? '', 'id' => $project->id, 'page_id' => $pageItem->id, 'history_id' => $history->id, 'only_body' => 1, 'ts' => microtime(true)]) !!}"
            @else
            url: "{!! wzRoute('project:doc:json', ['highlight' => $highlight ?? '', 'code' => $code ?? '','id' => $project->id, 'page_id' => $pageItem->id, 'only_body' => 1, 'ts' => microtime(true)])  !!}"
            @endif
        });

        window.setTimeout(function () {
            $.wz.imageClick('#markdown-body');
            // $('.swagger-ui section.models h4').trigger('click');
            var highlightStr = {!! json_encode(explode(',', $highlight)) !!};
            var html = $('#markdown-body').html();
            $('#markdown-body').html($.wz.highlight(html, highlightStr));
        }, 3000);
    });
</script>
@endpush