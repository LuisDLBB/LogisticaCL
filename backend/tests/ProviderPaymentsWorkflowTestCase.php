<?php

namespace Tests;

use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\RecordUserActivity;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Session\Middleware\AuthenticateSession;

abstract class ProviderPaymentsWorkflowTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([Authenticate::class, AuthenticateSession::class, EnsurePortalAccess::class, RecordUserActivity::class]);
    }
}
