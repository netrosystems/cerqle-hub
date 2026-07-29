<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ReleaseVersionService
{
    /**
     * @return array{version: string, commit: ?string, deployed_at: ?string, changes: array<int, string>}
     */
    public function current(): array
    {
        $state = $this->readJson($this->statePath());

        return [
            'version' => $this->normaliseVersion($state['version'] ?? config('release.base_version', '1.0.0')),
            'commit' => isset($state['commit']) && is_string($state['commit']) ? $state['commit'] : null,
            'deployed_at' => isset($state['deployed_at']) && is_string($state['deployed_at']) ? $state['deployed_at'] : null,
            'changes' => array_values(array_filter(
                is_array($state['changes'] ?? null) ? $state['changes'] : [],
                'is_string'
            )),
        ];
    }

    /**
     * Record a successful deployment and return its release metadata.
     *
     * @return array{version: string, commit: ?string, deployed_at: string, changes: array<int, string>}
     */
    public function record(?string $explicitVersion = null): array
    {
        $previous = $this->current();
        $commit = $this->gitOutput(['rev-parse', '--short=12', 'HEAD']);
        $version = $explicitVersion
            ? $this->normaliseVersion($explicitVersion)
            : $this->incrementPatch($previous['version']);

        $release = [
            'version' => $version,
            'commit' => $commit,
            'deployed_at' => now()->toIso8601String(),
            'changes' => $this->changesSince($previous['commit'], $commit),
        ];

        $this->writeJson($this->statePath(), $release);

        $historyPath = $this->historyPath();
        $history = $this->readJsonList($historyPath);
        array_unshift($history, $release);
        $this->writeJson($historyPath, array_slice($history, 0, (int) config('release.history_limit', 50)));

        return $release;
    }

    private function incrementPatch(string $version): string
    {
        [$major, $minor, $patch] = array_map('intval', explode('.', $this->normaliseVersion($version)));

        return sprintf('%d.%d.%d', $major, $minor, $patch + 1);
    }

    private function statePath(): string
    {
        return (string) (config('release.state_path') ?: base_path('release/current.json'));
    }

    private function historyPath(): string
    {
        return (string) (config('release.history_path') ?: base_path('release/history.json'));
    }

    private function normaliseVersion(mixed $version): string
    {
        $value = ltrim(trim(is_string($version) ? $version : ''), 'vV');

        return preg_match('/^\d+\.\d+\.\d+$/', $value) === 1 ? $value : '1.0.0';
    }

    /**
     * @return array<int, string>
     */
    private function changesSince(?string $previousCommit, ?string $currentCommit): array
    {
        if (! $currentCommit) {
            return [];
        }

        $range = $previousCommit && $previousCommit !== $currentCommit
            ? "{$previousCommit}..{$currentCommit}"
            : $currentCommit;
        $output = $this->gitOutput(['log', '--pretty=format:%s', '--no-merges', '-20', $range]);

        if (! $output) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));
    }

    private function gitOutput(array $arguments): ?string
    {
        try {
            $process = new Process(array_merge(['git'], $arguments), base_path());
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! File::isFile($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJsonList(string $path): array
    {
        $decoded = $this->readJson($path);

        return array_is_list($decoded) ? $decoded : [];
    }

    private function writeJson(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path), 0775);
        File::put(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            true
        );
    }
}
