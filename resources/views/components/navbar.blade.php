
@foreach(navigatorSort($navbars, $project->catalog_sort_style) as $nav)
    <li class="{{ $nav['selected'] ? 'active' : '' }} {{ !empty($nav['nodes']) ? 'wz-has-child' : '' }}" data-type="{{ $nav['type'] }}">
        <a href="{{ $nav['url'] }}" title="{{ $nav['name'] }} {{ $nav['updated_at'] }}" class="wz-nav-item">
            @if($nav['status'] == \App\Repositories\Document::STATUS_OUTDATED)
                <del class="doc-outdated">{{ $nav['name'] }}</del>
            @else
                {{ $nav['name'] }}
            @endif
        </a>

        @if(!empty($nav['nodes']))
            <ul>
                @include('components.navbar', ['navbars' => $nav['nodes'], 'project' => $project])
            </ul>
        @endif
    </li>
@endforeach