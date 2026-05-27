<?php

namespace DreamFactory\Core\Scheduler\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Source-level guards on the scheduler ServiceProvider:
 *  - crontab install must NOT run on every web request boot;
 *  - the storage path passed to exec() must go through escapeshellarg;
 *  - install must take a file lock to serialize concurrent workers.
 */
class BootCrontabTest extends TestCase
{
    private string $sp;

    protected function setUp(): void
    {
        $this->sp = file_get_contents(__DIR__ . '/../../src/ServiceProvider.php');
    }

    public function testInstallIsConsoleGated(): void
    {
        $this->assertMatchesRegularExpression(
            '/runningInConsole\(\).*installCrontabEntry/s',
            $this->sp,
            'crontab install must be gated by runningInConsole()'
        );
    }

    public function testInstallSkipsUnitTests(): void
    {
        $this->assertStringContainsString(
            'runningUnitTests',
            $this->sp,
            'crontab install must skip during unit tests'
        );
    }

    public function testCrontabPathIsEscaped(): void
    {
        $this->assertStringContainsString(
            'escapeshellarg($crontabFile)',
            $this->sp,
            'crontab path must be passed to exec() via escapeshellarg'
        );
    }

    public function testInstallTakesExclusiveLock(): void
    {
        $this->assertStringContainsString('LOCK_EX', $this->sp);
        $this->assertStringContainsString('LOCK_NB', $this->sp);
        $this->assertStringContainsString('flock($lock', $this->sp);
    }

    public function testRawExecOfStoragePathRemoved(): void
    {
        // The unsafe pre-fix pattern: exec('crontab ' . storage_path() . '/crontab.txt')
        $this->assertDoesNotMatchRegularExpression(
            "/exec\\(\\s*'crontab '\\s*\\.\\s*storage_path\\(\\)/",
            $this->sp,
            'unsafe pre-fix exec call must be gone'
        );
    }
}
