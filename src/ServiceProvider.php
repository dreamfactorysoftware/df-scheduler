<?php
namespace DreamFactory\Core\Scheduler;

use DreamFactory\Core\Compliance\Handlers\Events\EventHandler;
use DreamFactory\Core\Scheduler\Http\Middleware\ExampleMiddleware;
use DreamFactory\Core\Scheduler\Models\ExampleConfig;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Scheduler\Resources\SchedulerService;
use Illuminate\Routing\Router;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        // add migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function register()
    {
        // Add our service types.
        $this->app->resolving('df.service', function (SystemResourceManager $df) {
            $df->addType(
                new SystemResourceType([
                    'name'                  => 'scheduler',
                    'label'                 => 'Scheduler Service',
                    'description'           => 'Schedule tasks',
                    'subscription_required' => LicenseLevel::GOLD,
                    'class_name'            => SchedulerService::class,
                ])
            );
        });
    }
}
