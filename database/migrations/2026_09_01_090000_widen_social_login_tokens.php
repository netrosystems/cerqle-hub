<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->longText('access_token')->nullable()->change();
            $table->longText('refresh_token')->nullable()->change();
        });

        DB::table('social_accounts')
            ->select(['id', 'access_token', 'refresh_token'])
            ->orderBy('id')
            ->chunkById(100, function ($accounts): void {
                foreach ($accounts as $account) {
                    $updates = [];

                    foreach (['access_token', 'refresh_token'] as $column) {
                        $value = $account->{$column};

                        if (blank($value) || $this->isEncrypted($value)) {
                            continue;
                        }

                        $updates[$column] = Crypt::encryptString($value);
                    }

                    if ($updates !== []) {
                        DB::table('social_accounts')->where('id', $account->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('access_token')->nullable()->change();
            $table->string('refresh_token')->nullable()->change();
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
