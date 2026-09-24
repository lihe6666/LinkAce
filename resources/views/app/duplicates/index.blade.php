@extends('layouts.app')

@section('content')

    <div class="duplicates-page">

        <header class="d-flex align-items-center">
            <h3 class="mb-0">
                @lang('duplicates.duplicates')
            </h3>
            @if($groups->isNotEmpty())
                <button type="button" class="btn btn-sm btn-outline-danger ms-auto"
                    data-duplicate-delete
                    data-confirmation="@lang('duplicates.delete_all_confirm')">
                    @lang('duplicates.delete_all_selected')
                </button>
            @endif
        </header>

        <form id="duplicate-delete-form" method="POST" style="display: none;"
            action="{{ route('bulk-edit.delete') }}">
            @csrf
            <input type="hidden" name="type" value="links">
            <input type="hidden" name="models">
            <input type="hidden" name="redirect_back" value="true">
        </form>

        @if($groups->isEmpty())

            <div class="alert alert-info mt-4">
                @lang('duplicates.no_duplicates')
            </div>

        @else

            @foreach($groups as $group)
                <div class="card mb-4" data-duplicate-group>
                    <div class="card-header d-flex align-items-center">
                        <a href="{{ $group->first()->url }}" {!! linkTarget() !!} class="text-pale small short-text me-2">
                            {{ $group->first()->shortUrl() }}
                        </a>
                        <span class="badge bg-secondary">@lang('duplicates.duplicate_count', ['count' => $group->count()])</span>
                        <button type="button" class="btn btn-xs btn-outline-danger ms-auto"
                            data-duplicate-delete
                            data-confirmation="@lang('duplicates.delete_selected_confirm')">
                            @lang('duplicates.delete_selected')
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <table class="table mb-0">
                            <tbody>
                            @foreach($group as $link)
                                <tr>
                                    <td class="text-center" style="width: 40px;">
                                        <input type="checkbox" aria-label="@lang('link.bulk_edit_add')"
                                            class="duplicate-check form-check d-inline-block" data-id="{{ $link->id }}"
                                            @if(!$loop->first) checked @endif>
                                    </td>
                                    <td>
                                        <a href="{{ route('links.show', [$link->id]) }}" class="title">
                                            {{ $link->title }}
                                        </a>
                                        @if($link->tags->count() > 0)
                                            <div class="mt-1">
                                                @foreach($link->tags as $tag)
                                                    <a href="{{ route('tags.show', ['tag' => $tag]) }}" class="btn btn-xs btn-light">
                                                        <x-models.name-with-user :model="$tag"/>
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="meta text-condensed">
                                        <a href="{{ $link->url }}" {!! linkTarget() !!} class="small short-text">
                                            {{ $link->shortUrl() }}
                                        </a>
                                    </td>
                                    <td class="meta text-pale small text-condensed">{!! $link->addedAt() !!}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach

        @endif

        @if($groups->isNotEmpty() && $paginator->hasPages())
            <div class="mt-4">
                {!! $paginator->onEachSide(1)->withQueryString()->links() !!}
            </div>
        @endif

    </div>

@endsection
