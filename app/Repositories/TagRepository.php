<?php

namespace App\Repositories;

use App\Models\Link;
use App\Models\Tag;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TagRepository
{
    public static function create(array $data): Tag
    {
        $data['user_id'] = auth()->user()->id;
        $data['name'] = str_replace(',', '', $data['name']);

        $tag = Tag::withoutSyncingToSearch(fn () => Tag::create($data));

        self::syncTagToSearch($tag);

        return $tag;
    }

    public static function update(Tag $tag, array $data): Tag
    {
        $data['name'] = str_replace(',', '', $data['name']);

        Tag::withoutSyncingToSearch(fn () => $tag->update($data));
        self::syncTagToSearch($tag);

        return $tag;
    }

    public static function bulkUpdate(array $models, array $data): \Illuminate\Support\Collection
    {
        $tags = Tag::whereIn('id', $models)->get();

        return $tags->map(function (Tag $tag) use ($data) {
            if (!auth()->user()->can('update', $tag)) {
                Log::warning('Could not update tag ' . $tag->id . ' during bulk update: Permission denied!');
                return null;
            }

            $tagData = $tag->toArray();
            $tagData['visibility'] = $data['visibility'] ?: $tagData['visibility'];

            return TagRepository::update($tag, $tagData);
        });
    }

    public static function delete(Tag $tag): bool
    {
        try {
            $links = self::searchIndexingEnabled()
                ? $tag->links()->get()
                : collect();

            $tag->links()->detach();
            Tag::withoutSyncingToSearch(fn () => $tag->delete());
            self::removeTagFromSearch($tag);
            self::syncLinksToSearch($links);
        } catch (Exception $e) {
            Log::error($e);
            return false;
        }

        return true;
    }

    /**
     * Merge the victim tags into the survivor: all their links are attached
     * to the survivor (skipping existing combinations) and the victims are
     * deleted. The survivor keeps its name and visibility.
     */
    public static function merge(Tag $survivor, Collection $victims): Tag
    {
        $affectedLinks = collect();

        DB::transaction(function () use ($survivor, $victims, &$affectedLinks): void {
            foreach ($victims as $victim) {
                if ($victim->is($survivor)) {
                    continue;
                }

                $linkIds = DB::table('link_tags')
                    ->where('tag_id', $victim->id)
                    ->pluck('link_id');

                if ($linkIds->isNotEmpty()) {
                    $existing = DB::table('link_tags')
                        ->where('tag_id', $survivor->id)
                        ->whereIn('link_id', $linkIds)
                        ->pluck('link_id');

                    $linkIds->diff($existing)
                        ->chunk(500)
                        ->each(fn (Collection $chunk) => DB::table('link_tags')->insert(
                            $chunk->map(fn ($linkId) => ['tag_id' => $survivor->id, 'link_id' => $linkId])->all()
                        ));

                    $affectedLinks = $affectedLinks->merge($linkIds);
                }

                DB::table('link_tags')->where('tag_id', $victim->id)->delete();

                if (! self::delete($victim)) {
                    throw new Exception('Could not delete tag ' . $victim->id . ' during merge.');
                }
            }
        });

        self::syncLinksToSearch(
            $affectedLinks->unique()->map(fn ($id) => Link::find($id))->filter()
        );

        return $survivor;
    }

    private static function syncTagToSearch(Tag $tag): void
    {
        if (!self::searchIndexingEnabled()) {
            return;
        }

        $tag->searchable();
    }

    private static function removeTagFromSearch(Tag $tag): void
    {
        if (!self::searchIndexingEnabled()) {
            return;
        }

        $tag->unsearchable();
    }

    private static function syncLinksToSearch(Collection $links): void
    {
        if (!self::searchIndexingEnabled()) {
            return;
        }

        $links->each(function (Link $link): void {
            $link->load(['tags', 'lists']);
            $link->searchable();
        });
    }

    private static function searchIndexingEnabled(): bool
    {
        return in_array(
            config('linkace.search.driver'),
            config('linkace.search.external_drivers', []),
            true
        );
    }
}
