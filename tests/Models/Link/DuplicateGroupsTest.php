<?php

namespace Tests\Models\Link;

use App\Enums\ModelAttribute;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DuplicateGroupsTest extends TestCase
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

    public function test_normalized_key_strips_scheme_and_trailing_slash(): void
    {
        $link = Link::factory()->create(['url' => 'https://example.com/path/']);

        $this->assertEquals('example.com/path', $link->normalizedDuplicateKey());
    }

    public function test_normalized_key_includes_query_parameters(): void
    {
        $link = Link::factory()->create(['url' => 'https://example.com/path?x=1']);

        $this->assertEquals('example.com/path?x=1', $link->normalizedDuplicateKey());
    }

    public function test_normalized_key_is_null_for_broken_url(): void
    {
        $link = Link::factory()->create(['url' => 'example.com']);

        $this->assertNull($link->normalizedDuplicateKey());
    }

    public function test_duplicate_groups_detects_scheme_difference(): void
    {
        Link::factory()->create(['url' => 'https://example.com']);
        Link::factory()->create(['url' => 'http://example.com']);

        $groups = Link::duplicateGroupIds();

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups->first());
    }

    public function test_duplicate_groups_ignores_different_query_strings(): void
    {
        Link::factory()->create(['url' => 'https://example.com']);
        Link::factory()->create(['url' => 'https://example.com?x=1']);

        $groups = Link::duplicateGroupIds();

        $this->assertCount(0, $groups);
    }

    public function test_duplicate_groups_excludes_single_links(): void
    {
        Link::factory()->create(['url' => 'https://example.com']);
        Link::factory()->create(['url' => 'https://example.org']);

        $groups = Link::duplicateGroupIds();

        $this->assertCount(0, $groups);
    }

    public function test_duplicate_groups_excludes_other_users_private_links(): void
    {
        $otherUser = User::factory()->create();

        Link::factory()->create(['url' => 'https://example.com']);
        Link::factory()->create([
            'url' => 'https://example.com',
            'user_id' => $otherUser->id,
            'visibility' => ModelAttribute::VISIBILITY_PRIVATE,
        ]);

        $groups = Link::duplicateGroupIds();

        $this->assertCount(0, $groups);
    }

    public function test_duplicate_groups_detects_duplicates_across_chunks(): void
    {
        $duplicateUrl = 'https://chunk-test.example.com';

        Link::factory()->create(['url' => $duplicateUrl]);
        Link::factory()->count(510)->create();
        Link::factory()->create(['url' => $duplicateUrl]);

        $groups = Link::duplicateGroupIds();

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups->first());
    }
}
