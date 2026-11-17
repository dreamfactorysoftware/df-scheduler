<?php

namespace DreamFactory\Core\Scheduler\Tests;

use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Scheduler\Components\TaskScheduler;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Scheduler\Models\TaskRun;
use DreamFactory\Core\Testing\TestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;

class OverlapLockTest extends TestCase
{
    private array $tasks = [];

    public function tearDown(): void
    {
        foreach ($this->tasks as $task) {
            SchedulerTask::whereKey($task->id)->delete();
        }
        parent::tearDown();
    }

    private function makeTask(int $frequency): SchedulerTask
    {
        return $this->tasks[] = SchedulerTask::create([
            'name'       => 'overlap-lock-' . uniqid(),
            'service_id' => Service::whereName('system')->value('id'),
            'component'  => 'environment',
            'verb_mask'  => 1,
            'frequency'  => $frequency,
            'is_active'  => true,
        ]);
    }

    private function scheduledEvent(SchedulerTask $task): Event
    {
        $found = null;
        foreach (app(Schedule::class)->events() as $event) {
            if ($event->expression === '*/' . $task->frequency . ' * * * *'
                && str_contains($event->command, '--resource=' . $task->component)) {
                $found = $event;
            }
        }
        $this->assertNotNull($found, 'task was not scheduled');

        return $found;
    }

    public static function frequencies(): array
    {
        return [
            'every minute'      => [1, 10],
            'every 2 minutes'   => [2, 10],
            'every 5 minutes'   => [5, 10],
            'every 6 minutes'   => [6, 12],
            'every 15 minutes'  => [15, 30],
            'every 30 minutes'  => [30, 60],
            'every 45 minutes'  => [45, 90],
            'hourly'            => [60, 120],
            'step past an hour' => [90, 120],
            'unset'             => [0, 10],
        ];
    }

    #[DataProvider('frequencies')]
    public function testLockLengthFollowsFrequency(int $frequency, int $minutes)
    {
        $this->assertSame($minutes, (new SchedulerTask(['frequency' => $frequency]))->overlapLockMinutes());
    }

    public function testScheduledEventUsesTaskLockLength()
    {
        foreach ([1, 30] as $frequency) {
            $task = $this->makeTask($frequency);
            CronUpScheduler::schedule($task);

            $event = $this->scheduledEvent($task);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame($task->overlapLockMinutes(), $event->expiresAt);
            $this->assertLessThan(1440, $event->expiresAt);
        }
    }

    public function testRunningStateUsesLockWindow()
    {
        $everyMinute = $this->makeTask(1);
        $halfHourly = $this->makeTask(30);
        foreach ([$everyMinute, $halfHourly] as $task) {
            TaskRun::create(['task_id' => $task->id, 'started_at' => now()->subMinutes(15)]);
        }

        $this->assertFalse($everyMinute->run_status['is_running'], 'open run is older than the lock window');
        $this->assertTrue($halfHourly->run_status['is_running'], 'open run is inside the lock window');

        // as loaded for ?fields=id: frequency is not selected
        $partial = SchedulerTask::select(['id'])->find($halfHourly->id);
        $this->assertSame(60, $partial->overlapLockMinutes());
        $this->assertTrue($partial->run_status['is_running']);
    }
}

/** Schedules as if the cron daemon were up, so the event can be inspected. */
class CronUpScheduler extends TaskScheduler
{
    protected static function cronIsRunning()
    {
        return true;
    }
}
