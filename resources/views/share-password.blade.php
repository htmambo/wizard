@extends("layouts.single")
@section('title', $pageItem->title)
@section('page-content')
<nav class="wz-page-control clearfix">
    <h1 class="wz-page-title">
        {{ $pageItem->title }}
    </h1>
    <a href="{{ wzRoute('project:home', ['id' => $project->id, 'p' => $pageItem->id]) }}"
       title="返回"
       class="btn btn-link float-right print-hide"><i class="material-icons">clear</i></a>
    <hr />
</nav>
<div class="markdown-body" id="markdown-body">

    <form class="form-signin" method="POST" action="">
        {{--<img class="mb-4" src="/assets/wizard.svg" alt="" height="100">--}}
        <h1 class="h3 mb-3 font-weight-normal">请输入访问密码</h1>

        {{ csrf_field() }}

        <div class="text-left form-group">
            <label for="password" class="bmd-label-floating">@lang('common.password')</label>
            <input id="password" type="password" class="form-control" name="password" required>
        </div>

        <button type="submit" class="btn btn-lg btn-primary btn-block btn-raised">提交</button>
    </form>


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
    </script>
@endpush
