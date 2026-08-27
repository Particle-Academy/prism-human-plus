<?php

declare(strict_types=1);

namespace Prism\HumanPlus;

use Illuminate\Support\ServiceProvider;
use Prism\HumanPlus\Contracts\AttachmentStore;
use Prism\HumanPlus\Security\ResultGuard;
use Prism\HumanPlus\Security\TrustPolicy;
use Prism\HumanPlus\Stores\LaravelAttachmentStore;

final class PrismHumanPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttachmentStore::class, LaravelAttachmentStore::class);
        $this->app->singleton(ResultGuard::class, fn (): ResultGuard => new ResultGuard);
        $this->app->singleton(TrustPolicy::class, fn (): TrustPolicy => TrustPolicy::undeclared());
    }
}
