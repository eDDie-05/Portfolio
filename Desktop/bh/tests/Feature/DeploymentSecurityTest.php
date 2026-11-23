<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeding_does_not_create_default_login_accounts(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 0);
    }
}
