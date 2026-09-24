<?php

namespace Tests\Controller\App;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_duplicates_page_renders(): void
    {
        $this->get('duplicates')
            ->assertOk()
            ->assertSee('Duplicates');
    }

    public function test_duplicates_page_shows_no_duplicates_message(): void
    {
        $this->get('duplicates')
            ->assertOk()
            ->assertSee('No duplicate links found.');
    }

    public function test_duplicates_page_shows_duplicate_links(): void
    {
        Link::create(['user_id' => $this->user->id, 'url' => 'https://example.com', 'title' => 'First Link']);
        Link::create(['user_id' => $this->user->id, 'url' => 'http://example.com', 'title' => 'Second Link']);

        $this->get('duplicates')
            ->assertOk()
            ->assertSee('First Link')
            ->assertSee('Second Link')
            ->assertDontSee('No duplicate links found.');
    }

    public function test_duplicates_page_paginates(): void
    {
        for ($i = 0; $i < 51; $i++) {
            Link::factory()->create(['url' => "https://dup{$i}.example.com", 'title' => "Duplicate {$i}"]);
            Link::factory()->create(['url' => "https://dup{$i}.example.com", 'title' => "Duplicate {$i} again"]);
        }

        $this->get('duplicates')
            ->assertOk()
            ->assertSee('page=2', false);

        $this->get('duplicates?page=2')
            ->assertOk();
    }
}
