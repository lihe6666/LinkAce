<?php

namespace Tests\Controller\Models;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_index_view_lists_similar_groups(): void
    {
        $general = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        $specific = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);

        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $general->links()->attach($link->id);
        $specific->links()->attach($link->id);

        $this->get('tags/merge')
            ->assertOk()
            ->assertSee('云服务')
            ->assertSee('云服务器');
    }

    public function test_index_view_paginates_groups(): void
    {
        for ($i = 0; $i < 25; $i++) {
            Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'g' . $i . 'zz']);
            Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'g' . $i . 'zzy']);
        }

        $this->get('tags/merge')
            ->assertOk()
            ->assertSee('page=2');
    }

    public function test_merge_endpoint_merges_selected_tags(): void
    {
        $survivor = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        $victim = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        $victim->links()->attach($link->id);

        $this->post('tags/merge', [
            'survivor' => $survivor->id,
            'victims' => [$victim->id],
        ])->assertRedirect(route('tags.merge.index'));

        $this->assertSoftDeleted($victim);
        $this->assertSame(1, $survivor->fresh()->links()->count());
    }

    public function test_merge_endpoint_accepts_survivor_in_victims(): void
    {
        $survivor = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        $victim = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);

        $this->post('tags/merge', [
            'survivor' => $survivor->id,
            'victims' => [$survivor->id, $victim->id],
        ])->assertRedirect(route('tags.merge.index'));

        $this->assertSoftDeleted($victim);
    }

    public function test_merge_all_endpoint_merges_every_group(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'html']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'html5']);

        $this->post('tags/merge/all')->assertRedirect(route('tags.merge.index'));

        $this->assertSame(2, Tag::count());
        $this->assertSame(2, Tag::onlyTrashed()->count());
    }

    public function test_merge_endpoint_rejects_other_users_tags(): void
    {
        $otherUser = User::factory()->create();
        $survivor = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        $victim = Tag::factory()->create(['user_id' => $otherUser->id, 'name' => '云服务器']);

        $this->post('tags/merge', [
            'survivor' => $survivor->id,
            'victims' => [$victim->id],
        ])->assertForbidden();

        $this->assertDatabaseCount('tags', 2);
    }
}
