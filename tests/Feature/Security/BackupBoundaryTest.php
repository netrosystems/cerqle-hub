<?php

namespace Tests\Feature\Security;

use App\Services\MysqlBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupBoundaryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cerqle-backup-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->app->useStoragePath($this->directory);
        config(['database.default' => 'mysql']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_dump_arguments_are_literal_and_password_is_environment_only(): void
    {
        $path = $this->directory.'/backup.gz';
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('setTimeout')->with(3600)->once();
        $process->shouldReceive('clearOutput')->once();
        $process->shouldReceive('clearErrorOutput')->once();
        $process->shouldReceive('run')->once()->andReturnUsing(function ($callback) {
            $callback(Process::OUT, '-- synthetic test dump --');

            return 0;
        });
        $service = Mockery::mock(MysqlBackupService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('process')->once()->withArgs(function ($arguments, $environment) {
            $this->assertSame('MYSQL_PWD', array_key_first($environment));
            $this->assertSame('test; $(not-a-command)', $environment['MYSQL_PWD']);
            $this->assertContains('--host=host;not-a-command', $arguments);
            $this->assertNotContains($environment['MYSQL_PWD'], $arguments);

            return true;
        })->andReturn($process);
        $service->dump(['host' => 'host;not-a-command', 'database' => 'test_db', 'password' => 'test; $(not-a-command)'], $path);
        $this->assertSame('-- synthetic test dump --', gzdecode(file_get_contents($path)));
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function test_failed_dump_removes_partial_file_even_if_gzip_succeeds(): void
    {
        $path = $this->directory.'/failed.gz';
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('setTimeout')->once();
        $process->shouldReceive('run')->once()->andReturn(2);
        $service = Mockery::mock(MysqlBackupService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('process')->andReturn($process);
        try {
            $service->dump(['database' => 'test_db'], $path);
            $this->fail('Failed dump reported success.');
        } catch (\RuntimeException) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_no_upload_retains_private_local_backup(): void
    {
        $this->fakeDump();
        $this->assertSame(0, Artisan::call('db:backup', ['--no-upload' => true]));
        $files = glob($this->directory.'/app/private/backups/database_*');
        $this->assertCount(1, $files);
        $this->assertSame(0600, fileperms($files[0]) & 0777);
        $this->assertStringContainsString($files[0], Artisan::output());
    }

    public function test_public_backup_disk_is_rejected_before_dumping(): void
    {
        $this->mock(MysqlBackupService::class)->shouldNotReceive('dump');
        $this->assertSame(1, Artisan::call('db:backup', ['--disk' => 'public']));
        $this->assertStringContainsString('private storage disk', Artisan::output());
    }

    public function test_successful_upload_is_private_and_only_then_removes_local_file(): void
    {
        $this->fakeDump();
        $disk = Storage::fake('backup-test');
        $this->assertSame(0, Artisan::call('db:backup', ['--disk' => 'backup-test']));
        $files = $disk->allFiles('backups');
        $this->assertCount(1, $files);
        $this->assertSame('private', $disk->getVisibility($files[0]));
        $this->assertSame([], glob($this->directory.'/app/private/backups/database_*'));
    }

    public function test_upload_failure_retains_backup_and_reports_failure(): void
    {
        $this->fakeDump();
        Storage::shouldReceive('disk')->with('backup-test')->andReturnSelf();
        Storage::shouldReceive('put')->once()->andReturn(false);
        $this->assertSame(1, Artisan::call('db:backup', ['--disk' => 'backup-test']));
        $this->assertCount(1, glob($this->directory.'/app/private/backups/database_*'));
    }

    private function fakeDump(): void
    {
        $this->mock(MysqlBackupService::class)->shouldReceive('dump')->once()->andReturnUsing(function ($config, $path) {
            $gzip = gzopen($path, 'wb');
            gzwrite($gzip, '-- synthetic test dump --');
            gzclose($gzip);
            chmod($path, 0600);
        });
    }
}
