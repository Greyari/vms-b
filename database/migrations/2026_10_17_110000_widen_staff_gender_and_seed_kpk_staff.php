<?php

use App\Models\Staff;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->string('gender', 20)->nullable()->change();
        });

        if (app()->environment('testing')) {
            return;
        }

        $kpkStaff = [
            [
                'staff_id' => 'KPK 2293',
                'name' => 'KPK_MUSLICHUN',
                'gender' => 'Male',
                'address' => 'ADMIN',
                'company' => 'PT. KARYA PUTRA KARIMUN',
                'is_active' => true,
            ],
            [
                'staff_id' => 'KPK 37985',
                'name' => 'KPK_SYAMSI DJUSNI',
                'gender' => 'Male',
                'address' => 'DIRECTOR',
                'company' => 'PT. KARYA PUTRA KARIMUN',
                'is_active' => true,
            ],
            [
                'staff_id' => 'KPK 54913',
                'name' => 'KPK_MARIANA',
                'gender' => 'Female',
                'address' => 'ADMIN',
                'company' => 'PT. KARYA PUTRA KARIMUN',
                'is_active' => true,
            ],
            [
                'staff_id' => 'KPK 2288',
                'name' => 'KPK_MUHAMMAD WARDANASYAH',
                'gender' => 'Male',
                'address' => 'ADMIN',
                'company' => 'PT. KARYA PUTRA KARIMUN',
                'is_active' => true,
            ],
            [
                'staff_id' => 'KPK 6221',
                'name' => 'KPK_HEMING',
                'gender' => 'Male',
                'address' => 'ADMIN',
                'company' => 'PT. KARYA PUTRA KARIMUN',
                'is_active' => true,
            ],
            [
                'staff_id' => 'KPK 2255',
                'name' => 'KPK_DARYANTO',
                'gender' => 'Male',
                'address' => 'MANAGER',
                'company' => 'PT. KARYA PUTRA KARIMUN',
                'is_active' => true,
            ],
            [
                'staff_id' => 'KPK 7075',
                'name' => 'KPK_KIE HUAT',
                'gender' => 'Male',
                'address' => 'SUPERVISOR',
                'company' => 'PT. KARYA PUTRA KARIMUN',
                'is_active' => true,
            ],
            [
                'staff_id' => 'KPK 9042',
                'name' => 'KPK_KENDY',
                'gender' => 'Male',
                'address' => 'ADMIN',
                'company' => 'PT. KARYA PUTRA KARIMUN',
                'is_active' => true,
            ],
        ];

        foreach ($kpkStaff as $row) {
            Staff::updateOrCreate(
                ['staff_id' => $row['staff_id']],
                $row
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->char('gender', 1)->nullable()->change();
        });
    }
};
