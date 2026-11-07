<?php

namespace Tests\Feature;

use Tests\TestCase;

class InfoApiTest extends TestCase
{
    public function test_info_is_public_and_only_names_the_portal(): void
    {
        config(['app.name' => 'Narrenhaus-Konto']);

        $this->getJson('/api/info')
            ->assertOk()
            ->assertExactJson([
                'name' => 'Narrenhaus-Konto',
                'households_url' => route('households.index'),
            ]);
    }

    public function test_info_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/info')->assertOk();
        }

        $this->getJson('/api/info')->assertTooManyRequests();
    }
}
