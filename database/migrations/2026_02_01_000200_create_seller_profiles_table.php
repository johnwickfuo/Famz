<?php

use App\Enums\BusinessType;
use App\Enums\SellerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('business_name');
            $table->string('slug')->unique();

            // CAC registration is optional: most market traders are not
            // registered, and requiring it would exclude the majority of this
            // platform's sellers.
            $table->string('cac_number', 32)->nullable();

            $table->enum('business_type', BusinessType::values());
            $table->text('address');
            $table->string('state', 64)->index();
            $table->string('lga', 96)->index();
            $table->string('phone', 32);
            $table->string('whatsapp', 32)->nullable();

            $table->string('id_document');
            $table->string('logo')->nullable();
            $table->text('description');

            $table->enum('status', SellerStatus::values())
                ->default(SellerStatus::Pending->value)
                ->index();

            // Why an application was rejected, or what is still needed.
            $table->text('review_notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Whether this seller's new listings skip review.
             *
             * null  — follow the platform rule (auto-approve once the seller has
             *         three approved listings behind them)
             * true  — an administrator has vouched for them; always auto-approve
             * false — an administrator has put them back under review regardless
             *         of track record
             */
            $table->boolean('auto_approve_products')->nullable();

            $table->timestamps();
        });

        // The categories an applicant says they intend to sell in. Kept separate
        // from the categories their products actually land in.
        Schema::create('category_seller_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->unique(['seller_profile_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_seller_profile');
        Schema::dropIfExists('seller_profiles');
    }
};
