<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE gift_card_redemptions ADD UNIQUE gift_card_redemption_provider_evidence_unique (`provider_code`, `evidence_hash`)');
    }

    public function down(): void
    {
        if (DB::table('gift_card_redemptions')->exists()) {
            throw new RuntimeException('Cannot remove gift-card redemption evidence uniqueness after redemptions exist.');
        }
        DB::statement('ALTER TABLE gift_card_redemptions DROP INDEX gift_card_redemption_provider_evidence_unique');
    }
};
