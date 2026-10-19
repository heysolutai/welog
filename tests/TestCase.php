<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // As telas Inertia passam pelo layout com @vite. Sem isto a suíte só
        // roda onde já existe `public/build/manifest.json`: passava na máquina
        // de quem desenvolve e quebrava num clone limpo (CI). Quem valida o
        // build do frontend é o job de assets, não o teste de backend.
        $this->withoutVite();
    }
}
