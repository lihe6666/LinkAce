<?php

namespace App\Http\Controllers\Models;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Repositories\TagRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class TagMergeController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Tag::class);

        $groups = Tag::similarGroups();

        $perPage = 20;
        $page = max(1, (int) request()->input('page', 1));

        $paginator = new LengthAwarePaginator(
            $groups->forPage($page, $perPage)->values(),
            $groups->count(),
            $perPage,
            $page,
            ['path' => request()->url()]
        );

        return view('models.tags.merge', [
            'pageTitle' => trans('tag.merge_similar'),
            'groups' => $paginator->getCollection(),
            'paginator' => $paginator,
        ]);
    }

    public function merge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'survivor' => ['required', 'integer', 'exists:tags,id'],
            'victims' => ['required', 'array'],
            'victims.*' => ['integer', 'exists:tags,id'],
        ]);

        $survivor = Tag::findOrFail($validated['survivor']);
        $this->authorize('update', $survivor);

        $victims = Tag::whereIn('id', $validated['victims'])->get();

        foreach ($victims as $victim) {
            $this->authorize('delete', $victim);
        }

        TagRepository::merge($survivor, $victims);

        flash(trans('tag.merged_successfully'), 'success');

        return redirect()->route('tags.merge.index');
    }

    public function mergeAll(): RedirectResponse
    {
        $merged = 0;

        foreach (Tag::similarGroups() as $group) {
            $survivor = $group->first();
            $victims = $group->slice(1);

            $this->authorize('update', $survivor);

            foreach ($victims as $victim) {
                $this->authorize('delete', $victim);
            }

            TagRepository::merge($survivor, $victims);
            $merged++;
        }

        flash(trans('tag.merged_all_successfully', ['count' => $merged]), 'success');

        return redirect()->route('tags.merge.index');
    }
}
