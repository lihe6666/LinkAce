<?php

namespace Tests\Models\Tag;

use App\Models\Tag;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NameSimilarityTest extends TestCase
{
    use DatabaseMigrations;
    use DatabaseTransactions;

    public function test_matches_prefix_and_typos(): void
    {
        $this->assertTrue(Tag::namesSimilar('云服务', '云服务器'));
        $this->assertTrue(Tag::namesSimilar('docker', 'Docker'));
        $this->assertTrue(Tag::namesSimilar('nodejs', 'node.js'));
        $this->assertTrue(Tag::namesSimilar('opensource', 'open-source'));
        $this->assertFalse(Tag::namesSimilar('docker', 'kubernetes'));
    }

    public function test_ignores_short_names(): void
    {
        $this->assertTrue(Tag::namesSimilar('AI', 'ai'));
        $this->assertFalse(Tag::namesSimilar('ai', 'ui'));
        $this->assertFalse(Tag::namesSimilar('api', 'app'));
        $this->assertFalse(Tag::namesSimilar('sql', 'mysql'));
        $this->assertFalse(Tag::namesSimilar('git', 'github'));
    }

    public function test_ignores_single_character_typos_in_short_words(): void
    {
        $this->assertFalse(Tag::namesSimilar('wifi', 'wiki'));
        $this->assertFalse(Tag::namesSimilar('swift', 'swoft'));
    }

    public function test_requires_close_containment(): void
    {
        $this->assertTrue(Tag::namesSimilar('云服务', '云服务器'));
        $this->assertFalse(Tag::namesSimilar('rust', 'rust-lang'));
        $this->assertFalse(Tag::namesSimilar('docker', 'docker-compose'));
    }
}
