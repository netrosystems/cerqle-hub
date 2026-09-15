<?php

namespace App\Console\Commands;

use App\Services\MysqlBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DbBackupCommand extends Command
{
    protected $signature = 'db:backup {--disk=local : Storage disk to upload the backup to} {--no-upload : Keep backup local only}';

    protected $description = 'Dump the MySQL database and optionally upload to a storage disk.';

    public function handle(MysqlBackupService $backup): int
    {
        if (config('database.default') !== 'mysql') {
            $this->error('db:backup currently only supports MySQL.');

            return self::FAILURE;
        }
        $diskName = (string) $this->option('disk');
        $diskConfig = config('filesystems.disks.'.$diskName, []);
        $diskRoot = realpath($diskConfig['root'] ?? '');
        $publicLocal = false;
        foreach ([realpath(public_path()), realpath(storage_path('app/public'))] as $publicRoot) {
            if ($diskRoot !== false && $publicRoot !== false && ($diskRoot === $publicRoot || str_starts_with($diskRoot, $publicRoot.DIRECTORY_SEPARATOR))) {
                $publicLocal = true;
            }
        }
        if (! $this->option('no-upload') && ($diskName === 'public' || ($diskConfig['visibility'] ?? null) === 'public'
            || (($diskConfig['driver'] ?? null) === 'local' && $publicLocal))) {
            $this->error('Database backups require a private storage disk.');

            return self::FAILURE;
        }
        $directory = storage_path('app/private/backups');
        if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || ! chmod($directory, 0700)) {
            $this->error('Cannot create a private backup directory.');

            return self::FAILURE;
        }
        $path = tempnam($directory, 'database_');
        if ($path === false) {
            $this->error('Cannot create a private backup file.');

            return self::FAILURE;
        }
        $filename = 'database_'.now()->format('Y_m_d_His').'_'.bin2hex(random_bytes(6)).'.sql.gz';
        try {
            $backup->dump(config('database.connections.mysql'), $path);
        } catch (\Throwable) {
            @unlink($path);
            $this->error('Database dump failed. No backup was uploaded.');

            return self::FAILURE;
        }
        if ($this->option('no-upload')) {
            $this->info('Private local backup retained: '.$path);

            return self::SUCCESS;
        }
        $stream = fopen($path, 'rb');
        try {
            if ($stream === false || ! Storage::disk($this->option('disk'))->put('backups/'.$filename, $stream, ['visibility' => 'private'])) {
                throw new \RuntimeException('Upload failed.');
            }
        } catch (\Throwable) {
            $this->error('Upload failed; private local backup retained: '.$path);

            return self::FAILURE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        @unlink($path);
        $this->info('Private backup uploaded: backups/'.$filename);

        return self::SUCCESS;
    }
}
