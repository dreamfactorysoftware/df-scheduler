<?php

namespace DreamFactory\Core\Scheduler\Testing;

use DreamFactory\Core\Scheduler\Components\TaskScheduler;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Testing\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use \Mockery as m;

class ScheduleTest extends TestCase
{
    const RESOURCE = 'scheduler';

    protected $serviceId = '1';

    public function testScheduleTask()
    {
        $mock = m::mock(SchedulerTask::class);
        $this->assertEquals(0, count(app(Schedule::class)->events()));
        $mock->shouldReceive('getAttribute')->with('payload')->once()->andReturn("");
        $mock->shouldReceive('getAttribute')->with('verb_mask')->once()->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('service_id')->once()->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('component')->once()->andReturn("/service");
        $mock->shouldReceive('getAttribute')->with('is_active')->once()->andReturn(1);
        $mock->shouldReceive('getAttribute')->with('frequency')->once()->andReturn(1);
        $mock->shouldReceive('offsetExists')->once()->andReturnSelf();
        TaskScheduler::schedule($mock);
        $this->assertEquals(1, count(app(Schedule::class)->events()));
    }
}