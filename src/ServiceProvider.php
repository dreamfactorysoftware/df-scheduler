<?php

namespace DreamFactory\Core\Scheduler;

use DreamFactory\Core\Scheduler\Commands\ScheduleListCommand;
use DreamFactory\Core\Scheduler\Commands\ScheduledRequest;
use DreamFactory\Core\Scheduler\Components\TaskScheduler;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Scheduler\Models\TaskLog;
use DreamFactory\Core\Scheduler\Models\TaskRun;
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

        // Install the host crontab entry for schedule:run. This only runs from the CLI,
        // behind a file lock, and for a single OS user per host (see installCrontabEntry).
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

    /**
     * Add the schedule:run entry to the crontab of one OS user on this host.
     *
     * Artisan may be run by several OS users (root during an image build, the web user
     * from a shell, and so on). Each user has its own crontab, so adding the entry for
     * every one of them would start schedule:run more than once a minute. Instead:
     *  - DF_SCHEDULER_INSTALL_CRON=false turns the automatic entry off.
     *  - DF_SCHEDULER_CRON_USER=<user> adds it only when artisan runs as that user.
     *  - Otherwise the first user that has, or adds, the entry on this host is recorded
     *    in storage/crontab.owner.json and other users leave their crontab alone.
     */
    protected function installCrontabEntry(): void
    {
        if (!filter_var(config('df-scheduler.install_cron', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $user = $this->currentOsUser();
        if (empty($user)) {
            return;
        }

        $configuredUser = trim((string)config('df-scheduler.cron_user', ''));
        if ($configuredUser !== '' && $configuredUser !== $user) {
            return;
        }

        $projectPath = base_path() . '/';
        $cron = "* * * * * cd " . $projectPath . " && php artisan schedule:run >> /dev/null 2>&1";
        $crontabFile = storage_path() . '/crontab.txt';
        $lockFile = storage_path() . '/crontab.lock';
        $ownerFile = storage_path() . '/crontab.owner.json';
        $host = (string)gethostname();

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

            $owners = $this->readCrontabOwners($ownerFile);
            $owner = $owners[$host] ?? null;
            $output = $this->readCrontab();
            $hasEntry = Str::contains($output, $cron);

            if (!$hasEntry && $configuredUser === '' && !empty($owner) && $owner !== $user) {
                // Another user on this host runs schedule:run already. root can check that
                // the entry is still there and take over if it was removed.
                if ('root' !== $user || Str::contains($this->readCrontab($owner), $cron)) {
                    return;
                }
            }

            if (!$hasEntry) {
                if (file_put_contents($crontabFile, $output . PHP_EOL . $cron . PHP_EOL) === false) {
                    Log::error('df-scheduler: failed to write ' . $crontabFile);
                    return;
                }
                if (!$this->writeCrontab($crontabFile)) {
                    Log::warning('df-scheduler: could not add the schedule:run entry to the crontab of ' . $user);
                    return;
                }
            }

            if ($hasEntry && !empty($owner) && $owner !== $user) {
                Log::warning('df-scheduler: the crontabs of both ' . $owner . ' and ' . $user .
                    ' contain the schedule:run entry; remove one of them so tasks start once a minute');
            } elseif ($owner !== $user) {
                $owners[$host] = $user;
                if (false === @file_put_contents($ownerFile, json_encode($owners, JSON_PRETTY_PRINT) . PHP_EOL)) {
                    Log::warning('df-scheduler: failed to write ' . $ownerFile);
                }
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Name of the OS user this process runs as, or null if it cannot be determined.
     */
    protected function currentOsUser(): ?string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (!empty($info['name'])) {
                return $info['name'];
            }
        }
        $name = trim((string)shell_exec('id -un 2>/dev/null'));

        return '' === $name ? null : $name;
    }

    /**
     * @return array<string, string> host name => OS user that owns the schedule:run entry
     */
    protected function readCrontabOwners(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $owners = json_decode((string)@file_get_contents($file), true);

        return is_array($owners) ? array_filter($owners, 'is_string') : [];
    }

    /**
     * Crontab of the current OS user, or of $user (reading another user's needs root).
     */
    protected function readCrontab(?string $user = null): string
    {
        $command = 'crontab ' . (null === $user ? '' : '-u ' . escapeshellarg($user) . ' ') . '-l 2>/dev/null';

        return (string)shell_exec($command);
    }

    /**
     * Replace the current OS user's crontab with the contents of $crontabFile.
     */
    protected function writeCrontab(string $crontabFile): bool
    {
        exec('crontab ' . escapeshellarg($crontabFile), $ignored, $status);

        return 0 === $status;
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/df-scheduler.php', 'df-scheduler');

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
            $df->addMapping('task_run', TaskRun::class);
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
