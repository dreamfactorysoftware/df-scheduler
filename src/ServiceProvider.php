<?php

namespace DreamFactory\Core\Scheduler;

use DreamFactory\Core\Scheduler\Commands\ScheduleListCommand;
use DreamFactory\Core\Scheduler\Commands\ScheduledRequest;
use DreamFactory\Core\Scheduler\Components\TaskScheduler;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Scheduler\Models\TaskLog;
use DreamFactory\Core\Scheduler\Resources\System\SchedulerResource;
use DreamFactory\Core\Models\SystemTableModelMapper;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\System\Components\SystemResourceType;
use Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        // add migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Install the host crontab entry. This used to run on every boot
        // (every HTTP request, every queue tick), which:
        //  1. raced multiple workers writing the same crontab.txt,
        //  2. ran shell_exec/exec on every request — DoS amplifier,
        //  3. interpolated storage_path() into a shell command unquoted.
        // We now only run from the CLI, behind an exclusive file lock,
        // and pass the path with escapeshellarg.
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            $this->installCrontabEntry();
        }

        $this->commands([
            ScheduleListCommand::class,
            ScheduledRequest::class,
        ]);

        $this->app->booted(function () {
            $this->scheduleTasks();
        });
    }

    protected function installCrontabEntry(): void
    {
        $projectPath = base_path() . '/';
        $cron = "* * * * * cd " . $projectPath . " && php artisan schedule:run >> /dev/null 2>&1";
        $crontabFile = storage_path() . '/crontab.txt';
        $lockFile = storage_path() . '/crontab.lock';

        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            Log::warning('df-scheduler: could not open crontab lock file at ' . $lockFile);
            return;
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                // Another process is already installing the cron entry.
                return;
            }

            $output = shell_exec('crontab -l 2>/dev/null') ?? '';

            if (!Str::contains($output, $cron)) {
                if (file_put_contents($crontabFile, $output . PHP_EOL . $cron . PHP_EOL) === false) {
                    Log::error('df-scheduler: failed to write ' . $crontabFile);
                    return;
                }
                exec('crontab ' . escapeshellarg($crontabFile));
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

        // Add our table model mapping
        $this->app->resolving('df.system.table_model_map', function (SystemTableModelMapper $df) {
            $df->addMapping('task_log', TaskLog::class);
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
