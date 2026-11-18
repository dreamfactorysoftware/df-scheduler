<?php

namespace DreamFactory\Core\Scheduler\Tests;

use DreamFactory\Core\Scheduler\ServiceProvider;
use DreamFactory\Core\Testing\TestCase;

/**
 * Crontabs are kept in memory, keyed by OS user, so no real crontab is touched.
 */
class FakeCrontabProvider extends ServiceProvider
{
    public static array $crontabs = [];
    public string $user = 'root';

    public function install(): void
    {
        $this->installCrontabEntry();
    }

    protected function currentOsUser(): ?string
    {
        return $this->user;
    }

    protected function readCrontab(?string $user = null): string
    {
        return static::$crontabs[$user ?? $this->user] ?? '';
    }

    protected function writeCrontab(string $crontabFile): bool
    {
        static::$crontabs[$this->user] = file_get_contents($crontabFile);

        return true;
    }
}

class CrontabEntryTest extends TestCase
{
    private string $originalStorage;
    private string $storage;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = $this->app->storagePath();
        $this->storage = sys_get_temp_dir() . '/df-scheduler-cron-' . uniqid();
        mkdir($this->storage);
        $this->app->useStoragePath($this->storage);
        FakeCrontabProvider::$crontabs = [];
        config(['df-scheduler.install_cron' => true, 'df-scheduler.cron_user' => '']);
    }

    public function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        foreach (glob($this->storage . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->storage);
        parent::tearDown();
    }

    private function runAs(string $user): void
    {
        $provider = new FakeCrontabProvider($this->app);
        $provider->user = $user;
        $provider->install();
    }

    private function entries(string $user): int
    {
        return substr_count(FakeCrontabProvider::$crontabs[$user] ?? '', 'artisan schedule:run');
    }

    private function owner(): ?string
    {
        $owners = json_decode((string)@file_get_contents($this->storage . '/crontab.owner.json'), true);

        return $owners[gethostname()] ?? null;
    }

    private function entryLine(): string
    {
        return '* * * * * cd ' . base_path() . '/ && php artisan schedule:run >> /dev/null 2>&1';
    }

    public function testFirstUserGetsTheEntryAndOthersDoNot(): void
    {
        $this->runAs('root');
        $this->runAs('www-data');
        $this->runAs('root');
        $this->runAs('www-data');

        $this->assertSame(1, $this->entries('root'));
        $this->assertSame(0, $this->entries('www-data'));
        $this->assertSame('root', $this->owner());
    }

    public function testWebUserFirstKeepsRootCrontabEmpty(): void
    {
        $this->runAs('www-data');
        $this->runAs('root');

        $this->assertSame(1, $this->entries('www-data'));
        $this->assertSame(0, $this->entries('root'));
    }

    public function testExistingEntryIsRecordedWithoutAddingAnother(): void
    {
        FakeCrontabProvider::$crontabs['root'] = PHP_EOL . $this->entryLine() . PHP_EOL;

        $this->runAs('root');
        $this->runAs('www-data');

        $this->assertSame(1, $this->entries('root'));
        $this->assertSame(0, $this->entries('www-data'));
        $this->assertSame('root', $this->owner());
    }

    public function testTwoExistingEntriesKeepTheRecordedOwner(): void
    {
        FakeCrontabProvider::$crontabs['root'] = PHP_EOL . $this->entryLine() . PHP_EOL;
        $this->runAs('root');
        FakeCrontabProvider::$crontabs['www-data'] = PHP_EOL . $this->entryLine() . PHP_EOL;

        $this->runAs('www-data');

        $this->assertSame(1, $this->entries('www-data'));
        $this->assertSame('root', $this->owner());
    }

    public function testRootTakesOverWhenTheRecordedEntryIsGone(): void
    {
        $this->runAs('www-data');
        FakeCrontabProvider::$crontabs['www-data'] = '';

        $this->runAs('root');

        $this->assertSame(1, $this->entries('root'));
        $this->assertSame('root', $this->owner());
    }

    public function testOtherUsersDoNotTakeOver(): void
    {
        $this->runAs('www-data');
        FakeCrontabProvider::$crontabs['www-data'] = '';

        $this->runAs('deploy');

        $this->assertSame(0, $this->entries('deploy'));
        $this->assertSame('www-data', $this->owner());
    }

    public function testConfiguredUserIsTheOnlyOneThatGetsTheEntry(): void
    {
        config(['df-scheduler.cron_user' => 'www-data']);

        $this->runAs('root');
        $this->runAs('www-data');
        $this->runAs('www-data');

        $this->assertSame(0, $this->entries('root'));
        $this->assertSame(1, $this->entries('www-data'));
    }

    public function testInstallCanBeTurnedOff(): void
    {
        config(['df-scheduler.install_cron' => 'false']);

        $this->runAs('root');

        $this->assertSame([], FakeCrontabProvider::$crontabs);
    }

    public function testExistingCrontabLinesAreKept(): void
    {
        FakeCrontabProvider::$crontabs['root'] = "0 3 * * * /usr/local/bin/backup\n";

        $this->runAs('root');

        $this->assertStringContainsString('/usr/local/bin/backup', FakeCrontabProvider::$crontabs['root']);
        $this->assertSame(1, $this->entries('root'));
    }
}
