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
     *    in storage/crontab.owner.json and other users leave their crontab alone. When
     *    there is no record yet, storage/crontab.txt (written whenever the entry is added)
     *    tells which user added it before. If neither tells, and artisan has run here
     *    before, only root adds the entry.
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

        $lockCreated = !is_file($lockFile);
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            Log::warning('df-scheduler: could not open crontab lock file at ' . $lockFile);
            return;
        }
        if ($lockCreated) {
            $this->shareWithStorageOwner($lockFile);
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

            if ($configuredUser !== '') {
                if (!empty($owner) && $owner !== $user) {
                    Log::warning('df-scheduler: DF_SCHEDULER_CRON_USER is ' . $user . '; remove the schedule:run entry ' .
                        'from the crontab of ' . $owner . ' if it is still there so tasks start once a minute');
                }
            } else {
                if (empty($owner) && !$hasEntry) {
                    // No record for this host yet: if another user added the entry before,
                    // crontab.txt is theirs and holds it.
                    $previous = $this->previousCrontabUser($crontabFile, $cron);
                    if (!empty($previous) && $previous !== $user) {
                        $owner = $previous;
                        $owners[$host] = $owner;
                        $this->writeCrontabOwners($ownerFile, $owners);
                    }
                }

                if (empty($owner) && !$hasEntry && 'root' !== $user && !$lockCreated) {
                    // Artisan has run here before, but it is not known for which user the entry
                    // was added. Leave it to root, which can read the other crontabs.
                    Log::warning('df-scheduler: the schedule:run entry was not added for ' . $user .
                        '; run an artisan command as root once, or set DF_SCHEDULER_CRON_USER');
                    return;
                }

                if (!$hasEntry && !empty($owner) && $owner !== $user) {
                    // Another user on this host runs schedule:run already. root can check that
                    // the entry is still there and take over if it was removed.
                    if ('root' !== $user || Str::contains($this->readCrontab($owner), $cron)) {
                        return;
                    }
                }
            }

            if (!$hasEntry) {
                // Recreate the file so it belongs to the user whose crontab gets the entry.
                @unlink($crontabFile);
                if (file_put_contents($crontabFile, $output . PHP_EOL . $cron . PHP_EOL) === false) {
                    Log::error('df-scheduler: failed to write ' . $crontabFile);
                    return;
                }
                if (!$this->writeCrontab($crontabFile)) {
                    Log::warning('df-scheduler: could not add the schedule:run entry to the crontab of ' . $user);
                    return;
                }
            }

            if ($configuredUser === '' && $hasEntry && !empty($owner) && $owner !== $user) {
                Log::warning('df-scheduler: the crontabs of both ' . $owner . ' and ' . $user .
                    ' contain the schedule:run entry; remove one of them so tasks start once a minute');
            } elseif ($owner !== $user) {
                $owners[$host] = $user;
                $this->writeCrontabOwners($ownerFile, $owners);
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
     * Save the owner record. A file created by root is handed to the owner of the storage
     * directory, so artisan run as the web user can update it later.
     *
     * The record is kept per host name; entries of hosts that no longer exist are harmless.
     */
    protected function writeCrontabOwners(string $file, array $owners): void
    {
        $created = !is_file($file);
        if (false === @file_put_contents($file, json_encode($owners, JSON_PRETTY_PRINT) . PHP_EOL)) {
            Log::warning('df-scheduler: failed to write ' . $file);

            return;
        }
        if ($created) {
            $this->shareWithStorageOwner($file);
        }
    }

    /**
     * When running as root, give a newly created file to the owner of its directory
     * (the web user on a standard install) and make it group-writable.
     */
    protected function shareWithStorageOwner(string $file): void
    {
        if (!function_exists('posix_geteuid') || 0 !== posix_geteuid()) {
            return;
        }
        $dir = dirname($file);
        $uid = @fileowner($dir);
        $gid = @filegroup($dir);
        if (false !== $uid && false !== $gid) {
            @chown($file, $uid);
            @chgrp($file, $gid);
        }
        @chmod($file, 0664);
    }

    /**
     * OS user that owns $crontabFile, if the file holds the $cron line; null otherwise.
     */
    protected function previousCrontabUser(string $crontabFile, string $cron): ?string
    {
        if (!is_file($crontabFile) || !Str::contains((string)@file_get_contents($crontabFile), $cron)) {
            return null;
        }
        $uid = @fileowner($crontabFile);
        if (false === $uid || !function_exists('posix_getpwuid')) {
            return null;
        }
        $info = posix_getpwuid($uid);

        return empty($info['name']) ? null : $info['name'];
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
