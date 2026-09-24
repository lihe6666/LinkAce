<?php

namespace App\Console\Commands;

use App\Enums\ModelAttribute;
use App\Models\Link;
use App\Models\Tag;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AiTagLinks extends Command
{
    protected $signature = 'links:ai-tag
        {--batch=10 : Number of links per API request}
        {--limit= : Maximum number of links to process}
        {--user= : Only process links of the given user ID}
        {--only-missing : Only process links that have no tags}
        {--dry-run : Preview results without writing to the database}
        {--mode= : Tag mode: replace or append (defaults to AI_TAG_MODE env, fallback replace)}
        {--model= : Model name (defaults to LLM_MODEL env or deepseek-chat)}
        {--base= : API base URL (defaults to LLM_BASE_URL env or https://api.deepseek.com)}
        {--key= : API key (overrides LLM_API_KEY)}';

    protected $description = 'Generate topical tags for links using an OpenAI-compatible AI API.';

    protected $help = 'Generate topical tags for links via an OpenAI-compatible AI API.

Configuration (set in .env):
  LLM_API_KEY    API key (required)
  LLM_BASE_URL   API base URL (default: https://api.deepseek.com)
  LLM_MODEL      Model name (default: deepseek-chat)
  AI_TAG_MODE    Tag mode: replace or append (default: replace)

Examples:
  # Preview tags for 20 untagged links (no DB writes)
  php artisan links:ai-tag --only-missing --dry-run --limit=20

  # Tag every link that currently has no tags
  php artisan links:ai-tag --only-missing

  # Re-tag ALL links (overwrites existing tags)
  php artisan links:ai-tag

  # Only add tags, keep existing ones
  php artisan links:ai-tag --mode=append

  # Tag a single user
  php artisan links:ai-tag --user=2

  # Use another OpenAI-compatible provider
  php artisan links:ai-tag --base=https://api.openai.com/v1 --model=gpt-4o-mini

  # Long jobs: run in background (as the web user)
  sudo -u www bash -lc "nohup php artisan links:ai-tag --only-missing > storage/logs/ai-tag.log 2>&1 &"';

    protected Client $client;

    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    protected string $mode;

    public function handle(): int
    {
        $this->apiKey = $this->option('key') ?: (string) env('LLM_API_KEY');
        $this->baseUrl = rtrim((string) ($this->option('base') ?: env('LLM_BASE_URL') ?: 'https://api.deepseek.com'), '/');
        $this->model = (string) ($this->option('model') ?: env('LLM_MODEL') ?: 'deepseek-chat');

        $this->mode = $this->option('mode') ?: env('AI_TAG_MODE', 'replace');
        if (! in_array($this->mode, ['replace', 'append'], true)) {
            $this->mode = 'replace';
        }

        if ($this->apiKey === '') {
            $this->error('No API key found. Set LLM_API_KEY or pass --key=.');
            return self::FAILURE;
        }

        $this->info("API: {$this->baseUrl} (model: {$this->model})");
        $this->info("Tag mode: {$this->mode}");

        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);

        $links = $this->getLinks();

        if ($links->isEmpty()) {
            $this->info('No links to process.');
            return self::SUCCESS;
        }

        $batch = max(1, (int) $this->option('batch'));
        $this->info(sprintf('Processing %d links in batches of %d...', $links->count(), $batch));

        $processed = 0;
        $tagged = 0;

        foreach ($links->chunk($batch) as $chunk) {
            $tagsMap = $this->requestTags($chunk);

            foreach ($chunk as $link) {
                $processed++;
                $tags = $tagsMap[(string) $link->id] ?? [];

                if (empty($tags)) {
                    $this->line("  [{$link->id}] {$link->shortTitle(40)} -> (no tags)");
                    continue;
                }

                $this->line("  [{$link->id}] {$link->shortTitle(40)} -> " . implode(', ', $tags));

                if (! $this->option('dry-run')) {
                    $this->applyTags($link, $tags);
                }

                $tagged++;
            }

            $this->info("Progress: {$processed}/{$links->count()} links, {$tagged} tagged");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    protected function getLinks()
    {
        $query = Link::query();

        if ($this->option('user')) {
            $query->where('user_id', (int) $this->option('user'));
        }

        if ($this->option('only-missing')) {
            $query->doesntHave('tags');
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        return $query->get(['id', 'user_id', 'url', 'title', 'description']);
    }

    protected function requestTags($links): array
    {
        $items = $links->map(function (Link $link) {
            return [
                'id' => (string) $link->id,
                'title' => (string) $link->title,
                'url' => (string) $link->url,
                'description' => Str::limit((string) $link->description, 200),
            ];
        })->values()->all();

        $systemPrompt = 'You are a bookmark classifier. For each link, generate 1 to 3 concise topical tags (e.g. docker, devops, frontend, rust) in the language of the content. Return ONLY valid JSON: an object mapping the link id to an array of tag strings. Example: {"12":["docker","devops"]}';

        $userPrompt = 'Classify the following links:' . "\n" . json_encode($items, JSON_UNESCAPED_UNICODE);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->client->post('chat/completions', [
                    'json' => [
                        'model' => $this->model,
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $userPrompt],
                        ],
                        'response_format' => ['type' => 'json_object'],
                        'temperature' => 0.3,
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                    ],
                ]);

                $body = json_decode($response->getBody()->getContents(), true);
                $content = $body['choices'][0]['message']['content'] ?? '';

                return $this->parseTags($content, $items);
            } catch (\Exception $e) {
                $this->warn("  API request failed (attempt {$attempt}/3): {$e->getMessage()}");
                if ($attempt >= 3) {
                    return [];
                }
                sleep(2);
            }
        }

        return [];
    }

    protected function parseTags(string $content, array $items): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            $id = (string) $item['id'];
            $tags = $decoded[$id] ?? null;
            if (is_array($tags)) {
                $result[$id] = array_values(array_filter(array_map('strval', array_map('trim', $tags))));
            }
        }

        return $result;
    }

    protected function applyTags(Link $link, array $tags): void
    {
        $tagIds = [];
        foreach ($tags as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $tag = Tag::firstOrCreate(
                ['user_id' => $link->user_id, 'name' => $name],
                ['user_id' => $link->user_id, 'name' => $name, 'visibility' => ModelAttribute::VISIBILITY_PUBLIC],
            );

            $tagIds[] = $tag->id;
        }

        if (! empty($tagIds)) {
            if ($this->mode === 'replace') {
                $link->tags()->sync($tagIds);
            } else {
                $link->tags()->syncWithoutDetaching($tagIds);
            }
        }
    }
}
