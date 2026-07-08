<?php

namespace DreamFactory\Core\Scheduler\Testing;

use DreamFactory\Core\Scheduler\Components\TaskScheduler;
use DreamFactory\Core\Scheduler\Components\RunAsSession;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Scheduler\Models\TaskLog;
use DreamFactory\Core\Testing\TestCase;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\Lookup;
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
        // Run-as identity columns are read while building the command; a legacy
        // task has neither, so the scheduled command stays identity-free.
        $mock->shouldReceive('getAttribute')->with('app_id')->andReturn(null);
        $mock->shouldReceive('getAttribute')->with('user_id')->andReturn(null);
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

    /**
     * A task carrying a run-as identity puts --app-id/--user-id on the scheduled
     * command; a task without one stays identity-free (legacy behavior).
     */
    public function testScheduledCommandIncludesRunAsIdentity()
    {
        $role = Role::create(['name' => 'runas_cmd_role', 'is_active' => true]);
        $app = App::create([
            'name' => 'runas_cmd_app',
            'api_key' => str_repeat('b', 64),
            'role_id' => $role->id,
            'is_active' => true,
            'type' => 0,
        ]);

        $withIdentity = new SchedulerTask([
            'name' => 'runas_cmd_task', 'payload' => '', 'verb_mask' => 1, 'service_id' => 1,
            'component' => 'test', 'is_active' => 1, 'frequency' => 1,
            'app_id' => $app->id, 'user_id' => null,
        ]);
        $withIdentity->save();
        TaskScheduler::schedule($withIdentity);
        $events = app(Schedule::class)->events();
        $command = end($events)->command;
        $this->assertStringContainsString('df:scheduled-request', $command);
        $this->assertStringContainsString('--app-id=' . $app->id, $command);

        $legacy = new SchedulerTask([
            'name' => 'runas_legacy_task', 'payload' => '', 'verb_mask' => 1, 'service_id' => 1,
            'component' => 'test', 'is_active' => 1, 'frequency' => 1,
        ]);
        $legacy->save();
        TaskScheduler::schedule($legacy);
        $events = app(Schedule::class)->events();
        $this->assertStringNotContainsString('--app-id=', end($events)->command);
    }

    /**
     * The heart of Option B: applying a run-as identity establishes a real
     * session, so lookups resolve and a role (with role.services for RBAC) is
     * loaded — exactly what the session-less scheduler lacks today.
     */
    public function testRunAsSessionResolvesLookupsAndRole()
    {
        Lookup::create(['name' => 'RUNAS_TEST', 'value' => 'resolved-value']);
        $role = Role::create(['name' => 'runas_session_role', 'is_active' => true]);
        $app = App::create([
            'name' => 'runas_session_app',
            'api_key' => str_repeat('c', 64),
            'role_id' => $role->id,
            'is_active' => true,
            'type' => 0,
        ]);

        // Start from a clean, session-less state (creating the lookup above
        // primed the map, so flush to reproduce the scheduler's cold start).
        Session::flush();
        $before = '{RUNAS_TEST}';
        Session::replaceLookups($before);
        $this->assertEquals('{RUNAS_TEST}', $before, 'lookup must be unresolved before run-as');
        $this->assertNull(Session::get('role.id'));

        RunAsSession::apply($app->id, null);

        $this->assertEquals($app->id, Session::get('app.id'));
        $this->assertEquals($role->id, Session::get('role.id'));
        $this->assertTrue(Session::get('role.services') !== null, 'role.services must be loaded for RBAC');

        $after = '{RUNAS_TEST}';
        Session::replaceLookups($after);
        $this->assertEquals('resolved-value', $after, 'lookup must resolve under the run-as identity');
    }

    /**
     * No identity => no session established => legacy session-less behavior,
     * so existing tasks are completely unaffected.
     */
    public function testNoIdentityLeavesSessionless()
    {
        Session::flush();
        RunAsSession::apply(null, null);
        $this->assertNull(Session::get('app.id'));
        $this->assertNull(Session::get('role.id'));
    }
}
