<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePendingPaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pending_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->decimal('amount');            
            $table->string('transaction_code')->unique();   
            $table->unsignedBigInteger('market_place_id');
            $table->unsignedBigInteger('payment_method_id');
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('user_id');
            $table->text('comment')->nullable();
            $table->string('pop')->nullable();     
            $table->dateTime('expiration_time');        
            $table->integer('status')->default(2);
            $table->ipAddress('ipAddress');
            $table->softDeletes();   

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');            
            $table->foreign('market_place_id')->references('id')->on('market_places')->onDelete('cascade');
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->onDelete('cascade');
            $table->foreign('package_id')->references('id')->on('packages')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pending_payments');
    }
}
