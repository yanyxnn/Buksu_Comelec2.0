<?php

namespace Tests;

use App\Services\Auth\GoogleIdentityProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeGoogleIdentityProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite never talks to real Google. Every test starts with a fake
        // that has NO identity configured (i.e. it fails closed) and opts in
        // through googleLogin()/fakeGoogle().
        $this->app->instance(GoogleIdentityProvider::class, new FakeGoogleIdentityProvider);

        // Independent of whether front-end assets have been built.
        $this->withoutVite();
    }
}
