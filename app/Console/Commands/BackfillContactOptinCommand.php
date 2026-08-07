<?php

namespace App\Console\Commands;

use App\Modules\Shared\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backfill opt_in_sms / opt_in_whatsapp / opt_in_email on existing contacts
 * that were created via paths which never set the flag (legacy WhatsApp
 * inbox, ecommerce webhooks, etc.) but DO have documented consent on file
 * (imported via CSV with a consent column, or added manually).
 *
 * Why this command exists
 * ------------------------
 * The Campaign "audience preview" KPI shows two numbers — the audience size
 * (matched) and the deliverable count (contacts that opted in for the chosen
 * channel). For workspaces where most contacts arrived via one of the
 * consent-light entry points, the second number is a tiny fraction of the
 * first and the campaign looks broken when it isn't.
 *
 * Safety rails
 * ------------
 *   * Only flips flags to TRUE. Never to false — explicit opt-outs are
 *     sacred and must be honoured even if they look wrong.
 *   * Only touches rows whose `source` is in the consent-bearing set
 *     (CSV list imports, CSV campaign audiences, manual CRM entries, bulk
 *     imports). Anything we did not get consent for is left alone.
 *   * Skips contacts without a phone number — opting in a row with no
 *     phone number is a no-op for SMS but a real problem for WhatsApp/email.
 *   * Skips `is_campaign_only = true` rows (audience-uploads attached to a
 *     single list — those are scoped by design and should not be promoted
 *     to the CRM's customer directory implicitly).
 *   * `--dry-run` is the default expectation on a production run; you must
 *     pass `--apply` to write changes.
 *
 * Usage
 * -----
 *   php artisan contacts:backfill-optin --dry-run
 *   php artisan contacts:backfill-optin --apply --workspace=42
 *   php artisan contacts:backfill-optin --apply --source=contact_list_csv
 *   php artisan contacts:backfill-optin --apply --channels=sms,whatsapp
 */
class BackfillContactOptinCommand extends Command
{
    /**
     * Sources that carry explicit, documented consent on file.
     * Order matters only for the display in --help output.
     */
    private const CONSENT_BEARING_SOURCES = [
        'contact_list_csv',  // CSV uploaded into a contact list (opt_in_sms column on file)
        'campaign_csv',      // CSV uploaded into a campaign audience (same)
        'import',            // CRM bulk import (CSV upload or grid editor)
        'manual',            // operator added the contact via the CRM form
    ];

    /**
     * Channels we can opt in. opt_in_email already defaults to true in the
     * schema, so it's listed for completeness but contributes nothing unless
     * a false row from a legacy import sneaks in.
     */
    private const CHANNELS = ['sms', 'whatsapp', 'email'];

    protected $signature = 'contacts:backfill-optin
        {--dry-run : Print what would change without writing anything (default behaviour)}
        {--apply : Commit the changes; required to write}
        {--workspace= : Limit to one workspace id}
        {--source=* : Only backfill rows whose `source` is in this list (defaults to every consent-bearing source)}
        {--channel=* : Only flip the listed channels (defaults to sms,whatsapp,email)}
        {--batch=500 : Update batch size for the bulk update}';

    protected $description = 'Backfill opt_in_sms / opt_in_whatsapp / opt_in_email on contacts imported via consent-bearing sources';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply;

        $sources = $this->option('source');
        $sources = $sources === [] ? self::CONSENT_BEARING_SOURCES : array_values(array_intersect($sources, self::CONSENT_BEARING_SOURCES));

        if ($sources === []) {
            $this->error('No valid sources selected. Allowed: '.implode(', ', self::CONSENT_BEARING_SOURCES));

            return self::INVALID;
        }

        $channels = $this->option('channel');
        $channels = $channels === [] ? self::CHANNELS : array_values(array_intersect($channels, self::CHANNELS));

        if ($channels === []) {
            $this->error('No valid channels selected. Allowed: '.implode(', ', self::CHANNELS));

            return self::INVALID;
        }

        $batch = max(50, (int) $this->option('batch'));

        $this->info('');
        $this->line(sprintf(
            '  <fg=cyan;options=bold>contacts:backfill-optin</> %s',
            $dryRun ? '<fg=yellow>(DRY RUN — pass --apply to commit)</>' : '<fg=red>(APPLYING — writes to the database)</>'
        ));
        $this->line('  sources : '.implode(', ', $sources));
        $this->line('  channels: '.implode(', ', $channels));
        $this->line('  scope   : '.($this->option('workspace') ? 'workspace='.$this->option('workspace') : 'ALL workspaces'));
        $this->info('');

        $base = $this->baseQuery($sources);

        if ($this->option('workspace')) {
            $base->where('workspace_id', (int) $this->option('workspace'));
        }

        // Counts per channel BEFORE the change, so the summary is useful.
        $beforeByChannel = [];
        foreach ($channels as $channel) {
            $column = "opt_in_{$channel}";
            $beforeByChannel[$channel] = (clone $base)
                ->whereNotNull('phone_e164')
                ->where($column, true)
                ->count();
        }
        $totalCandidates = (clone $base)->count();

        $this->line(sprintf('  candidates in scope: <fg=white>%d</>', $totalCandidates));
        foreach ($beforeByChannel as $channel => $count) {
            $this->line(sprintf('  currently opted-in for %-8s: <fg=white>%d</>', $channel, $count));
        }
        $this->info('');

        if ($dryRun) {
            $this->line('  <fg=gray>Nothing was modified. Re-run with --apply to commit.</>');
            $this->info('');

            return self::SUCCESS;
        }

        $updatedByChannel = [];
        foreach ($channels as $channel) {
            $column = "opt_in_{$channel}";
            // Only flip contacts that already have a phone number — opting in
            // a phone-less row is harmless for SMS but pollutes segments later.
            $query = (clone $base)
                ->whereNotNull('phone_e164')
                ->where($column, false);

            $updated = $this->updateInBatches($query, $batch, [$column => true]);
            $updatedByChannel[$channel] = $updated;
        }

        $this->info('  <fg=cyan;options=bold>Results</>');
        foreach ($updatedByChannel as $channel => $count) {
            $this->line(sprintf('  opted-in for %-8s: <fg=green>%d</> contacts updated', $channel, $count));
        }
        $this->info('');
        $this->line('  <fg=gray>Run again with --dry-run to confirm there is nothing left to backfill.</>');
        $this->info('');

        return self::SUCCESS;
    }

    /**
     * The narrow slice of the contacts table this command is allowed to touch.
     */
    private function baseQuery(array $sources): Builder
    {
        return Contact::query()
            ->whereIn('source', $sources)
            // Skip campaign-only uploads — those are scoped to a single list
            // and intentionally hidden from the customer directory.
            ->where('is_campaign_only', false);
    }

    /**
     * Run the UPDATE in batches so a 100k-row workspace doesn't lock the
     * table for minutes. Returns the total row count updated.
     */
    private function updateInBatches(Builder $query, int $batchSize, array $values): int
    {
        $total = 0;
        $query->select(['id'])->orderBy('id')->chunkById($batchSize, function ($rows) use ($values, &$total) {
            $ids = $rows->pluck('id')->all();
            if ($ids === []) {
                return;
            }
            $total += Contact::query()->whereIn('id', $ids)->update($values);
        }, 'id');

        return $total;
    }
}
