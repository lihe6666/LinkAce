<?php

namespace App\Http\Controllers\Models;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Repositories\TagRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TagMergeController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Tag::class);

        return view('models.tags.merge', [
            'pageTitle' => trans('tag.merge_similar'),
            'groups' => Tag::similarGroups(),
        ]);
    }

    public function merge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'survivor' => ['required', 'integer', 'exists:tags,id'],
            'victims' => ['required', 'array'],
            'victims.*' => ['integer', 'exists:tags,id', 'different:survivor'],
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
}
