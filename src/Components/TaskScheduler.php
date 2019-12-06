<?php

namespace DreamFactory\Core\Scheduler\Components;

use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Scheduler\Models\TaskLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

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
            $data = '';
        } else {
            $data = json_encode(json_decode($task->payload));
        }

        $verb = VerbsMask::toString($task->verb_mask);
        $serviceName = \ServiceManager::getServiceById($task->service_id)->getName();
        $component = $task->component;
        $commandOptions = '--format=JSON --verb=' . $verb . ' --service=' . $serviceName . ' --resource=' . $component;

        // Use the scheduler to schedule the task at its desired frequency in minutes
        if ($task->is_active) {

            $dirPath = storage_path() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tmp';

            if (!file_exists($dirPath)) mkdir($dirPath, 0777, true);

            if ($logFile = self::createTaskLogFile($dirPath)) {
                $logFilePath = stream_get_meta_data($logFile)['uri'];
            } else {
                $logFilePath = $dirPath . DIRECTORY_SEPARATOR . uniqid('schedule', true) . '.log';;
            }

            app(Schedule::class)
                ->command('df:request \'' . $data . '\' ' . $commandOptions)
                ->cron('*/' . $task->frequency . ' * * * *')
                ->withoutOverlapping()
                ->sendOutputTo($logFilePath)
                ->onSuccess(function () use ($task) {
                    if (TaskLog::whereTaskId($task->id)->exists()) {
                        TaskLog::whereTaskId($task->id)->first()->delete();
                    }
                })
                ->onFailure(function () use ($task, $logFilePath) {
                    try {
                        $taskId = $task->id;
                        $errorContent = file_exists($logFilePath) ? file_get_contents($logFilePath) : '';
                        $statusCode = self::getStatusCode($logFilePath);
                        $taskLog = new TaskLog(['task_id' => $taskId, 'status_code' => $statusCode, 'content' => $errorContent]);

                        if (!TaskLog::whereTaskId($taskId)->exists()) {
                            $taskLog->save();
                        } else {
                            $taskLog = TaskLog::whereTaskId($taskId)->first();
                            $isSameError = Str::before($taskLog->content, "\n") === Str::before($errorContent, "\n");
                            if ($taskLog->task_id === $taskId && $taskLog->status_code === $statusCode && $isSameError) {
                                if (file_exists($logFilePath)) unlink($logFilePath);
                                return;
                            }
                            $taskLog->status_code = $statusCode;
                            $taskLog->content = $errorContent;
                            $taskLog->save();
                        }
                        if (file_exists($logFilePath)) unlink($logFilePath);
                    } catch (\Exception $e) {
                        \Log::error('Could not store failed scheduler task log: ' . $e->getMessage());
                    }
                });

            // close and remove log file after scheduling
            if (file_exists($logFilePath)) fclose($logFile);
        }
    }

    /**
     * Get status code from error log
     *
     * @param string $fileName
     * @return float|int|string
     */
    private static function getStatusCode($fileName)
    {
        $lines = file($fileName);
        foreach ($lines as $lineNumber => $line) {
            if (strpos($line, 'statusCode') !== false) {
                return abs((int)filter_var($line, FILTER_SANITIZE_NUMBER_INT));
            }
        }
        return '500';
    }

    /**
     * @param $dirPath
     * @return array
     */
    private static function createTaskLogFile($dirPath)
    {
        putenv('TMPDIR='.$dirPath);
        return tmpfile();
    }
}