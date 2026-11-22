<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_shows_public_landing_page_to_guest(): void
    {
        $this->get('/')->assertOk()->assertViewIs('landing');
    }
}
