<?php

namespace Tests\Controller\Models;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TagMergeControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        putenv('LLM_API_KEY=test');
        $_ENV['LLM_API_KEY'] = 'test';
        $_SERVER['LLM_API_KEY'] = 'test';
    }

    protected function tearDown(): void
    {
        putenv('LLM_API_KEY');
        unset($_ENV['LLM_API_KEY'], $_SERVER['LLM_API_KEY']);

        parent::tearDown();
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

    public function test_index_view(): void
    {
        $this->get('tags/merge')
            ->assertOk()
            ->assertSee(trans('tag.merge_all'));
    }

    public function test_merge_all_endpoint_merges_ai_groups(): void
    {
        $general = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        $specific = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $general->links()->attach($link->id);
        $specific->links()->attach($link->id);

        $this->fakeAi([['云服务', '云服务器']], [0 => true]);

        $this->post('tags/merge/all')->assertRedirect(route('tags.merge.index'));

        $this->assertSoftDeleted($specific);
        $this->assertSame(1, Tag::count());
    }

    public function test_merge_all_endpoint_keeps_groups_rejected_by_the_ai(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);

        $this->fakeAi([['云服务', '云服务器']], [0 => false]);

        $this->post('tags/merge/all')->assertRedirect(route('tags.merge.index'));

        $this->assertSame(2, Tag::count());
    }

    public function test_merge_all_endpoint_keeps_tags_when_ai_fails(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);

        Http::fake(function () {
            throw new \Exception('boom');
        });

        $this->post('tags/merge/all')->assertRedirect(route('tags.merge.index'));

        $this->assertSame(2, Tag::count());
    }

    public function test_merge_all_endpoint_ignores_unknown_names(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);

        $this->fakeAi([['云服务', '不存在的标签']], [0 => true]);

        $this->post('tags/merge/all')->assertRedirect(route('tags.merge.index'));

        $this->assertSame(2, Tag::count());
    }
}
