@extends('layouts.app')

@section('content')

    <div class="tag-merge-page">

        <header class="d-flex align-items-center">
            <h3 class="mb-0">
                @lang('tag.merge_similar')
            </h3>
        </header>

        <div class="alert alert-info mt-4">
            @lang('tag.merge_intro')
        </div>

        <form method="POST" action="{{ route('tags.merge.all') }}">
            @csrf
            <button type="submit" class="btn btn-outline-danger"
                onclick="return confirm('{{ trans('tag.merge_all_confirm') }}')">
                @lang('tag.merge_all')
            </button>
        </form>

    </div>

@endsection
