<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_workspace', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('staff');
            $table->timestamps();
            $table->unique(['invitation_id', 'workspace_id']);
        });

        // Pending legacy invitations did not contain a workspace choice. Keep
        // them least-privilege by assigning only the client's first workspace.
        DB::table('invitations')->whereNull('accepted_at')->orderBy('id')
            ->get(['id', 'client_id', 'created_at', 'updated_at'])
            ->each(function (object $invitation): void {
                $workspaceId = DB::table('workspaces')->where('client_id', $invitation->client_id)->orderBy('id')->value('id');
                if ($workspaceId) {
                    DB::table('invitation_workspace')->insert([
                        'invitation_id' => $invitation->id,
                        'workspace_id' => $workspaceId,
                        'role' => 'staff',
                        'created_at' => $invitation->created_at ?? now(),
                        'updated_at' => $invitation->updated_at ?? now(),
                    ]);
                }
            });

        // Older builds attached every client user to every workspace. Preserve
        // the workspace they were actively using (and any they own), but remove
        // inherited blanket access. Administrators can grant further access
        // deliberately from Team after this migration.
        DB::table('users')->whereNotNull('client_id')->orderBy('id')
            ->get(['id', 'client_id', 'workspace_id'])
            ->each(function (object $user): void {
                $ownedIds = DB::table('workspaces')
                    ->where('client_id', $user->client_id)
                    ->where('owner_id', $user->id)
                    ->pluck('id');

                $clientWorkspaceIds = DB::table('workspaces')
                    ->where('client_id', $user->client_id)
                    ->pluck('id');

                $keepIds = $ownedIds
                    ->when($user->workspace_id, fn ($ids) => $ids->push((int) $user->workspace_id))
                    ->filter(fn (int $workspaceId) => DB::table('workspaces')
                        ->where('id', $workspaceId)
                        ->where('client_id', $user->client_id)
                        ->exists())
                    ->unique()
                    ->values();

                // A malformed legacy record may have no valid active
                // workspace. Preserve least-privilege access to one workspace
                // rather than leaving that person unable to sign in.
                if ($keepIds->isEmpty() && $clientWorkspaceIds->isNotEmpty()) {
                    $keepIds = collect([(int) $clientWorkspaceIds->first()]);
                }

                $removeIds = $clientWorkspaceIds->diff($keepIds);

                if ($removeIds->isNotEmpty()) {
                    DB::table('workspace_user')
                        ->where('user_id', $user->id)
                        ->whereIn('workspace_id', $removeIds)
                        ->delete();
                }

                foreach ($keepIds as $workspaceId) {
                    $role = $ownedIds->contains($workspaceId) ? 'owner' : 'staff';
                    $pivot = DB::table('workspace_user')
                        ->where('workspace_id', $workspaceId)
                        ->where('user_id', $user->id);

                    if ($pivot->exists()) {
                        $pivot->update(['role' => $role, 'updated_at' => now()]);
                    } else {
                        DB::table('workspace_user')->insert([
                            'workspace_id' => $workspaceId,
                            'user_id' => $user->id,
                            'role' => $role,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_workspace');
    }
};
