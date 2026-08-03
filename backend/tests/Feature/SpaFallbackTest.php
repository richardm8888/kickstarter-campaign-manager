<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaFallbackTest extends TestCase
{
    public function test_client_side_routes_fall_back_to_the_app_shell(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/projects/1/dashboard-view')->assertOk();
    }

    public function test_unknown_api_routes_still_return_404(): void
    {
        $this->getJson('/api/definitely-not-a-route')->assertNotFound();
    }
}
