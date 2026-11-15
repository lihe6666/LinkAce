<?php

namespace Tests\Commands;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TagsMergeSimilarTest extends TestCase
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

    private function createSimilarTags(): void
    {
        $general = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        $specific = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);

        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $general->links()->attach($link->id);
        $specific->links()->attach($link->id);
    }

    private function fakeAi(array $groups, array $verdicts): void
    {
        Http::fake(function ($request) use ($groups, $verdicts) {
            $system = $request->data()['messages'][0]['content'] ?? '';

            $content = str_contains($system, 'deduplicator')
                ? json_encode(['groups' => $groups], JSON_UNESCAPED_UNICODE)
                : json_encode(['verdicts' => $verdicts]);

            return Http::response([
                'choices' => [
                    ['message' => ['content' => $content]],
                ],
            ]);
        });
    }

    public function test_command_merges_the_groups_returned_by_the_ai(): void
    {
        $this->createSimilarTags();
        $this->fakeAi([['云服务', '云服务器']], [0 => true]);

        $this->artisan('tags:merge-similar', ['--key' => 'test', '--user' => $this->user->id]);

        $this->assertSoftDeleted(Tag::withTrashed()->where('name', '云服务器')->first());
        $this->assertSame(1, Tag::where('name', '云服务')->count());
    }

    public function test_command_keeps_groups_rejected_by_the_ai(): void
    {
        $this->createSimilarTags();
        $this->fakeAi([['云服务', '云服务器']], [0 => false]);

        $this->artisan('tags:merge-similar', ['--key' => 'test', '--user' => $this->user->id]);

        $this->assertSame(2, Tag::count());
    }

    public function test_command_merges_nothing_on_dry_run(): void
    {
        $this->createSimilarTags();
        $this->fakeAi([['云服务', '云服务器']], [0 => true]);

        $this->artisan('tags:merge-similar', ['--key' => 'test', '--user' => $this->user->id, '--dry-run' => true]);

        $this->assertSame(2, Tag::count());
    }

    public function test_command_ignores_names_that_are_not_in_the_tag_list(): void
    {
        $this->createSimilarTags();
        $this->fakeAi([['云服务', '不存在的标签']], [0 => true]);

        $this->artisan('tags:merge-similar', ['--key' => 'test', '--user' => $this->user->id]);

        $this->assertSame(2, Tag::count());
    }

    public function test_command_fails_without_merging_when_the_ai_is_unavailable(): void
    {
        $this->createSimilarTags();

        Http::fake(function () {
            throw new \Exception('boom');
        });

        $this->artisan('tags:merge-similar', ['--key' => 'test', '--user' => $this->user->id])
            ->expectsOutputToContain('AI grouping failed');

        $this->assertSame(2, Tag::count());
    }
}
