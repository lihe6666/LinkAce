@extends('layouts.app')

@section('content')

    <div class="tag-merge-page">

        <header class="d-flex align-items-center">
            <h3 class="mb-0">
                @lang('tag.merge_similar')
            </h3>
            @if($groups->isNotEmpty())
                <form method="POST" action="{{ route('tags.merge.all') }}" class="ms-auto">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('{{ trans('tag.merge_all_confirm') }}')">
                        @lang('tag.merge_all')
                    </button>
                </form>
            @endif
        </header>

        @if($groups->isEmpty())

            <div class="alert alert-info mt-4">
                @lang('tag.no_similar_tags')
            </div>

        @else

            @foreach($groups as $group)
                <form method="POST" action="{{ route('tags.merge') }}" class="mb-4">
                    @csrf

                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <span class="badge bg-secondary me-2">
                                @lang('tag.similar_tag_count', ['count' => $group->count()])
                            </span>
                            <span class="text-pale small">@lang('tag.merge_hint')</span>
                        </div>
                        <div class="card-body p-0">
                            <table class="table mb-0">
                                <tbody>
                                @foreach($group as $tag)
                                    <tr>
                                        <td class="text-center" style="width: 40px;">
                                            <input type="radio" name="survivor" value="{{ $tag->id }}"
                                                class="form-check-input" @if($loop->first) checked @endif
                                                aria-label="@lang('tag.merge_keep', ['name' => $tag->name])">
                                        </td>
                                        <td>
                                            <a href="{{ route('tags.show', ['tag' => $tag]) }}" class="title">
                                                {{ $tag->name }}
                                            </a>
                                        </td>
                                        <td class="meta text-pale small text-condensed">
                                            {{ $tag->links_count }} @lang('tag.links')
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            <div class="p-3 text-end">
                                @foreach($group as $tag)
                                    <input type="hidden" name="victims[]" value="{{ $tag->id }}">
                                @endforeach
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('{{ trans('tag.merge_confirm') }}')">
                                    @lang('tag.merge_selected')
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            @endforeach

            @if($paginator->hasPages())
                <div class="mt-4">
                    {!! $paginator->onEachSide(1)->withQueryString()->links() !!}
                </div>
            @endif

        @endif

    </div>

@endsection
