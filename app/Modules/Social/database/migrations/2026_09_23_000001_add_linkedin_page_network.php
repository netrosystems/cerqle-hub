<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the 'linkedin_page' network so a LinkedIn company page can be connected
 * alongside a member profile.
 *
 * They are separate networks rather than one network with a type flag because
 * LinkedIn treats them as separate products: posting as an organisation needs
 * the Community Management API and its own scopes, which are usually approved
 * against a different developer app than the member-profile one. Keeping them
 * apart lets an operator configure two sets of client credentials, and lets a
 * workspace connect a profile and a page at the same time — the unique key is
 * (workspace_id, network, account_id).
 */
return new class extends Migration
{
    private const WITH_PAGE = "'facebook','instagram','linkedin','linkedin_page','twitter','youtube','tiktok'";

    private const WITHOUT_PAGE = "'facebook','instagram','linkedin','twitter','youtube','tiktok'";

    public function up(): void
    {
        // sqlite stores this column as text with no enum constraint, so the
        // value is already accepted and there is nothing to alter.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE social_media_accounts MODIFY COLUMN network ENUM('.self::WITH_PAGE.') NOT NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        // Rows must leave the enum before it is narrowed or the MODIFY fails.
        // Disconnecting is the honest reversal: the member-profile app cannot
        // post as an organisation, so silently relabelling these as 'linkedin'
        // would leave a destination that fails at publish time.
        DB::table('social_media_accounts')->where('network', 'linkedin_page')->delete();

        DB::statement('ALTER TABLE social_media_accounts MODIFY COLUMN network ENUM('.self::WITHOUT_PAGE.') NOT NULL');
    }
};
