<?php

namespace DreamFactory\Core\Scheduler\Testing;

use DreamFactory\Core\Scheduler\Components\TaskScheduler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Scheduler\Models\TaskLog;
use DreamFactory\Core\Testing\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use \Mockery as m;

class ScheduleTest extends TestCase
{
    use DatabaseTransactions;

    const RESOURCE = 'scheduler';

    protected $serviceId = '1';

    public function testScheduleTask()
    {
        $counter=count(app(Schedule::class)->events());
        $mock = m::mock(SchedulerTask::class);
        $mock->shouldReceive('getAttribute')->with('payload')->once()->andReturn("");
        $mock->shouldReceive('getAttribute')->with('verb_mask')->once()->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('service_id')->once()->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('component')->once()->andReturn("/service");
        $mock->shouldReceive('getAttribute')->with('is_active')->once()->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('frequency')->once()->andReturn(1);
        $mock->shouldReceive('offsetExists')->once()->andReturnSelf();
        TaskScheduler::schedule($mock);
        $this->assertEquals($counter + 1, count(app(Schedule::class)->events()));
    }

    public function testScheduleTaskLog()
    {
        $counter = TaskLog::all()->count();
        $task = new SchedulerTask([
            'name'=> 'phpunit_testing_task',
            'payload' => '',
            'verb_mask' => 1,
            'service_id' => 1,
            'component' => 'admin/password',
            'is_active' => 1,
            'frequency'=> 1
        ]);
        $task->save();
        $this->assertTrue(SchedulerTask::whereName('phpunit_testing_task')->exists());
        TaskScheduler::schedule($task);
        Artisan::call('schedule:run');
        $this->assertEquals($counter + 1, TaskLog::all()->count());
    }


}
