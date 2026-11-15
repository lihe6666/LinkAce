<?php

namespace App\Http\Controllers\Models;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Repositories\TagRepository;
use App\Services\TagAiGrouper;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class TagMergeController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Tag::class);

        return view('models.tags.merge', [
            'pageTitle' => trans('tag.merge_similar'),
        ]);
    }

    public function mergeAll(): RedirectResponse
    {
        $this->authorize('viewAny', Tag::class);

        $apiKey = (string) env('LLM_API_KEY');

        if ($apiKey === '') {
            flash(trans('tag.merge_ai_not_configured'), 'error');
            return redirect()->route('tags.merge.index');
        }

        $baseUrl = rtrim((string) (env('LLM_BASE_URL') ?: 'https://api.deepseek.com'), '/');
        $model = (string) (env('LLM_MODEL') ?: 'deepseek-chat');

        $tags = Tag::byUser()->withCount('links')->get(['id', 'user_id', 'name']);

        try {
            $groups = (new TagAiGrouper($apiKey, $baseUrl, $model))->group($tags->pluck('name')->all());
        } catch (\Exception $e) {
            flash(trans('tag.merge_ai_failed'), 'error');
            return redirect()->route('tags.merge.index');
        }

        $merged = 0;

        foreach ($groups as $names) {
            $members = $tags->whereIn('name', $names)
                ->sortByDesc(fn (Tag $tag) => $tag->links_count)
                ->values();

            if ($members->count() < 2) {
                continue;
            }

            $survivor = $members->first();
            $victims = $members->slice(1);

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
