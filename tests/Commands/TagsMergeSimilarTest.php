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

        $link = Link::factory()->create([
            'user_id' => $this->user->id,
            'description' => 'A page about cloud servers',
        ]);

        $general->links()->attach($link->id);
        $specific->links()->attach($link->id);
    }

    private function fakeJudge(bool $verdict): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode(['verdicts' => ['0' => $verdict]])]],
                ],
            ]),
        ]);
    }

    public function test_command_merges_when_ai_confirms_same_meaning(): void
    {
        $this->createSimilarTags();
        $this->fakeJudge(true);

        $this->artisan('tags:merge-similar', ['--key' => 'test'])
            ->expectsOutputToContain('Merged');

        $this->assertSoftDeleted(Tag::withTrashed()->where('name', '云服务器')->first());
        $this->assertSame(1, Tag::where('name', '云服务')->count());
    }

    public function test_command_keeps_tags_when_ai_says_different(): void
    {
        $this->createSimilarTags();
        $this->fakeJudge(false);

        $this->artisan('tags:merge-similar', ['--key' => 'test'])
            ->expectsOutputToContain('kept apart');

        $this->assertDatabaseCount('tags', 2);
    }

    public function test_command_merges_nothing_on_dry_run(): void
    {
        $this->createSimilarTags();
        $this->fakeJudge(true);

        $this->artisan('tags:merge-similar', ['--key' => 'test', '--dry-run' => true])
            ->expectsOutputToContain('Would merge');

        $this->assertDatabaseCount('tags', 2);
    }

    public function test_command_falls_back_when_ai_unreachable(): void
    {
        $this->createSimilarTags();

        Http::fake(function () {
            throw new \Exception('boom');
        });

        $this->artisan('tags:merge-similar', ['--key' => 'test'])
            ->expectsOutputToContain('AI judge unavailable');

        $this->assertDatabaseCount('tags', 2);
    }
}
