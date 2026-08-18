<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The suite asserts on rendered markup, not on built assets. Without
         * this, every test that renders a page fails until `npm run build` has
         * been run — which makes a green suite a statement about the last build
         * rather than about the code.
         */
        $this->withoutVite();
    }
}
