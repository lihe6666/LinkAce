<?php

namespace Tests\Models;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use App\Repositories\TagRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TagMergeTest extends TestCase
{
    use DatabaseMigrations;
    use DatabaseTransactions;

    public function test_merge_moves_links_and_deletes_victims(): void
    {
        $user = User::factory()->create();
        $survivor = Tag::factory()->create(['user_id' => $user->id, 'name' => '云服务']);
        $victim = Tag::factory()->create(['user_id' => $user->id, 'name' => '云服务器']);

        $linkA = Link::factory()->create(['user_id' => $user->id]);
        $linkB = Link::factory()->create(['user_id' => $user->id]);
        $linkC = Link::factory()->create(['user_id' => $user->id]);

        $survivor->links()->attach($linkA);
        $victim->links()->attach($linkA); // overlap must not duplicate
        $victim->links()->attach([$linkB->id, $linkC->id]);

        $merged = TagRepository::merge($survivor, collect([$victim]));

        $this->assertSame(
            [$linkA->id, $linkB->id, $linkC->id],
            $merged->links()->pluck('links.id')->sort()->values()->all()
        );
        $this->assertSoftDeleted($victim);
        $this->assertDatabaseCount('link_tags', 3);
    }

    public function test_merge_skips_the_survivor_itself(): void
    {
        $user = User::factory()->create();
        $survivor = Tag::factory()->create(['user_id' => $user->id, 'name' => '云服务']);
        $link = Link::factory()->create(['user_id' => $user->id]);
        $survivor->links()->attach($link->id);

        $merged = TagRepository::merge($survivor, collect([$survivor]));

        $this->assertSame(1, $merged->links()->count());
        $this->assertDatabaseCount('link_tags', 1);
    }
}
