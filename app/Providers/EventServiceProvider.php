<?php

namespace Modules\DeployEnv\app\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\DeployEnv\app\Events\DeployEnvFormer;
use Modules\DeployEnv\app\Events\ImportRow;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        DeployEnvFormer::class              => [
            \Modules\DeployEnv\app\Listeners\DeployEnvFormer::class,
        ],
        ImportRow::class=>[
            \Modules\DeployEnv\app\Listeners\ImportRow::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
