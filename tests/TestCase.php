<?php

namespace Tests;

use App\Identity\IdentityProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\FakeIdentityProvider;

abstract class TestCase extends BaseTestCase
{
    protected function fakeIdentityProvider(bool $manages = true): FakeIdentityProvider
    {
        $fake = new FakeIdentityProvider($manages);
        $this->app->instance(IdentityProvider::class, $fake);

        return $fake;
    }
}
