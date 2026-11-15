<?php

namespace Tests\Models\Tag;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SimilarGroupsTest extends TestCase
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

    public function test_similar_groups_detects_containment(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);

        $groups = Tag::similarGroups();

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups->first());
    }

    public function test_similar_groups_groups_normalized_variants(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'Docker']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => ' docker ']);

        $this->assertCount(1, Tag::similarGroups());
    }

    public function test_similar_groups_ignores_unrelated_tags(): void
    {
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'docker']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'rust']);
        Tag::factory()->create(['user_id' => $this->user->id, 'name' => 'frontend']);

        $this->assertCount(0, Tag::similarGroups());
    }

    public function test_similar_groups_excludes_other_users_tags(): void
    {
        $otherUser = User::factory()->create();

        Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        Tag::factory()->create(['user_id' => $otherUser->id, 'name' => '云服务器']);

        $this->assertCount(0, Tag::similarGroups());
    }

    public function test_similar_groups_orders_survivor_by_link_count(): void
    {
        $small = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务器']);
        $big = Tag::factory()->create(['user_id' => $this->user->id, 'name' => '云服务']);
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        $big->links()->attach($link->id);
        $small->links()->attach($link->id);

        $group = Tag::similarGroups()->first();

        $this->assertTrue($group->first()->is($big));
    }

    public function test_names_similar_matches_prefix_and_typo(): void
    {
        $this->assertTrue(Tag::namesSimilar('云服务', '云服务器'));
        $this->assertTrue(Tag::namesSimilar('docker', 'Docker'));
        $this->assertTrue(Tag::namesSimilar('nodejs', 'node.js'));
        $this->assertFalse(Tag::namesSimilar('docker', 'kubernetes'));
    }

    public function test_names_similar_ignores_short_names(): void
    {
        $this->assertTrue(Tag::namesSimilar('AI', 'ai'));
        $this->assertFalse(Tag::namesSimilar('ai', 'ui'));
        $this->assertFalse(Tag::namesSimilar('api', 'app'));
        $this->assertFalse(Tag::namesSimilar('sql', 'mysql'));
        $this->assertFalse(Tag::namesSimilar('git', 'github'));
    }

    public function test_names_similar_ignores_single_character_typos_in_short_words(): void
    {
        $this->assertFalse(Tag::namesSimilar('wifi', 'wiki'));
        $this->assertFalse(Tag::namesSimilar('swift', 'swoft'));
        $this->assertTrue(Tag::namesSimilar('opensource', 'open-source'));
    }

    public function test_names_similar_requires_close_containment(): void
    {
        $this->assertTrue(Tag::namesSimilar('云服务', '云服务器'));
        $this->assertFalse(Tag::namesSimilar('rust', 'rust-lang'));
        $this->assertFalse(Tag::namesSimilar('docker', 'docker-compose'));
    }

    public function test_similar_groups_does_not_chain_through_generic_tags(): void
    {
        foreach (['web', 'web开发', 'web框架', 'web应用', 'web安全'] as $name) {
            Tag::factory()->create(['user_id' => $this->user->id, 'name' => $name]);
        }

        // Only "web" is similar to every other name, the others differ from
        // each other, so no group may contain more than two tags.
        foreach (Tag::similarGroups() as $group) {
            $this->assertLessThanOrEqual(2, $group->count());
        }
    }
}
