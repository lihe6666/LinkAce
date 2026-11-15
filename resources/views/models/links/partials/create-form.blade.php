<div class="card">
    <header class="card-header">
        @lang('link.add')
    </header>
    <div class="card-body">

        <form action="{{ route('links.store') }}" method="POST" class="create-form link-form">
            @csrf

            <div class="mb-4">
                <label class="form-label" for="url">@lang('link.url')</label>
                <input name="url" id="url" type="url"
                    class="form-control form-control-lg{{ $errors->has('url') || $existing_link ? ' is-invalid' : '' }}"
                    placeholder="@lang('placeholder.link_url')" value="{{ old('url') ?: $bookmark_url ?? '' }}"
                    required autofocus>

                <div class="invalid-feedback link-exists d-flex align-items-center gap-2 {{ $existing_link ? '' : 'd-none' }}">
                    <div>@lang('link.existing_found')</div>
                    <a href="{{ route('links.edit', [$existing_link->id ?? 0]) }}"
                        class="link-exists-edit {{ $existing_link && $existing_deleted ? 'd-none' : '' }}">@lang('link.edit')</a>

                    <button type="submit" form="exiting-link-restore" title="@lang('trash.restore')"
                        class="link-exists-restore btn btn-sm btn-link {{ $existing_link && $existing_deleted ? '' : 'd-none' }}" >
                        @lang('trash.restore_deleted_link')
                    </button>
                </div>

                @error('url')
                <p class="invalid-feedback" role="alert">
                    {{ $errors->first('url') }}
                </p>
                @enderror
            </div>

            <div class="row">
                <div class="col-12 col-sm-6 col-md-7">

                    <div class="mb-4">
                        <label class="form-label" for="title">@lang('link.title')</label>
                        <input name="title" id="title"
                            class="form-control{{ $errors->has('title') ? ' is-invalid' : '' }}"
                            type="text" placeholder="@lang('placeholder.link_title')"
                            value="{{ old('title') ?: $bookmark_title ?? '' }}">
                        @if ($errors->has('title'))
                            <p class="invalid-feedback" role="alert">
                                {{ $errors->first('title') }}
                            </p>
                        @endif
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="description">@lang('link.description')</label>
                        <textarea name="description" id="description" rows="4" class="form-control"
                        >{{ old('description') ?: $bookmark_description ?? '' }}</textarea>

                        @if ($errors->has('description'))
                            <p class="invalid-feedback" role="alert">
                                {{ $errors->first('description') }}
                            </p>
                        @endif
                    </div>

                </div>
                <div class="col-12 col-sm-6 col-md-5">

                    <div class="mb-4">
                        <label class="form-label" for="lists">@lang('list.lists')</label>
                        <input name="lists" id="lists" type="text" placeholder="@lang('placeholder.list_select')"
                            class="tag-select" data-tag-data="{{ $all_lists->toJson() }}"
                            data-value="{{ Link::oldTaxonomyOutputWithoutLink('lists', $bookmark_lists ?? []) }}"
                            data-allow-creation="1" data-tag-type="lists">

                        @if ($errors->has('lists'))
                            <p class="invalid-feedback" role="alert">
                                {{ $errors->first('lists') }}
                            </p>
                        @endif
                    </div>

                    <div class="mb-4">
                        <div class="d-flex align-items-center mb-2">
                            <label class="form-label mb-0" for="tags">@lang('tag.tags')</label>
                            <button type="button" class="btn btn-xs btn-outline-primary ms-auto"
                                data-ai-tags
                                data-loading-label="@lang('tag.ai_loading')"
                                data-error-label="@lang('tag.ai_failed')">
                                @lang('tag.ai_tag')
                            </button>
                        </div>
                        <input name="tags" id="tags" type="text" placeholder="@lang('placeholder.tags_select')"
                            class="tag-select" data-tag-data="{{ $all_tags->toJson() }}"
                            data-value="{{ Link::oldTaxonomyOutputWithoutLink('tags', $bookmark_tags ?? []) }}"
                            data-allow-creation="1" data-tag-type="tags">

                        @if ($errors->has('tags'))
                            <p class="invalid-feedback" role="alert">
                                {{ $errors->first('tags') }}
                            </p>
                        @endif

                        <div class="tag-suggestions mt-2 d-none">
                            <small>@lang('We found some tags which might be interesting...')</small>
                            <div class="tag-suggestions-content mt-1"></div>
                        </div>
                    </div>

                    <x-forms.visibility-toggle class="mb-4"/>

                </div>
            </div>

            <div class="mt-3 d-sm-flex align-items-center justify-content-end">

                @if(!isset($bookmark_url))
                    <div class="form-check mb-3 mb-sm-0 me-sm-4">
                        <input class="form-check-input" type="checkbox" id="reload_view" name="reload_view"
                            @if(session('reload_view')) checked @endif>
                        <label class="form-check-label" for="reload_view">
                            @lang('linkace.continue_adding')
                        </label>
                    </div>
                @endif

                <button type="submit" class="btn btn-primary">
                    <x-icon.save class="me-2"/> @lang('link.add')
                </button>

            </div>

        </form>

        <form action="{{ route('trash-restore') }}" method="post" id="exiting-link-restore">
            @csrf
            <input type="hidden" name="model" value="link">
            <input type="hidden" name="redirect_to_model" value="1">
            <input type="hidden" name="id" class="link-exists-restore-id" value="{{ $existing_link->id ?? 0 }}">
        </form>

    </div>
</div>
