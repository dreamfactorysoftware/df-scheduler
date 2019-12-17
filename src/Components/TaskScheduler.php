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

            $logFilePath = self::createTaskLogFile();

            app(Schedule::class)
                ->command('df:request \'' . $data . '\' ' . $commandOptions)
                ->cron('*/' . $task->frequency . ' * * * *')
                ->withoutOverlapping()
                ->sendOutputTo($logFilePath)
                ->onSuccess(function () use ($task, $logFilePath) {
                    if (TaskLog::whereTaskId($task->id)->exists()) {
                        TaskLog::whereTaskId($task->id)->first()->delete();
                    }
                    self::deleteTaskLogFile($logFilePath);
                })
                ->onFailure(function () use ($task, $logFilePath) {
                    try {
                        $taskLog = self::createTaskLog($task, $logFilePath);

                        if (TaskLog::whereTaskId($task->id)->exists()) {
                            self::updateTaskLog($task, $logFilePath);
                        } else {
                            $taskLog->save();
                        }
                        self::deleteTaskLogFile($logFilePath);
                    } catch (\Exception $e) {
                        \Log::error('Could not store failed scheduler task log: ' . $e->getMessage());
                    }
                });
        }
    }

    /**
     * @param SchedulerTask $task
     * @param $logFilePath
     * @return string
     */
    public static function createTaskLog(SchedulerTask $task, $logFilePath)
    {
        $taskId = $task->id;
        $errorContent = file_exists($logFilePath) ? file_get_contents($logFilePath) : '';
        $statusCode = self::getStatusCode($logFilePath);
        return new TaskLog(['task_id' => $taskId, 'status_code' => $statusCode, 'content' => $errorContent]);
    }

    /**
     * Get status code from error log
     *
     * @param string $fileName
     * @return float|int
     */
    public static function getStatusCode($fileName)
    {
        $lines = file($fileName);
        foreach ($lines as $lineNumber => $line) {
            if (strpos($line, 'statusCode') !== false) {
                return abs((int)filter_var($line, FILTER_SANITIZE_NUMBER_INT));
            }
        }
        return 500;
    }

    /**
     * @return string
     */
    public static function createTaskLogFile()
    {
        $dirPath = storage_path() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tmp';

        if (!file_exists($dirPath)) mkdir($dirPath, 0770, true);

        putenv('TMPDIR='.$dirPath);
        if ($logFile = tmpfile()) {
            return stream_get_meta_data($logFile)['uri'];
        } else {
            return $dirPath . DIRECTORY_SEPARATOR . uniqid('schedule', true) . '.log';
        }
    }

    /**
     * @param $task
     * @param $logFilePath
     * @return void
     */
    public static function updateTaskLog($task, $logFilePath)
    {
        $errorContent = file_exists($logFilePath) ? file_get_contents($logFilePath) : '';
        $statusCode = self::getStatusCode($logFilePath);
        $taskLog = TaskLog::whereTaskId($task->id)->first();
        $isSameError = Str::before($taskLog->content, "\n") === Str::before($errorContent, "\n");
        if ($taskLog->task_id === $task->id && $taskLog->status_code === $statusCode && $isSameError) {
            self::deleteTaskLogFile($logFilePath);
            return;
        }
        $taskLog->status_code = $statusCode;
        $taskLog->content = $errorContent;
        $taskLog->save();
    }

    /**
     * @param $logFilePath
     * @return void
     */
    public static function deleteTaskLogFile($logFilePath)
    {
        if (file_exists($logFilePath)) unlink($logFilePath);
    }
}