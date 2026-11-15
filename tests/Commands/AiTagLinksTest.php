<?php

namespace Tests\Commands;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiTagLinksTest extends TestCase
{
    use DatabaseMigrations;
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function fakeAiTags(array $tags, Link $link): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([(string) $link->id => $tags], JSON_UNESCAPED_UNICODE)]],
                ],
            ]),
        ]);
    }

    public function test_ai_tag_reuses_similar_existing_tag(): void
    {
        $existing = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        $link = Link::factory()->create(['user_id' => $this->user->id, 'title' => 'A cloud page']);

        $this->fakeAiTags(['云服务器'], $link);

        $this->artisan('links:ai-tag', ['--key' => 'test', '--user' => $this->user->id])
            ->expectsOutputToContain('云服务');

        $this->assertDatabaseCount('tags', 1);
        $this->assertTrue($link->fresh()->tags()->first()->is($existing));
    }

    public function test_ai_tag_prompt_lists_existing_tags(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        $link = Link::factory()->create(['user_id' => $this->user->id, 'title' => 'A cloud page']);

        Http::fake(function ($request) use ($link) {
            $this->assertStringContainsString('云服务', $request->data()['messages'][1]['content']);

            return Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([(string) $link->id => ['docker']])]],
                ],
            ]);
        });

        $this->artisan('links:ai-tag', ['--key' => 'test', '--user' => $this->user->id]);

        $this->assertDatabaseCount('tags', 2);
    }

    public function test_ai_tag_creates_new_tag_when_no_match(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'docker']);
        $link = Link::factory()->create(['user_id' => $this->user->id, 'title' => 'A rust page']);

        $this->fakeAiTags(['kubernetes'], $link);

        $this->artisan('links:ai-tag', ['--key' => 'test', '--user' => $this->user->id]);

        $this->assertDatabaseCount('tags', 2);
        $this->assertSame(1, $link->fresh()->tags()->count());
    }
}
