<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\CreatesSjiAuthTables;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot trait helper tambahan setelah aplikasi siap.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits(): array
    {
        $uses = parent::setUpTraits();

        if (isset($uses[CreatesSjiAuthTables::class])) {
            $this->setUpSjiAuthTables();
        }

        return $uses;
    }
}
