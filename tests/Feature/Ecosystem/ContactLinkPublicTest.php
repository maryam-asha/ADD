<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\ContactLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactLinkPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_read_excludes_hidden_links_and_orders_by_sort_order(): void
    {
        $hidden = ContactLink::create(['type' => 'phone', 'value' => '+963000000', 'sort_order' => 0, 'is_visible' => false]);
        $second = ContactLink::create(['type' => 'website', 'value' => 'https://example.com', 'sort_order' => 2, 'is_visible' => true]);
        $first = ContactLink::create(['type' => 'email', 'value' => 'hi@example.com', 'sort_order' => 1, 'is_visible' => true]);

        $response = $this->getJson('/api/v1/contact-links');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $second->id);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_public_read_requires_no_authentication(): void
    {
        ContactLink::create(['type' => 'email', 'value' => 'hi@example.com']);

        $this->getJson('/api/v1/contact-links')->assertOk();
    }
}
