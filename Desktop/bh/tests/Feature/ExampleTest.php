<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_login_page_is_available_to_guests(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }
}
