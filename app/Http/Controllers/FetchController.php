<?php

namespace App\Http\Controllers;

use App\Helper\UpdateHelper;
use App\Models\Link;
use App\Models\LinkList;
use App\Models\Tag;
use App\Services\LinkAiTagger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Masterminds\HTML5;
use Kovah\HtmlMeta\Exceptions\DisallowedIpException;
use Kovah\HtmlMeta\Exceptions\InvalidUrlException;
use Kovah\HtmlMeta\Exceptions\UnreachableUrlException;
use Kovah\HtmlMeta\Facades\HtmlMeta as HtmlMetaFacade;

class FetchController extends Controller
{
    public function getTags(Request $request): JsonResponse
    {
        $query = $request->input('query', false);

        if (!$query) {
            return response()->json([]);
        }

        $tags = Tag::query()
            ->visibleForUser()
            ->where('name', 'like', '%' . escapeSearchQuery($query) . '%')
            ->with('user:id,name')
            ->oldest('name')
            ->get();

        return response()->json($tags);
    }

    public function getLists(Request $request): JsonResponse
    {
        $query = $request->input('query', false);

        if (!$query) {
            return response()->json([]);
        }

        $tags = LinkList::query()
            ->visibleForUser()
            ->where('name', 'like', '%' . escapeSearchQuery($query) . '%')
            ->with('user:id,name')
            ->oldest('name')
            ->get();

        return response()->json($tags);
    }

    /**
     * Returns a boolean flag which indicates that there already is a link
     * present for the given URL.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function searchExistingUrls(Request $request): JsonResponse
    {
        $query = $request->input('query', false);

        if (!$query) {
            return response()->json([]);
        }

        $ignoreId = $request->input('ignore_id', 0);

        // Check the current user's deleted links first so restore/edit actions stay owner-scoped.
        // If no deleted match exists, fall back to visible active links for the current user.
        $link = Link::withTrashed()
            ->byUser()
            ->where('url', trim($query))
            ->where('id', '!=', $ignoreId)
            ->first()
            ?? Link::visibleForUser()
                ->where('url', trim($query))
                ->where('id', '!=', $ignoreId)
                ->first();

        return response()->json([
            'linkFound' => $link,
            'linkDeleted' => $link?->trashed(),
            'editLink' => $link ? route('links.edit', ['link' => $link]) : null,
            'restoreLink' => route('trash-restore'),
        ]);
    }

    public function getAiTags(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $apiKey = (string) env('LLM_API_KEY');

        if ($apiKey === '') {
            return response()->json(['error' => 'AI is not configured.'], 503);
        }

        $existingTags = Tag::byUser()
            ->withCount('links')
            ->orderByDesc('links_count')
            ->limit(300)
            ->pluck('name')
            ->all();

        $tagger = new LinkAiTagger(
            $apiKey,
            rtrim((string) (env('LLM_BASE_URL') ?: 'https://api.deepseek.com'), '/'),
            (string) (env('LLM_MODEL') ?: 'deepseek-chat'),
        );

        try {
            $tags = $tagger->tags(
                $validated['title'] ?? '',
                $validated['url'] ?? '',
                $validated['description'] ?? '',
                $existingTags,
            );
        } catch (\Exception $e) {
            Log::warning('AI tagging for a link failed: ' . $e->getMessage());

            return response()->json(['error' => 'AI tagging failed.'], 502);
        }

        return response()->json(['tags' => $tags]);
    }

    public static function checkForUpdates(): JsonResponse
    {
        $updateCheck = UpdateHelper::checkForUpdates(true);

        return response()->json(['checkResult' => $updateCheck]);
    }

    /**
     * Returns the HTML for a given URL to prevent CORS issues in the frontend
     * implementation.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function htmlKeywordsFromUrl(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['url'],
        ]);

        $url = $request->input('url');

        try {
            $response = HtmlMetaFacade::forUrl($url)->getResponse();
        } catch (DisallowedIpException|InvalidUrlException|UnreachableUrlException) {
            return response()->json(['keywords' => null]);
        }

        if (!$response?->successful()) {
            return response()->json(['keywords' => null]);
        }

        return response()->json(['keywords' => $this->extractKeywords($response->body())]);
    }

    /**
     * @return array<int, string>
     */
    protected function extractKeywords(string $html): array
    {
        $html5 = new HTML5();
        $dom = $html5->loadHTML($html);
        $keywords = [];

        /** @var \DOMElement $metaTag */
        foreach ($dom->getElementsByTagName('meta') as $metaTag) {
            if (strtolower($metaTag->getAttribute('name')) !== 'keywords') {
                continue;
            }

            $keywords = explode(',', $metaTag->getAttributeNode('content')?->value);
            $keywords = array_map(fn($keyword) => trim(e($keyword)), $keywords);
            array_push($keywords, ...$keywords);
        }

        return $keywords;
    }
}
