<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generates topical tags for a single link through an OpenAI-compatible API,
 * preferring tag names the user already uses.
 */
class LinkAiTagger
{
    public function __construct(
        protected string $apiKey,
        protected string $baseUrl,
        protected string $model,
    ) {
    }

    /**
     * @param string[] $existingTags
     * @return string[]
     */
    public function tags(string $title, string $url, string $description, array $existingTags = []): array
    {
        $system = 'You are a bookmark classifier. Generate 1 to 3 concise topical tags for the link in the '
            . 'language of the content. Reuse the existing tag names verbatim when they fit and only invent a '
            . 'new tag when none fits. Return ONLY valid JSON: {"tags": ["tag1", "tag2"]}.';

        $response = Http::baseUrl($this->baseUrl)
            ->timeout(60)
            ->retry(2, 1000)
            ->withToken($this->apiKey)
            ->post('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode([
                        'title' => $title,
                        'url' => $url,
                        'description' => Str::limit($description, 200),
                        'existing_tags' => $existingTags,
                    ], JSON_UNESCAPED_UNICODE)],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.3,
                'max_tokens' => 200,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI tagging failed with HTTP ' . $response->status());
        }

        return $this->parseTags((string) $response->json('choices.0.message.content'));
    }

    /**
     * @return string[]
     */
    protected function parseTags(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! is_array($decoded['tags'] ?? null)) {
            return [];
        }

        $tags = [];

        foreach ($decoded['tags'] as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $tag = Str::limit(trim($tag), 50, '');

            if ($tag !== '') {
                $tags[$tag] = true;
            }
        }

        return array_slice(array_keys($tags), 0, 5);
    }
}
