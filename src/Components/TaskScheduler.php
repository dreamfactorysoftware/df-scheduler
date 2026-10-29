<?php

namespace DreamFactory\Core\Scheduler\Components;

use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Scheduler\Models\TaskLog;
use DreamFactory\Core\Scheduler\Models\TaskRun;
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

        // Optional run-as identity: when set, the task runs under a real session
        // (role + lookups) instead of the legacy session-less context. Ints only,
        // so no shell-escaping concerns. The user is scoped to the app, so a
        // user_id is only meaningful alongside an app_id.
        if (!empty($task->app_id)) {
            $commandOptions .= ' --app-id=' . (int)$task->app_id;
            if (!empty($task->user_id)) {
                $commandOptions .= ' --user-id=' . (int)$task->user_id;
            }
        }

        // Use the scheduler to schedule the task at its desired frequency in minutes
        if ($task->is_active) {

            $logFilePath = self::createTaskLogFile();
            exec('pgrep cron', $output, $status);
            if (count($output) === 0) {
                $message = 'Make sure your cron daemon is running!';
                $taskLog = self::createTaskLog($task, $logFilePath, 500, $message);
                if (TaskLog::whereTaskId($task->id)->exists()) {
                    self::updateTaskLog($task, $logFilePath,500, $message);
                } else {
                    $taskLog->save();
                }
                self::deleteTaskLogFile($logFilePath);
                return;
            }

            $state = new \stdClass();

            app(Schedule::class)
                ->command('df:scheduled-request \'' . $data . '\' ' . $commandOptions)
                ->cron('*/' . $task->frequency . ' * * * *')
                ->withoutOverlapping()
                ->sendOutputTo($logFilePath)
                ->before(function () use ($task, $state) {
                    $state->run = self::startRun($task);
                })
                ->onSuccess(function () use ($task, $logFilePath, $state) {
                    self::finishRun($state->run ?? null, $logFilePath, true);
                    if (TaskLog::whereTaskId($task->id)->exists()) {
                        TaskLog::whereTaskId($task->id)->first()->delete();
                    }
                    self::deleteTaskLogFile($logFilePath);
                })
                ->onFailure(function () use ($task, $logFilePath, $state) {
                    self::finishRun($state->run ?? null, $logFilePath, false);
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
     * Open a run row; an older still-open row means its process died.
     *
     * @param SchedulerTask $task
     * @return TaskRun|null
     */
    public static function startRun(SchedulerTask $task)
    {
        try {
            TaskRun::whereTaskId($task->id)->whereNull('finished_at')
                ->update(['finished_at' => now(), 'status_code' => 500, 'output' => 'Run did not finish']);

            return TaskRun::create(['task_id' => $task->id, 'started_at' => now()]);
        } catch (\Exception $e) {
            \Log::error('Could not store scheduler task run: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Close a run row and prune old history. Never throws: history must not break the run.
     *
     * @param TaskRun|null $run
     * @param string $logFilePath
     * @param bool $ok
     */
    public static function finishRun($run, $logFilePath, $ok)
    {
        if (!$run) {
            return;
        }
        try {
            $output = file_exists($logFilePath) ? file_get_contents($logFilePath) : '';
            $run->finished_at = now();
            $run->status_code = strpos($output, 'statusCode') !== false
                ? self::getStatusCode($logFilePath) : ($ok ? 200 : 500);
            $run->output = mb_strcut($output, 0, TaskRun::OUTPUT_LIMIT);
            $run->save();

            $keep = TaskRun::whereTaskId($run->task_id)->orderByDesc('id')
                ->limit(TaskRun::KEEP_PER_TASK)->pluck('id');
            TaskRun::whereTaskId($run->task_id)->whereNotIn('id', $keep)->delete();
        } catch (\Exception $e) {
            \Log::error('Could not finish scheduler task run: ' . $e->getMessage());
        }
    }

    /**
     * @param SchedulerTask $task
     * @param $logFilePath
     * @param $message
     * @return TaskLog
     */
    public static function createTaskLog(SchedulerTask $task, $logFilePath, $statusCode = null, $message = null)
    {
        $taskId = $task->id;
        $errorContent = file_exists($logFilePath) ? file_get_contents($logFilePath) : '';
        $statusCode = $statusCode ?: self::getStatusCode($logFilePath);
        return new TaskLog(['task_id' => $taskId, 'status_code' => $statusCode, 'content' => !empty($message) ? $message : $errorContent]);
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

        putenv('TMPDIR=' . $dirPath);
        $logFile = tmpfile();
        if ($logFile) {
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
    public static function updateTaskLog($task, $logFilePath, $statusCode = null, $message = null)
    {
        $errorContent = file_exists($logFilePath) ? file_get_contents($logFilePath) : '';
        $statusCode = $statusCode ?: self::getStatusCode($logFilePath);
        $taskLog = TaskLog::whereTaskId($task->id)->first();
        $isSameError = Str::before($taskLog->content, "\n") === Str::before($errorContent, "\n");
        if ($taskLog->task_id === $task->id && $taskLog->status_code === $statusCode && $isSameError) {
            self::deleteTaskLogFile($logFilePath);
            return;
        }
        $taskLog->status_code = $statusCode;
        $taskLog->content = !empty($message) ? $message : $errorContent;
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
