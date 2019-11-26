<?php

namespace DreamFactory\Core\Scheduler\Components;

use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use Illuminate\Console\Scheduling\Schedule;

class TaskScheduler
{
    /**
     * Schedule a task
     *
     * @param SchedulerTask $task
     * @throws \DreamFactory\Core\Exceptions\NotImplementedException
     */
    public static function schedule(SchedulerTask $task)
    {
        if (empty($task->payload)) {
            $data = "";
        } else {
            $data = json_encode(json_decode($task->payload));
        }
        $verb = VerbsMask::toString($task->verb_mask);
        $serviceName = \ServiceManager::getServiceById($task->service_id)->getName();
        $component = $task->component;
        $commandOptions = '--format=JSON --verb=' . $verb . ' --service=' . $serviceName . ' --resource=' . $component;

        // Use the scheduler to schedule the task at its desired frequency in minutes
        if ($task->is_active) {
            app(Schedule::class)
                ->command('df:request \'' . $data . '\' ' . $commandOptions)
                ->cron('*/' . $task->frequency . ' * * * *')
                ->withoutOverlapping()
//                ->appendOutputTo(storage_path() . '/scheduled-tasks-responses.log')
                ->onFailure(function () use ($task) {
                });
        }
    }
}