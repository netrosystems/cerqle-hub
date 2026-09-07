<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionCodeSyncTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/cerqle-sync-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $this->runGit($this->root, ['init', '--bare', 'origin.git']);
        $this->runGit($this->root, ['clone', $this->root.'/origin.git', 'dev']);
        $this->runGit($this->root.'/dev', ['checkout', '-b', 'main']);
        mkdir($this->root.'/dev/scripts');
        copy(dirname(__DIR__, 2).'/scripts/sync-production-code.sh', $this->root.'/dev/scripts/sync-production-code.sh');
        file_put_contents($this->root.'/dev/app.txt', 'base');
        $this->commit('dev');
        $this->runGit($this->root.'/dev', ['push', 'origin', 'main']);
        $this->runGit($this->root, ['clone', '-b', 'main', $this->root.'/origin.git', 'server']);
    }

    private function runGit(string $cwd, array $args): string
    {
        $process = new Process(array_merge(['git', '-c', 'user.name=Deploy Test', '-c', 'user.email=deploy@example.test'], $args), $cwd);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function commit(string $checkout): void
    {
        $this->runGit($this->root.'/'.$checkout, ['add', '.']);
        $this->runGit($this->root.'/'.$checkout, ['commit', '--allow-empty', '-m', 'fixture']);
    }

    private function publish(): void
    {
        file_put_contents($this->root.'/dev/app.txt', 'new release');
        $this->commit('dev');
        $this->runGit($this->root.'/dev', ['push', 'origin', 'main']);
    }

    private function sync(): Process
    {
        $process = new Process(['bash', 'scripts/sync-production-code.sh'], $this->root.'/server', ['CERQLE_DEPLOY_BRANCH' => 'main']);
        $process->run();

        return $process;
    }

    public function test_fast_forward_preserves_untracked_files_and_backup(): void
    {
        file_put_contents($this->root.'/server/.env', 'test-only-setting');
        $old = $this->runGit($this->root.'/server', ['rev-parse', 'HEAD']);
        $this->publish();
        $this->assertTrue($this->sync()->isSuccessful());
        $this->assertSame('new release', file_get_contents($this->root.'/server/app.txt'));
        $this->assertSame('test-only-setting', file_get_contents($this->root.'/server/.env'));
        $this->assertSame($old, $this->runGit($this->root.'/server', ['for-each-ref', '--format=%(objectname)', 'refs/heads/deploy-backup/']));
    }

    public function test_history_only_divergence_is_reconciled(): void
    {
        $this->commit('server');
        $this->publish();
        $this->assertTrue($this->sync()->isSuccessful());
        $this->assertSame($this->runGit($this->root.'/dev', ['rev-parse', 'HEAD']), $this->runGit($this->root.'/server', ['rev-parse', 'HEAD']));
    }

    public function test_real_server_only_changes_are_not_discarded(): void
    {
        file_put_contents($this->root.'/server/app.txt', 'server hotfix');
        $this->commit('server');
        $this->publish();
        $this->assertFalse($this->sync()->isSuccessful());
        $this->assertSame('server hotfix', file_get_contents($this->root.'/server/app.txt'));
    }

    public function test_dirty_tracked_files_are_not_discarded(): void
    {
        file_put_contents($this->root.'/server/app.txt', 'unsaved hotfix');
        $this->publish();
        $this->assertFalse($this->sync()->isSuccessful());
        $this->assertSame('unsaved hotfix', file_get_contents($this->root.'/server/app.txt'));
    }

    public function test_untracked_collision_blocks_sync(): void
    {
        file_put_contents($this->root.'/server/collision.txt', 'local data');
        file_put_contents($this->root.'/dev/collision.txt', 'release file');
        $this->publish();
        $this->assertFalse($this->sync()->isSuccessful());
        $this->assertSame('local data', file_get_contents($this->root.'/server/collision.txt'));
    }

    public function test_ignored_collision_blocks_sync(): void
    {
        file_put_contents($this->root.'/server/.git/info/exclude', "ignored.txt\n");
        file_put_contents($this->root.'/server/ignored.txt', 'local secret');
        file_put_contents($this->root.'/dev/ignored.txt', 'release file');
        $this->publish();
        $this->assertFalse($this->sync()->isSuccessful());
        $this->assertSame('local secret', file_get_contents($this->root.'/server/ignored.txt'));
    }
}
