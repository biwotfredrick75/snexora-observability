<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('suppliers', function (Blueprint $table) {

            $table->increments('supplierId');

            $table->string('supplierName',60)->default('');
            $table->string('supplierReference',100)->default('');
            $table->string('memberNumber',200)->unique();

            $table->integer('routeGroupId');

            $table->tinyText('address');
            $table->tinyText('supplierAddress');

            $table->string('gstNumber',25)->default('');
            $table->string('contactPerson',60)->default('');

            $table->string('supplierAccountNumber',40)->default('');
            $table->string('website',100)->default('');

            $table->string('bankAccount',60)->default('');

            $table->char('currencyCode',3);

            $table->integer('paymentTerms')->nullable();

            $table->boolean('taxIncluded')->default(false);

            $table->integer('dimensionId')->default(0);
            $table->integer('dimension2Id')->default(0);

            $table->integer('taxGroupId')->nullable();

            $table->decimal('creditLimit',18,2)->default(0);

            $table->string('purchaseAccount',15)->default('');
            $table->string('payableAccount',15)->default('');
            $table->string('paymentDiscountAccount',15)->default('');

            $table->tinyText('notes');

            $table->boolean('inactive')->default(false);

            $table->string('supplierType',200);
            $table->string('gender',200);

            $table->date('dateOfBirth');

            $table->string('bankNumber',200);
            $table->string('bankBranch',200);

            $table->string('idNumber',200);

            $table->string('nextOfKin',200);
            $table->string('nextOfKinId',200);
            $table->string('nextOfKinRelationship',200);

            $table->timestamp('createdAt')->useCurrent();

            $table->string('route',50);

            $table->boolean('deductNhif')->default(false);

            $table->decimal('packingSharesLimit',18,2);
            $table->decimal('savingsPerLitre',18,2)->default(0);

            $table->string('withholdingTaxAccount',100)->nullable();

            $table->string('nextOfKinTelephone',250)->nullable();

            $table->integer('source')->default(0);

            $table->integer('registrationFeeComplete')->default(0);

            $table->decimal('sharesLimit',18,2);

            $table->date('balanceMessageDate');

            $table->boolean('isVendor')->default(false);
            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index('supplierReference');
            $table->index('supplierAccountNumber');
            $table->index('memberNumber','idxSupplierMemberNumber');
            $table->index('supplierType','idxSupplierType');
            $table->index('supplierId','idxSupplierId');
        });
    }

    public function down()
    {
        Schema::dropIfExists('suppliers');
    }
}
