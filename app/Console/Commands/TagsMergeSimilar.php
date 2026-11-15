<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Repositories\TagRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TagsMergeSimilar extends Command
{
    protected $signature = 'tags:merge-similar
        {--user= : Only process tags of the given user ID}
        {--limit= : Maximum number of tag groups to process}
        {--dry-run : Preview merges without writing to the database}
        {--model= : Model name (defaults to LLM_MODEL env or deepseek-chat)}
        {--base= : API base URL (defaults to LLM_BASE_URL env or https://api.deepseek.com)}
        {--key= : API key (overrides LLM_API_KEY)}';

    protected $description = 'Automatically merge tags with the same meaning using an OpenAI-compatible AI API.';

    protected $help = 'Merge tags whose names mean the same thing (e.g. "cloud" and "cloud server").

Groups are detected by name similarity, then confirmed by an AI judge using the
links\' descriptions as evidence. Only groups with at least one link description
are merged automatically; the rest can be reviewed manually at /tags/merge.

Configuration (set in .env):
  LLM_API_KEY    API key (required)
  LLM_BASE_URL   API base URL (default: https://api.deepseek.com)
  LLM_MODEL      Model name (default: deepseek-chat)

Examples:
  # Preview what would be merged
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

        $groups = Tag::similarGroups($this->option('user'));

        if ($groups->isEmpty()) {
            $this->info('No similar tag groups found.');
            return self::SUCCESS;
        }

        if ($this->option('limit')) {
            $groups = $groups->take((int) $this->option('limit'));
        }

        $this->info(sprintf('Found %d similar tag group(s).', $groups->count()));

        $verdicts = $this->judgeGroups($groups);

        if ($verdicts === null) {
            $this->warn('AI judge unavailable. Nothing merged; review the groups at /tags/merge.');
            return self::SUCCESS;
        }

        $merged = 0;
        $removed = 0;
        $retagged = 0;
        $manual = 0;

        foreach ($groups as $index => $group) {
            if (($verdicts[$index] ?? null) !== true) {
                $manual++;
                $this->line("  Group {$index}: kept apart (no AI confirmation)");
                continue;
            }

            $survivor = $group->first();
            $victims = $group->slice(1);
            $movedLinks = $victims->sum(fn (Tag $tag) => (int) $tag->links_count);

            if ($this->option('dry-run')) {
                $this->info("  Would merge {$victims->count()} tag(s) into \"{$survivor->name}\" ({$movedLinks} link(s) moved)");
                continue;
            }

            TagRepository::merge($survivor, $victims);

            $merged++;
            $removed += $victims->count();
            $retagged += $movedLinks;
            $this->info("  Merged \"{$survivor->name}\" + {$victims->count()} other(s) ({$movedLinks} link(s) moved)");
        }

        $this->info(sprintf(
            'Done: %d group(s) merged, %d tag(s) removed, %d link(s) retagged, %d group(s) left for manual review at /tags/merge.',
            $merged,
            $removed,
            $retagged,
            $manual
        ));

        return self::SUCCESS;
    }

    /**
     * Ask the AI judge whether the tags in each group mean the same thing.
     * Returns a map of group index => bool, an empty array when no group has
     * any link description to judge from, or null when the AI is unavailable.
     */
    protected function judgeGroups(Collection $groups): ?array
    {
        $payload = [];

        foreach ($groups as $index => $group) {
            $tags = $group->map(function (Tag $tag) {
                return [
                    'name' => $tag->name,
                    'descriptions' => $tag->links()
                        ->visibleForUser()
                        ->limit(3)
                        ->pluck('description')
                        ->filter()
                        ->map(fn (string $description) => Str::limit($description, 200))
                        ->values()
                        ->all(),
                ];
            })->all();

            $hasEvidence = collect($tags)->contains(fn (array $tag) => count($tag['descriptions']) > 0);

            if (! $hasEvidence) {
                continue;
            }

            $payload[] = ['index' => $index, 'tags' => $tags];
        }

        if ($payload === []) {
            $this->warn('No link descriptions available for the similar tag groups; nothing merged automatically.');
            return [];
        }

        $system = 'You are a bookmark tag judge. For each group of tags, decide whether ALL tags in the group refer '
            . 'to the same subject and can be replaced by a single tag on the same bookmarks. Use the provided link '
            . 'descriptions as evidence. Treat a shorter, more general name and a slightly longer, more specific '
            . 'variant of the same subject (e.g. "cloud service" and "cloud server", "web" and "web development") '
            . 'as the same tag. Respond with ONLY valid JSON: {"verdicts": {"<group index>": true or false}}. '
            . 'true means the tags should be merged; false means they are clearly different or you are not sure. '
            . 'When in doubt, answer false.';

        $userPrompt = json_encode(['groups' => $payload], JSON_UNESCAPED_UNICODE);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::baseUrl($this->baseUrl)
                    ->timeout(120)
                    ->withToken($this->apiKey)
                    ->post('chat/completions', [
                        'model' => $this->model,
                        'messages' => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user', 'content' => $userPrompt],
                        ],
                        'response_format' => ['type' => 'json_object'],
                        'temperature' => 0.0,
                    ]);

                $body = $response->json();
                $content = $body['choices'][0]['message']['content'] ?? '';

                return $this->parseVerdicts($content);
            } catch (\Exception $e) {
                $this->warn("AI judge request failed (attempt {$attempt}/3): {$e->getMessage()}");
                if ($attempt >= 3) {
                    return null;
                }
                sleep(2);
            }
        }

        return null;
    }

    protected function parseVerdicts(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! isset($decoded['verdicts'])) {
            $this->warn('AI judge returned unusable JSON; nothing merged automatically.');
            return [];
        }

        $verdicts = [];
        foreach ($decoded['verdicts'] as $index => $verdict) {
            $verdicts[(int) $index] = (bool) $verdict;
        }

        return $verdicts;
    }
}
