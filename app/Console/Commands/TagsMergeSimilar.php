<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Repositories\TagRepository;
use App\Services\TagAiGrouper;
use Illuminate\Console\Command;

class TagsMergeSimilar extends Command
{
    protected $signature = 'tags:merge-similar
        {--user= : Only process tags of the given user ID}
        {--dry-run : Preview merges without writing to the database}
        {--model= : Model name (defaults to LLM_MODEL env or deepseek-chat)}
        {--base= : API base URL (defaults to LLM_BASE_URL env or https://api.deepseek.com)}
        {--key= : API key (overrides LLM_API_KEY)}';

    protected $description = 'Merge tags with the same meaning, grouped by an OpenAI-compatible AI API.';

    protected $help = 'The AI receives the full list of tag names and returns the tags that mean the same
thing. Each group is merged into its tag with the most links; the others are deleted.

Configuration (set in .env):
  LLM_API_KEY    API key (required)
  LLM_BASE_URL   API base URL (default: https://api.deepseek.com)
  LLM_MODEL      Model name (default: deepseek-chat)

Examples:
  # Preview which tags would be merged
  php artisan tags:merge-similar --dry-run

  # Merge for a single user
  php artisan tags:merge-similar --user=2';

    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    public function handle(): int
    {
        $this->apiKey = $this->option('key') ?: (string) env('LLM_API_KEY');
        $this->baseUrl = rtrim((string) ($this->option('base') ?: env('LLM_BASE_URL') ?: 'https://api.deepseek.com'), '/');
        $this->model = (string) ($this->option('model') ?: env('LLM_MODEL') ?: 'deepseek-chat');

        if ($this->apiKey === '') {
            $this->error('No API key found. Set LLM_API_KEY or pass --key=.');
            return self::FAILURE;
        }

        $query = Tag::query()->withCount('links');

        if ($this->option('user')) {
            $query->where('user_id', (int) $this->option('user'));
        }

        $tagsByUser = $query->get(['id', 'user_id', 'name'])->groupBy('user_id');

        if ($tagsByUser->isEmpty()) {
            $this->info('No tags found.');
            return self::SUCCESS;
        }

        $grouper = new TagAiGrouper($this->apiKey, $this->baseUrl, $this->model);

        $merged = 0;
        $removed = 0;
        $retagged = 0;

        foreach ($tagsByUser as $userId => $tags) {
            $this->info(sprintf('User %d: asking the AI to group %d tags...', $userId, $tags->count()));

            try {
                $groups = $grouper->group($tags->pluck('name')->all());
            } catch (\Exception $e) {
                $this->error('AI grouping failed: ' . $e->getMessage());
                return self::FAILURE;
            }

            if ($groups === []) {
                $this->line('  No synonymous tags found.');
                continue;
            }

            foreach ($groups as $names) {
                $members = $tags->whereIn('name', $names)
                    ->sortByDesc(fn (Tag $tag) => $tag->links_count)
                    ->values();

                if ($members->count() < 2) {
                    continue;
                }

                $survivor = $members->first();
                $victims = $members->slice(1);
                $movedLinks = $victims->sum(fn (Tag $tag) => (int) $tag->links_count);

                $this->line('  ' . $members->pluck('name')->implode(' | ') . ' -> ' . $survivor->name);

                if ($this->option('dry-run')) {
                    continue;
                }

                TagRepository::merge($survivor, $victims);

                $merged++;
                $removed += $victims->count();
                $retagged += $movedLinks;
            }
        }

        $this->info(sprintf(
            'Done: %d group(s) merged, %d tag(s) removed, %d link(s) retagged.',
            $merged,
            $removed,
            $retagged
        ));

        return self::SUCCESS;
    }
}
