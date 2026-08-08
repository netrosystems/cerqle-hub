<?php

namespace App\Modules\Broadcasting\Services\Sms;

use App\Models\Workspace;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Integrations\Services\CredentialResolver;
use RuntimeException;

class SmsDriverManager
{
    public static function forWorkspace(int $workspaceId): SmsDriverInterface
    {
        return static::resolveForWorkspace($workspaceId)->driver;
    }

    public static function resolveForWorkspace(int $workspaceId, ?string $requestedProvider = null): ResolvedSmsDriver
    {
        if ($requestedProvider !== null && $requestedProvider !== '') {
            $config = SmsProviderConfig::where('workspace_id', $workspaceId)
                ->where('provider', $requestedProvider)
                ->first();

            if (! $config || empty($config->credentials)) {
                throw new RuntimeException('The selected SMS provider is not configured for this workspace.');
            }

            return static::resolvedFromConfig($config);
        }

        // 1. Workspace-level default
        $config = SmsProviderConfig::where('workspace_id', $workspaceId)
            ->where('default', true)
            ->first();

        if ($config) {
            return static::resolvedFromConfig($config);
        }

        // 2. Fall back to first configured system default (in priority order)
        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (['twilio', 'nexmo', 'messagebird', 'smsbd', 'reve', 'alaris', 'bulksmsbd', 'sms_dot_bd', 'mimsms', 'fast2sms', 'amazon_sns'] as $provider) {
            $creds = CredentialResolver::for($workspace)->sms($provider);
            if ($creds) {
                $credentials = $creds->toArray();

                return new ResolvedSmsDriver(
                    $provider,
                    static::providerKey($provider, $credentials),
                    static::build($provider, $credentials),
                    static::defaultThroughputTps($provider),
                );
            }
        }

        throw new RuntimeException('No SMS provider configured for workspace '.$workspaceId);
    }

    public static function resolvedFromConfig(SmsProviderConfig $config): ResolvedSmsDriver
    {
        $credentials = $config->credentials ?? [];

        return new ResolvedSmsDriver(
            $config->provider,
            static::providerKey($config->provider, $credentials),
            static::build($config->provider, $credentials),
            min(
                max(1, (int) config('broadcasting.sms.platform_rate_per_second', 180)),
                max(1, (int) ($config->throughput_tps ?: static::defaultThroughputTps($config->provider))),
            ),
        );
    }

    /**
     * Conservative defaults when we cannot inspect a sender's contractual
     * throughput from credentials alone. A workspace administrator may set a
     * confirmed ceiling in SMS Gateway Setup.
     */
    public static function defaultThroughputTps(string $provider): int
    {
        return match ($provider) {
            'alaris' => max(1, (int) config('broadcasting.sms.provider_rate_per_second', 180)),
            'twilio', 'amazon_sns' => 1,
            default => 1,
        };
    }

    /**
     * A non-reversible fingerprint makes the throughput limit follow the actual
     * provider account even when the same credentials are used in two workspaces.
     */
    public static function providerKey(string $provider, array $credentials): string
    {
        $normalised = static::sortRecursive($credentials);

        return hash('sha256', $provider.'|'.json_encode($normalised, JSON_UNESCAPED_SLASHES));
    }

    private static function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = static::sortRecursive($item);
            }
        }
        ksort($value);

        return $value;
    }

    public static function build(string $provider, array $creds): SmsDriverInterface
    {
        return match ($provider) {
            'twilio' => new TwilioDriver($creds['account_sid'] ?? '', $creds['auth_token'] ?? '', $creds['from_number'] ?? ''),
            'nexmo' => new NexmoDriver($creds['api_key'] ?? '', $creds['api_secret'] ?? '', $creds['from'] ?? ''),
            'messagebird' => new MessageBirdDriver($creds['api_key'] ?? '', $creds['originator'] ?? ''),
            'smsbd' => new SmsBdDriver($creds['api_key'] ?? '', $creds['sender'] ?? ''),
            'reve' => new ReveSmsDriver($creds['api_key'] ?? '', $creds['api_secret'] ?? '', $creds['mask'] ?? ''),
            'alaris' => new AlarisSmsDriver(
                $creds['base_url'] ?? '',
                $creds['username'] ?? '',
                $creds['password'] ?? '',
                $creds['sender_id'] ?? '',
                $creds['service_type'] ?? '',
                $creds['long_message_mode'] ?? '',
            ),
            'bulksmsbd' => new BulkSmsBdDriver($creds['api_key'] ?? '', $creds['sender_id'] ?? ''),
            'sms_dot_bd' => new SmsDotBdDriver($creds['api_key'] ?? '', $creds['sender_id'] ?? ''),
            'mimsms' => new MimSmsDriver($creds['api_key'] ?? '', $creds['sender_id'] ?? ''),
            'fast2sms' => new Fast2SmsDriver($creds['api_key'] ?? '', $creds['sender_id'] ?? 'FSTSMS', $creds['route'] ?? 'q'),
            'amazon_sns' => new AmazonSnsDriver($creds['access_key'] ?? '', $creds['secret_key'] ?? '', $creds['region'] ?? 'us-east-1', $creds['sender_id'] ?? '', $creds['sms_type'] ?? 'Transactional'),
            default => throw new RuntimeException("Unknown SMS provider: {$provider}"),
        };
    }
}
