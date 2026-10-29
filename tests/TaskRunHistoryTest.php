<?php

namespace DreamFactory\Core\Scheduler\Tests;

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Scheduler\Components\TaskScheduler;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Scheduler\Models\TaskLog;
use DreamFactory\Core\Scheduler\Models\TaskRun;
use DreamFactory\Core\Testing\TestCase;
use Session;

class TaskRunHistoryTest extends TestCase
{
    protected $serviceId = 'system';

    private SchedulerTask $task;

    private array $files = [];

    public function setUp(): void
    {
        parent::setUp();

        Session::put('user.is_sys_admin', true);
        $this->task = $this->makeTask();
    }

    public function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        SchedulerTask::whereKey($this->task->id)->delete();
        parent::tearDown();
    }

    private function makeTask(): SchedulerTask
    {
        return SchedulerTask::create([
            'name'       => 'run-history-' . uniqid(),
            'service_id' => Service::whereName('system')->value('id'),
            'component'  => 'admin',
            'verb_mask'  => 1,
            'frequency'  => 1,
        ]);
    }

    private function logFile(string $content): string
    {
        $f = tempnam(sys_get_temp_dir(), 'run');
        file_put_contents($f, $content);
        return $this->files[] = $f;
    }

    private function statusFile(int $code): string
    {
        return $this->logFile("Exception Object\n(\n    [statusCode:protected] => $code\n)\n");
    }

    public function testSuccessfulRunIsRecorded()
    {
        $run = TaskScheduler::startRun($this->task);
        TaskScheduler::finishRun($run, $this->statusFile(200), true);

        $rows = TaskRun::whereTaskId($this->task->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]->started_at);
        $this->assertNotNull($rows[0]->finished_at);
        $this->assertEquals(200, $rows[0]->status_code);
        $this->assertStringContainsString('statusCode', $rows[0]->output);
        $this->assertFalse(TaskLog::whereTaskId($this->task->id)->exists());
    }

    public function testFailedRunIsRecordedAndTaskLogUnchanged()
    {
        $file = $this->statusFile(404);
        $run = TaskScheduler::startRun($this->task);
        TaskScheduler::finishRun($run, $file, false);
        // the existing failure path, as schedule()'s onFailure runs it
        TaskScheduler::createTaskLog($this->task, $file)->save();

        $this->assertEquals(404, TaskRun::whereTaskId($this->task->id)->first()->status_code);
        $this->assertEquals(404, TaskLog::whereTaskId($this->task->id)->first()->status_code);
    }

    public function testRunningStateExposed()
    {
        $run = TaskScheduler::startRun($this->task);
        $this->assertTrue($this->task->run_status['is_running']);

        TaskScheduler::finishRun($run, $this->statusFile(200), true);
        $this->assertFalse($this->task->run_status['is_running']);

        TaskRun::create(['task_id' => $this->task->id, 'started_at' => now()->subDays(2)]);
        $this->assertFalse($this->task->run_status['is_running'], 'stale open run must time out');
    }

    public function testLastSuccessAndFailureKeptSeparately()
    {
        TaskScheduler::finishRun(TaskScheduler::startRun($this->task), $this->statusFile(200), true);
        TaskScheduler::finishRun(TaskScheduler::startRun($this->task), $this->statusFile(500), false);

        $s = $this->task->run_status;
        $this->assertEquals(500, $s['last_run']['status_code']);
        $this->assertEquals(200, $s['last_success']['status_code']);
        $this->assertEquals(500, $s['last_failure']['status_code']);
        $this->assertArrayNotHasKey('output', $s['last_run']);
    }

    public function testNeverRunTask()
    {
        $s = $this->task->run_status;
        $this->assertFalse($s['is_running']);
        $this->assertNull($s['last_run']);
    }

    public function testStartClosesAbandonedRun()
    {
        $first = TaskScheduler::startRun($this->task);
        TaskScheduler::startRun($this->task);

        $first->refresh();
        $this->assertNotNull($first->finished_at);
        $this->assertEquals(500, $first->status_code);
    }

    public function testRetentionCap()
    {
        $other = $this->makeTask();
        TaskRun::create(['task_id' => $other->id, 'started_at' => now()]);
        for ($i = 0; $i < TaskRun::KEEP_PER_TASK + 5; $i++) {
            TaskRun::create(['task_id' => $this->task->id, 'started_at' => now(), 'finished_at' => now(), 'status_code' => 200]);
        }
        $run = TaskScheduler::startRun($this->task);
        TaskScheduler::finishRun($run, $this->statusFile(200), true);

        $this->assertEquals(TaskRun::KEEP_PER_TASK, TaskRun::whereTaskId($this->task->id)->count());
        $this->assertTrue(TaskRun::whereKey($run->id)->exists(), 'newest run kept');
        $this->assertEquals(1, TaskRun::whereTaskId($other->id)->count());
        SchedulerTask::whereKey($other->id)->delete();
    }

    public function testOutputTruncated()
    {
        $run = TaskScheduler::startRun($this->task);
        TaskScheduler::finishRun($run, $this->logFile(str_repeat('x', 100 * 1024)), true);

        $this->assertLessThanOrEqual(TaskRun::OUTPUT_LIMIT, strlen($run->refresh()->output));
    }

    public function testScheduleWiresRunHooks()
    {
        // schedule() bails early when no cron daemon runs in the test container
        $src = file_get_contents(__DIR__ . '/../src/Components/TaskScheduler.php');
        $this->assertMatchesRegularExpression('/->before\(.*startRun/s', $src);
        $this->assertEquals(2, preg_match_all('/self::finishRun\(/', $src));
    }

    public function testApiExposesRunStatusAndHistory()
    {
        TaskScheduler::finishRun(TaskScheduler::startRun($this->task), $this->statusFile(200), true);

        $rs = $this->makeRequest(Verbs::GET, 'scheduler/' . $this->task->id, [ApiOptions::RELATED => 'task_run_by_task_id']);
        $this->assertEquals(200, $rs->getStatusCode());
        $content = $rs->getContent();
        $this->assertArrayHasKey('run_status', $content);
        $this->assertCount(1, $content['task_run_by_task_id']);
    }
}
