<?php

namespace DreamFactory\Core\Scheduler\Tests;

use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Scheduler\Models\SchedulerTask;
use DreamFactory\Core\Testing\TestCase;

class PartialFieldsTest extends TestCase
{
    private ?SchedulerTask $task = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->task = SchedulerTask::create([
            'name'       => 'partial-fields-' . uniqid(),
            'service_id' => Service::whereName('system')->value('id'),
            'component'  => 'environment',
            'verb_mask'  => 1,
            'frequency'  => 5,
            'is_active'  => false,
        ]);
    }

    public function tearDown(): void
    {
        if ($this->task) {
            SchedulerTask::whereKey($this->task->id)->delete();
        }
        parent::tearDown();
    }

    private function select(array $columns): array
    {
        return SchedulerTask::whereKey($this->task->id)->select($columns)->first()->toArray();
    }

    public function testNameOnly(): void
    {
        $row = $this->select(['name']);

        $this->assertSame($this->task->name, $row['name']);
        $this->assertArrayNotHasKey('verb', $row);
        $this->assertArrayNotHasKey('run_status', $row);
    }

    public function testIdAndNameIncludeRunStatus(): void
    {
        $row = $this->select(['id', 'name']);

        $this->assertArrayNotHasKey('verb', $row);
        $this->assertArrayHasKey('run_status', $row);
        $this->assertFalse($row['run_status']['is_running']);
    }

    public function testVerbMaskIncludesVerb(): void
    {
        $row = $this->select(['name', 'verb_mask']);

        $this->assertSame('GET', $row['verb']);
        $this->assertArrayNotHasKey('run_status', $row);
    }

    public function testAllColumns(): void
    {
        $row = SchedulerTask::find($this->task->id)->toArray();

        $this->assertSame('GET', $row['verb']);
        $this->assertArrayHasKey('run_status', $row);
    }

    public function testVerbIsNullWithoutVerbMask(): void
    {
        $task = SchedulerTask::whereKey($this->task->id)->select(['id'])->first();

        $this->assertNull($task->verb);
    }
}
