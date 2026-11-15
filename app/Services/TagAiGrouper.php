<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Groups tag names that mean the same thing with an OpenAI-compatible AI API.
 *
 * Names are sent in small batches to keep the answers complete, and every
 * candidate group is verified by the AI again so that unrelated tags are not
 * merged just because they share a topic.
 */
class TagAiGrouper
{
    protected const BATCH_SIZE = 100;

    protected const MIN_BATCH_SIZE = 25;

    public function __construct(
        protected string $apiKey,
        protected string $baseUrl,
        protected string $model,
    ) {
    }

    /**
     * @param string[] $names
     * @return array<int, string[]> verified groups of tag names, at least two per group
     */
    public function group(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names))));

        if (count($names) < 2) {
            return [];
        }

        $candidates = [];

        foreach (array_chunk($names, self::BATCH_SIZE) as $batch) {
            $candidates = array_merge($candidates, $this->groupBatch($batch));
        }

        return $candidates === [] ? [] : $this->verify($candidates);
    }

    /**
     * @param string[] $names
     * @return array<int, string[]>
     */
    protected function groupBatch(array $names): array
    {
        $groups = $this->parseGroups($this->requestGrouping($names), $names);

        if ($groups !== null) {
            return $groups;
        }

        if (count($names) <= self::MIN_BATCH_SIZE) {
            throw new RuntimeException('The AI returned an unusable tag grouping response.');
        }

        $half = (int) ceil(count($names) / 2);

        return array_merge(
            $this->groupBatch(array_slice($names, 0, $half)),
            $this->groupBatch(array_slice($names, $half)),
        );
    }

    /**
     * @param string[] $names
     */
    protected function requestGrouping(array $names): string
    {
        $system = 'You are a strict bookmark tag deduplicator. Group tag names ONLY when they are different '
            . 'spellings of the exact same thing and any bookmark using one would use the other. DO group: '
            . '"android"+"Android", "nodejs"+"node.js", "css"+"CSS"+"CSS3", "云服务"+"云服务器". DO NOT group: '
            . '"docker"+"kubernetes", "claude"+"ChatGPT", "PDF翻译"+"PDF417", "Base64"+"UUID", "AR"+"虚拟现实", '
            . '"IP查询"+"BIN查询", "PPT"+"after effects". When in doubt, leave tags out. Respond with ONLY valid '
            . 'JSON: {"groups": [["name1", "name2"], ...]}. Copy every name verbatim from the input list and '
            . 'only include groups with at least two names.';

        return $this->request($system, ['tags' => $names]);
    }

    /**
     * Drop candidate groups that the AI rejects on a second look.
     *
     * @param array<int, string[]> $groups
     * @return array<int, string[]>
     */
    protected function verify(array $groups): array
    {
        $system = 'You are a strict tag merge reviewer. Answer true only if all names in a group refer to the '
            . 'same entity and one can replace the other on bookmarks. A general name and a more specific '
            . 'variant of the SAME subject count as the same ("云服务"+"云服务器", "AI"+"AI工具"). Answer false '
            . 'when the names differ in action, tool, product, file type or subtopic, for example: '
            . '"文件上传"+"文件同步", "开发"+"开发工具", "代码"+"代码托管", "协作"+"分享", "优化"+"代码格式化", '
            . '"docker"+"kubernetes", "claude"+"ChatGPT", "IP查询"+"IP地址". When in doubt, answer false. '
            . 'Respond with ONLY valid JSON: {"verdicts": {"0": true, "1": false, ...}}.';

        $verified = [];

        foreach (array_chunk($groups, 40, true) as $chunk) {
            $keys = array_keys($chunk);
            $verdicts = $this->requestVerdicts($system, array_values($chunk));

            foreach ($keys as $position => $originalKey) {
                if ($verdicts[$position] ?? false) {
                    $verified[] = $groups[$originalKey];
                }
            }
        }

        return $verified;
    }

    /**
     * @param array<int, string[]> $groups
     * @return array<int, bool>
     */
    protected function requestVerdicts(string $system, array $groups): array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $decoded = json_decode($this->stripCodeFences($this->request($system, ['groups' => $groups])), true);

            if (! is_array($decoded) || ! is_array($decoded['verdicts'] ?? null)) {
                continue;
            }

            $verdicts = [];

            foreach ($decoded['verdicts'] as $index => $verdict) {
                $verdicts[(int) $index] = $verdict === true;
            }

            return $verdicts;
        }

        throw new RuntimeException('The AI returned an unusable tag verification response.');
    }

    protected function request(string $system, array $payload): string
    {
        $response = Http::baseUrl($this->baseUrl)
            ->timeout(120)
            ->retry(3, 2000)
            ->withToken($this->apiKey)
            ->post('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.0,
                'max_tokens' => 8000,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI tag grouping failed with HTTP ' . $response->status());
        }

        return (string) $response->json('choices.0.message.content');
    }

    /**
     * Returns the parsed groups, or null when the response cannot be used.
     *
     * @param string[] $names
     * @return array<int, string[]>|null
     */
    protected function parseGroups(string $content, array $names): ?array
    {
        $decoded = json_decode($this->stripCodeFences($content), true);

        if (! is_array($decoded) || ! is_array($decoded['groups'] ?? null)) {
            return null;
        }

        $known = array_flip($names);
        $assigned = [];
        $groups = [];

        foreach ($decoded['groups'] as $group) {
            if (! is_array($group)) {
                continue;
            }

            $members = [];

            foreach ($group as $name) {
                if (! is_string($name) || ! isset($known[$name]) || isset($assigned[$name])) {
                    continue;
                }

                $members[] = $name;
            }

            if (count($members) < 2) {
                continue;
            }

            foreach ($members as $name) {
                $assigned[$name] = true;
            }

            $groups[] = $members;
        }

        return $groups;
    }

    protected function stripCodeFences(string $content): string
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);

        return preg_replace('/\s*```$/', '', $content);
    }
}
