<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class MysqlBackupService
{
    /** @param array<string, mixed> $config */
    public function dump(array $config, string $path): void
    {
        $database = (string) ($config['database'] ?? '');
        if ($database === '' || str_starts_with($database, '-')) {
            throw new \RuntimeException('Invalid backup database configuration.');
        }
        $process = $this->process([
            'mysqldump', '--single-transaction', '--quick',
            '--host='.(string) ($config['host'] ?? 'localhost'),
            '--port='.(string) ($config['port'] ?? 3306),
            '--user='.(string) ($config['username'] ?? ''),
            $database,
        ], ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
        $gzip = gzopen($path, 'wb');
        if ($gzip === false || ! chmod($path, 0600)) {
            if ($gzip !== false) {
                gzclose($gzip);
            }
            throw new \RuntimeException('Cannot create a private backup file.');
        }
        $bytes = 0;
        $closed = false;
        try {
            $process->setTimeout(3600);
            $exit = $process->run(function (string $type, string $buffer) use ($gzip, $process, &$bytes): void {
                if ($type === Process::OUT) {
                    if (gzwrite($gzip, $buffer) !== strlen($buffer)) {
                        throw new \RuntimeException('Cannot write the database backup.');
                    }
                    $bytes += strlen($buffer);
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            $closed = gzclose($gzip);
            if ($exit !== 0 || $bytes === 0 || ! $closed) {
                throw new \RuntimeException('Database dump failed; no usable backup was created.');
            }
        } catch (\Throwable) {
            if (! $closed && is_resource($gzip)) {
                gzclose($gzip);
            }
            @unlink($path);
            throw new \RuntimeException('Database dump failed; check server configuration and disk space.');
        }
    }

    /** @param list<string> $command
     * @param  array<string, string>  $environment
     */
    protected function process(array $command, array $environment): Process
    {
        return new Process($command, env: $environment);
    }
}
