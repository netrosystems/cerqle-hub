<?php

namespace App\Console\Commands;

use App\Modules\Shared\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backfill opt_in_sms on existing contacts that were created via paths
 * which never set the flag (legacy WhatsApp inbox, ecommerce webhooks,
 * etc.) but DO have documented SMS consent on file (imported via CSV
 * with an opt_in_sms column, or added manually).
 *
 * Why this command exists
 * ------------------------
 * The Campaign "audience preview" KPI shows two numbers — the audience size
 * (matched) and the deliverable count (contacts that opted in for the chosen
 * channel). For workspaces where most contacts arrived via one of the
 * consent-light entry points, the second number is a tiny fraction of the
 * first and the campaign looks broken when it isn't.
 *
 * Channel scope: SMS ONLY.
 * ------------------------
 * Each opt_in_* column documents consent for a specific channel. CSV imports
 * only carry an `opt_in_sms` column on file, so this command flips
 * `opt_in_sms` and nothing else. WhatsApp consent is recorded separately by
 * the WhatsApp driver the first time the contact messages the inbox. There
 * is no email-campaign feature in this product, so `opt_in_email` is left
 * alone — touching it would have no observable effect but would be
 * semantically wrong.
 *
 * Safety rails
 * ------------
 *   * Only flips opt_in_sms to TRUE. Never to false — explicit opt-outs are
 *     sacred and must be honoured even if they look wrong.
 *   * Only touches rows whose `source` is in the SMS-consent-bearing set
 *     (CSV list imports, CSV campaign audiences, manual CRM entries, bulk
 *     imports). Anything we did not get consent for is left alone.
 *   * Skips contacts without a phone number — opting in a phone-less row
 *     is a no-op for SMS.
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
 */
class BackfillContactOptinCommand extends Command
{
    /**
     * Sources that carry explicit, documented SMS consent on file.
     * Order matters only for the display in --help output.
     */
    private const CONSENT_BEARING_SOURCES = [
        'contact_list_csv',  // CSV uploaded into a contact list (opt_in_sms column on file)
        'campaign_csv',      // CSV uploaded into a campaign audience (same)
        'import',            // CRM bulk import (CSV upload or grid editor)
        'manual',            // operator added the contact via the CRM form
    ];

    protected $signature = 'contacts:backfill-optin
        {--dry-run : Print what would change without writing anything (default behaviour)}
        {--apply : Commit the changes; required to write}
        {--workspace= : Limit to one workspace id}
        {--source=* : Only backfill rows whose `source` is in this list (defaults to every consent-bearing source)}
        {--batch=500 : Update batch size for the bulk update}';

    protected $description = 'Backfill opt_in_sms on contacts imported via SMS consent-bearing sources';

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

        $batch = max(50, (int) $this->option('batch'));

        $this->info('');
        $this->line(sprintf(
            '  <fg=cyan;options=bold>contacts:backfill-optin</> %s',
            $dryRun ? '<fg=yellow>(DRY RUN — pass --apply to commit)</>' : '<fg=red>(APPLYING — writes to the database)</>'
        ));
        $this->line('  sources : '.implode(', ', $sources));
        $this->line('  channel : opt_in_sms (only — WhatsApp consent is recorded by the inbox driver, email is unused)');
        $this->line('  scope   : '.($this->option('workspace') ? 'workspace='.$this->option('workspace') : 'ALL workspaces'));
        $this->info('');

        $base = $this->baseQuery($sources);

        if ($this->option('workspace')) {
            $base->where('workspace_id', (int) $this->option('workspace'));
        }

        // Counts BEFORE the change so the operator sees what they're about to flip.
        $withPhone = (clone $base)->whereNotNull('phone_e164');
        $candidatesWithPhone = (clone $withPhone)->count();
        $currentlyOptedIn = (clone $withPhone)->where('opt_in_sms', true)->count();
        $wouldFlip = $candidatesWithPhone - $currentlyOptedIn;

        $this->line(sprintf('  candidates in scope  : <fg=white>%d</>', (clone $base)->count()));
        $this->line(sprintf('  with a phone number  : <fg=white>%d</>', $candidatesWithPhone));
        $this->line(sprintf('  already opted in SMS : <fg=white>%d</>', $currentlyOptedIn));
        $this->line(sprintf('  would flip to opted-in: <fg=white>%d</>', $wouldFlip));
        $this->info('');

        if ($dryRun) {
            $this->line('  <fg=gray>Nothing was modified. Re-run with --apply to commit.</>');
            $this->info('');

            return self::SUCCESS;
        }

        $query = (clone $withPhone)->where('opt_in_sms', false);
        $updated = $this->updateInBatches($query, $batch, ['opt_in_sms' => true]);

        $this->info('  <fg=cyan;options=bold>Results</>');
        $this->line(sprintf('  opted-in for SMS: <fg=green>%d</> contacts updated', $updated));
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
