<?php

namespace Tests\Feature\Console;

use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The backfill command is a one-shot data-fix tool. We test the safety
 * rails hard because a stray opt-in can trigger a real SMS send later.
 */
class BackfillContactOptinCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_the_default_and_changes_nothing(): void
    {
        $this->makeContact(['source' => 'contact_list_csv', 'opt_in_sms' => false]);

        $this->artisan('contacts:backfill-optin')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertDatabaseHas('contacts', [
            'source' => 'contact_list_csv',
            'opt_in_sms' => 0,
        ]);
    }

    public function test_apply_flips_consent_bearing_sources_to_opted_in(): void
    {
        $csv = $this->makeContact(['source' => 'contact_list_csv', 'opt_in_sms' => false]);
        $campaignCsv = $this->makeContact(['source' => 'campaign_csv', 'opt_in_sms' => false]);
        $manual = $this->makeContact(['source' => 'manual', 'opt_in_sms' => false]);
        $bulkImport = $this->makeContact(['source' => 'import', 'opt_in_sms' => false]);
        // Already opted in: should stay true and not be touched.
        $alreadyIn = $this->makeContact(['source' => 'contact_list_csv', 'opt_in_sms' => true]);

        $this->artisan('contacts:backfill-optin', ['--apply' => true])
            ->assertExitCode(0);

        foreach ([$csv, $campaignCsv, $manual, $bulkImport] as $row) {
            $this->assertSame(1, (int) $row->fresh()->opt_in_sms, "row {$row->id} should be opted in");
        }
        $this->assertSame(1, (int) $alreadyIn->fresh()->opt_in_sms);
    }

    public function test_non_consent_sources_are_left_alone(): void
    {
        $whatsapp = $this->makeContact(['source' => 'whatsapp_inbound', 'opt_in_sms' => false]);
        $webchat = $this->makeContact(['source' => 'webchat', 'opt_in_sms' => false]);
        $shopify = $this->makeContact(['source' => 'shopify', 'opt_in_sms' => false]);

        $this->artisan('contacts:backfill-optin', ['--apply' => true])
            ->assertExitCode(0);

        foreach ([$whatsapp, $webchat, $shopify] as $row) {
            $this->assertSame(0, (int) $row->fresh()->opt_in_sms, "non-consent row {$row->id} must not be flipped");
        }
    }

    public function test_does_not_opt_in_rows_without_a_phone_number(): void
    {
        $noPhone = $this->makeContact([
            'source' => 'contact_list_csv',
            'opt_in_sms' => false,
            'phone_e164' => null,
        ]);

        $this->artisan('contacts:backfill-optin', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(0, (int) $noPhone->fresh()->opt_in_sms);
    }

    public function test_includes_campaign_only_contact_list_csv_rows(): void
    {
        // A contact_list_csv upload IS the operator's campaign-ready list.
        // The original CSV is the documented SMS consent on file, so these
        // rows MUST be flipped even though is_campaign_only = true. They
        // would otherwise be silently invisible to SMS campaigns.
        $campaignOnlyCsv = $this->makeContact([
            'source' => 'contact_list_csv',
            'opt_in_sms' => false,
            'is_campaign_only' => true,
        ]);
        // Sanity: a campaign-only row from a non-consent source is still
        // excluded — we filter by `source`, not by the is_campaign_only flag.
        $campaignOnlyOther = $this->makeContact([
            'source' => 'whatsapp_inbound',
            'opt_in_sms' => false,
            'is_campaign_only' => true,
        ]);

        $this->artisan('contacts:backfill-optin', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(1, (int) $campaignOnlyCsv->fresh()->opt_in_sms,
            'contact_list_csv campaign-only row must be opted in');
        $this->assertSame(0, (int) $campaignOnlyOther->fresh()->opt_in_sms,
            'non-consent source must remain untouched');
    }

    public function test_workspace_filter_limits_scope(): void
    {
        $this->makeContact(['source' => 'contact_list_csv', 'opt_in_sms' => false, 'workspace_id' => 1]);
        $rowInOther = $this->makeContact(['source' => 'contact_list_csv', 'opt_in_sms' => false, 'workspace_id' => 2]);

        $this->artisan('contacts:backfill-optin', [
            '--apply' => true,
            '--workspace' => 1,
        ])->assertExitCode(0);

        $this->assertSame(0, (int) $rowInOther->fresh()->opt_in_sms);
    }

    public function test_source_filter_overrides_default_consent_set(): void
    {
        $manual = $this->makeContact(['source' => 'manual', 'opt_in_sms' => false]);
        $csv = $this->makeContact(['source' => 'contact_list_csv', 'opt_in_sms' => false]);

        $this->artisan('contacts:backfill-optin', [
            '--apply' => true,
            '--source' => ['manual'],
        ])->assertExitCode(0);

        $this->assertSame(1, (int) $manual->fresh()->opt_in_sms);
        $this->assertSame(0, (int) $csv->fresh()->opt_in_sms);
    }

    public function test_only_touches_opt_in_sms_and_leaves_whatsapp_and_email_alone(): void
    {
        // WhatsApp and email consent are independent channels. The backfill
        // is only valid for SMS (the only channel with documented consent
        // on file for these source values), so opt_in_whatsapp and
        // opt_in_email must never be flipped by this command.
        $row = $this->makeContact([
            'source' => 'contact_list_csv',
            'opt_in_sms' => false,
            'opt_in_whatsapp' => false,
            'opt_in_email' => false,
        ]);

        $this->artisan('contacts:backfill-optin', ['--apply' => true])
            ->assertExitCode(0);

        $row->refresh();
        $this->assertSame(1, (int) $row->opt_in_sms, 'opt_in_sms should be flipped');
        $this->assertSame(0, (int) $row->opt_in_whatsapp, 'opt_in_whatsapp must NOT be touched by SMS backfill');
        $this->assertSame(0, (int) $row->opt_in_email, 'opt_in_email must NOT be touched by SMS backfill');
    }

    public function test_a_contact_with_no_source_is_left_untouched(): void
    {
        // Sanity check: a contact whose `source` is not in the consent-bearing
        // set should not be flipped, even when opt_in_sms is false. This
        // guards against accidentally widening the scope of the command.
        $row = $this->makeContact([
            'source' => 'whatsapp_inbound',
            'opt_in_sms' => false,
            'opt_in_whatsapp' => true,
            'opt_in_email' => false,
        ]);

        $this->artisan('contacts:backfill-optin', ['--apply' => true])
            ->assertExitCode(0);

        $row->refresh();
        $this->assertSame(0, (int) $row->opt_in_sms, 'source whatsapp_inbound is not SMS-consent-bearing');
        $this->assertSame(1, (int) $row->opt_in_whatsapp, 'opt_in_whatsapp must remain untouched');
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        $this->makeContact(['source' => 'contact_list_csv', 'opt_in_sms' => false]);
        $this->makeContact(['source' => 'contact_list_csv', 'opt_in_sms' => false]);

        $this->artisan('contacts:backfill-optin', ['--apply' => true])->assertExitCode(0);
        $afterFirst = Contact::where('source', 'contact_list_csv')->where('opt_in_sms', true)->count();

        $this->artisan('contacts:backfill-optin', ['--apply' => true])->assertExitCode(0);
        $afterSecond = Contact::where('source', 'contact_list_csv')->where('opt_in_sms', true)->count();

        $this->assertSame(2, $afterFirst);
        $this->assertSame(2, $afterSecond);
    }

    public function test_unknown_source_rejects_invocation(): void
    {
        $this->artisan('contacts:backfill-optin', [
            '--apply' => true,
            '--source' => ['does_not_exist'],
        ])
            ->expectsOutputToContain('No valid sources selected')
            ->assertExitCode(2);
    }

    /**
     * Minimal helper: avoids the factory's `opt_in_sms = true` default by
     * creating the row directly through the model and overriding what we
     * care about for each scenario.
     */
    private function makeContact(array $overrides = []): Contact
    {
        $defaults = [
            'workspace_id' => 1,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'phone_e164' => '+9617'.random_int(1000000, 9999999),
            'email' => 'contact+'.uniqid('', true).'@example.test',
            'opt_in_whatsapp' => false,
            'opt_in_sms' => false,
            'opt_in_email' => false,
            'is_campaign_only' => false,
            'source' => 'contact_list_csv',
        ];

        return Contact::create(array_merge($defaults, $overrides));
    }
}
