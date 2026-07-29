<?php

namespace App\Console\Commands;

use App\Services\ReleaseVersionService;
use Illuminate\Console\Command;

class RecordReleaseCommand extends Command
{
    protected $signature = 'app:release
        {--show : Display the currently recorded release without changing it}
        {--release-version= : Record an explicit semantic version instead of incrementing the patch number}';

    protected $description = 'Record a successful deployment, increment its version, and generate release notes';

    public function handle(ReleaseVersionService $releases): int
    {
        $release = $this->option('show')
            ? $releases->current()
            : $releases->record($this->option('release-version') ?: null);

        $verb = $this->option('show') ? 'Current release' : 'Release recorded';
        $this->components->info("{$verb}: v{$release['version']}");

        if ($release['commit']) {
            $this->line("Commit: {$release['commit']}");
        }

        foreach ($release['changes'] as $change) {
            $this->line("  - {$change}");
        }

        return self::SUCCESS;
    }
}
