<?php

namespace DreamFactory\Core\Scheduler;

use DreamFactory\Core\Scheduler\Commands\ScheduleListCommand;
use DreamFactory\Core\Scheduler\Components\TaskScheduler;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Scheduler\Resources\System\SchedulerResource;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\System\Components\SystemResourceType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        // add migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Add cron job
        $projectPath = base_path() . '/';
        $cron = "* * * * * cd " . $projectPath . " && php artisan schedule:run >> /dev/null 2>&1";
        $output = shell_exec('crontab -l');
        if (!Str::contains($output, $cron)) {
            file_put_contents(storage_path() . '/crontab.txt', $output . $cron . PHP_EOL);
            exec('crontab ' . $projectPath . 'crontab.txt');
        }

//        todo: check cron service status in middleware $output = shell_exec('service cron status');

        $this->commands([
            ScheduleListCommand::class,
        ]);

        $this->app->booted(function () {
            $this->scheduleTasks();
        });
    }

    public function register()
    {
        $this->app->resolving('df.system.resource', function (SystemResourceManager $df) {
            $df->addType(
                new SystemResourceType([
                    'name'                  => 'scheduler',
                    'label'                 => 'Scheduler Service',
                    'description'           => 'Scheduled tasks',
                    'class_name'            => SchedulerResource::class,
                    'subscription_required' => LicenseLevel::GOLD,
                    'singleton'             => false,
                    'read_only'             => false,
                ])
            );
        });
    }

    /**
     * Trigger task scheduling
     *
     * @throws \DreamFactory\Core\Exceptions\NotImplementedException
     */
    public function scheduleTasks()
    {
        if (Schema::hasTable(with(new SchedulerTask)->getTable())) {
            $tasks = SchedulerTask::all();

            foreach ($tasks as $task) {
                TaskScheduler::schedule($task);
            }
        }
    }
}
