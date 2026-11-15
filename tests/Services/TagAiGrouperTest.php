<?php

namespace Tests\Services;

use App\Services\TagAiGrouper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TagAiGrouperTest extends TestCase
{
    public function test_returns_verified_groups(): void
    {
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => json_encode(['groups' => [['云服务', '云服务器'], ['docker', 'kubernetes']]], JSON_UNESCAPED_UNICODE)]]]])
            ->push(['choices' => [['message' => ['content' => json_encode(['verdicts' => [0 => true, 1 => false]])]]]]);

        $grouper = new TagAiGrouper('key', 'https://example.com', 'model');

        $this->assertSame([['云服务', '云服务器']], $grouper->group(['云服务', '云服务器', 'docker', 'kubernetes']));
    }

    public function test_ignores_names_that_were_not_sent(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode(['groups' => [['云服务', '不存在的标签']]], JSON_UNESCAPED_UNICODE)]],
                ],
            ]),
        ]);

        $grouper = new TagAiGrouper('key', 'https://example.com', 'model');

        $this->assertSame([], $grouper->group(['云服务', '云服务器']));
    }

    public function test_retries_in_smaller_batches_when_the_response_is_truncated(): void
    {
        $names = array_map(fn (int $i) => 'tag' . $i, range(1, 100));

        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => '{"groups": [']]]])
            ->push(['choices' => [['message' => ['content' => json_encode(['groups' => [['tag1', 'tag2']]])]]]])
            ->push(['choices' => [['message' => ['content' => json_encode(['groups' => []])]]]])
            ->push(['choices' => [['message' => ['content' => json_encode(['verdicts' => [0 => true]])]]]]);

        $grouper = new TagAiGrouper('key', 'https://example.com', 'model');

        $this->assertSame([['tag1', 'tag2']], $grouper->group($names));
    }
}
