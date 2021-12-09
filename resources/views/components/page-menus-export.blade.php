<li role="presentation" class="mr-2">
    <button type="button" data-toggle="modal" data-target="#wz-export" title="导出文件" class="btn btn-primary bmd-btn-icon" id="wz-export-trigger">
        <span class="fa fa-download" data-toggle="tooltip" title="导出为"></span>
    </button>
</li>

@push('bottom')
    <div class="modal fade" id="wz-export" tabindex="-1" role="dialog" aria-labelledby="wz-export">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">导出为</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    @if($pageItem->type == \App\Repositories\Document::TYPE_DOC || $pageItem->type == \App\Repositories\Document::TYPE_HTML)
                        <a href="#" class="dropdown-item wz-export-pdf" data-scope="">
                            <span class="fa fa-download mr-2"></span>
                            PDF
                        </a>
                        <a href="#" class="dropdown-item wz-export-pdf" data-scope="full">
                            <span class="fa fa-download mr-2"></span>
                            PDF (完全渲染)
                        </a>
                        <a href="#" class="dropdown-item wz-export-markdown">
                            <span class="fa fa-download mr-2"></span>
                            Markdown
                        </a>
                        <br />
                        <div class="bmd-label-floating">
                            <ol>
                                <li>完全渲染会将文档中的代码段渲染为图片，如果代码段较多，受系统限制可能会导出失败。</li>
                                <li>渲染后可能会因为分页的原因丢失内容，请手动在合适的位置添加“分页符”。</li>
                            </ol>
                        </div>
                    @endif

                    @if($pageItem->type == \App\Repositories\Document::TYPE_SWAGGER)
                    <a href="#" class="dropdown-item wz-export-swagger"
                       data-data-url="{!! wzRoute('swagger:doc:json', ['id' => $project->id, 'page_id' => $pageItem->id, 'ts' => microtime(true)])  !!}"
                       data-download-url="{!! wzRoute('export:download', ['filename' => "{$pageItem->title}.json"]) !!}">
                        <span class="fa fa-download mr-2"></span>
                        JSON
                    </a>
                    <a href="#" class="dropdown-item wz-export-swagger"
                       data-data-url="{!! wzRoute('swagger:doc:yml', ['id' => $project->id, 'page_id' => $pageItem->id, 'ts' => microtime(true)])  !!}"
                       data-download-url="{!! wzRoute('export:download', ['filename' => "{$pageItem->title}.yml"]) !!}">
                        <span class="fa fa-download mr-2"></span>
                        YAML
                    </a>
                    @endif

                </div>
            </div>
        </div>
    </div>
@endpush

@push('script')
<script src="/assets/vendor/html2canvas.min.js"></script>
<script>
    $(function () {

    @if($pageItem->type == \App\Repositories\Document::TYPE_DOC || $pageItem->type == \App\Repositories\Document::TYPE_HTML)
        // PDF 导出
        $('.wz-export-pdf').on('click', function (e) {
            e.preventDefault();
            var scope = $(this).attr('data-scope');
            var convertToImg = '.editormd-tex, .flowchart, .sequence-diagram, .mermaid';
            if(scope=='full') convertToImg += ', pre.prettyprint';
            var sec = 0;
            var maxlen = {!! intval(ini_get('pcre.backtrack_limit')) !!};
            var total = $(convertToImg).length;
            var index = layer.msg('预处理中...', {
                icon: 16, shade: 0.01, time: 30000
            });

            setTimeout(function() {
                // 检查是否是黑暗模式，黑暗模式需要先切换为正常模式
                // 解决黑暗模式下公式为白色的问题
                var body = $('body');
                var isDarkTheme = false;
                if (body.hasClass('wz-dark-theme')) {
                    body.removeClass('wz-dark-theme');
                    isDarkTheme = true;
                }
                var origHtml = $('#markdown-body').html();
                Promise.all($('#markdown-body').find(convertToImg).map(function() {
                    var self = $(this);
                    return html2canvas(self[0]).then(function(canvas) {
                        var image = new Image();
                        image.src = canvas.toDataURL("image/png");
                        self.replaceWith(image);
                        sec++;
                        $('.layui-layer-content').text('预处理中' + sec + '/' + total + '...');
                    });
                })).then(function() {
                    layer.close(index);
                    if (isDarkTheme) {
                        body.addClass('wz-dark-theme');
                        if(typeof($.global.markdownEditor)==='object' && $.global.markdownEditor!==null) {
                            $.global.markdownEditor.setPreviewTheme('dark');
                            $.global.markdownEditor.setEditorTheme('paraiso-dark');
                            $.global.markdownEditor.setTheme('dark');
                        }
                    }

                    layer.msg('努力渲染中...', {
                        icon: 16, shade: 0.01, time: 50000
                    });
                    var contentBody = $('#markdown-body').clone();
                    contentBody.find('textarea').remove();
                    contentBody.find('.bmd-form-group').remove();
                    //移除可能配置了的目录
                    if(contentBody.find('.editormd-toc-menu').length) {
                        contentBody.find('.toc-menu-btn').remove();
                        contentBody.find('.markdown-toc-list').show();
                        contentBody.find('.markdown-toc-list').find('h1').show();
                    } else if(contentBody.find('.markdown-toc-list').length) {
                        contentBody.find('.markdown-toc-list').prepend('<li><h1>目录</h1></li>')
                    }

                    //移除可能存在的SQL_CREATE的多余部分
                    if($('.wz-sql-create').length) {
                        contentBody.find('nav').remove();
                        contentBody.find('.tab-pane.show').removeClass('tab-pane');
                        contentBody.find('.tab-pane').remove();
                    }
                    //标签
                    var tags = [];
                    $('.tm-tag').each(function(a,b){tags.push($(b).text());})
                    if(tags.length) {
                        tags = '<span>' + tags.join(',') + '</span>';
                        contentBody.prepend(tags);
                    }
                    //标题
                    var title = $('h1.wz-page-title').clone();
                    contentBody.prepend(title);
                    if(maxlen && contentBody.html().length>maxlen) {
                        //太长，不允许
                        $.wz.alert('渲染后内容太长了(当前渲染内容长度：'+contentBody.html().length+'，最大支持长度：'+maxlen+')，暂不支持导出', function(){$('#markdown-body').html(origHtml);});
                    } else {
                        $.wz.dynamicFormSubmit(
                            'generate-pdf-{{ $pageItem->id }}',
                            'POST',
                            '{{ wzRoute('export:pdf', ['type' => documentType($pageItem->type)]) }}',
                            {
                                "html": contentBody.html(),
                                "title": "{{ $pageItem->title }}"
                            }
                        );
                    }
                });
            }, 100);
        });

        // 普通导出
        $('.wz-export-markdown').on('click', function (e) {
            e.preventDefault();

            $.wz.dynamicFormSubmit(
                'generate-markdown-{{ $pageItem->id }}',
                'POST',
                '{{ wzRoute('export:download', ['filename' => "{$pageItem->title}.md"]) }}',
                {
                    content: $('.wz-markdown-content').val(),
                }
        )
        });
    @endif

    @if($pageItem->type == \App\Repositories\Document::TYPE_SWAGGER)
        $('.wz-export-swagger').on('click', function (e) {
            e.preventDefault();

            var data_url = $(this).data('data-url');
            var download_url = $(this).data('download-url');

            $.get(data_url, {}, function (data) {
                $.wz.dynamicFormSubmit(
                    'generate-swagger-{{ $pageItem->id }}',
                    'POST',
                    download_url,
                    {
                        content: data,
                    }
                );
            }, 'text');
        });
    @endif
    });
</script>
@endpush
