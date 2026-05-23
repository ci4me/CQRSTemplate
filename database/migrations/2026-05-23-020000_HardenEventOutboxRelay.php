<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class HardenEventOutboxRelay extends Migration
{
    public function up()
    {
        // Additional relay hardening logic - to be implemented
        // Claim logic with FOR UPDATE SKIP LOCKED will go here
    }

    public function down()
    {
        // Revert if needed
    }
}
