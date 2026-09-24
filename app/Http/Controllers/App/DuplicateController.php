<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Link;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;

class DuplicateController extends Controller
{
    public function index(Request $request): View
    {
        $groupIds = Link::duplicateGroupIds();

        $perPage = 50;
        $page = max(1, (int) $request->input('page', 1));

        $paginator = new LengthAwarePaginator(
            $groupIds->forPage($page, $perPage)->values(),
            $groupIds->count(),
            $perPage,
            $page,
            ['path' => $request->url()]
        );

        $pageIds = $paginator->getCollection()->flatten()->all();

        $links = Link::whereIn('id', $pageIds)
            ->with('tags')
            ->get()
            ->keyBy('id');

        $groups = $paginator->getCollection()
            ->map(fn (SupportCollection $ids) => $ids->map(fn ($id) => $links->get($id))->filter())
            ->values();

        return view('app.duplicates.index', [
            'pageTitle' => trans('duplicates.duplicates'),
            'groups' => $groups,
            'paginator' => $paginator,
        ]);
    }
}
