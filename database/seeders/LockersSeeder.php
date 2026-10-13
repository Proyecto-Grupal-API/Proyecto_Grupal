<?php

namespace Database\Seeders;

use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerPeriod;
use Illuminate\Database\Seeder;

class LockersSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPeriods();
        $this->seedLockers();
    }

    private function seedPeriods(): void
    {
        if (
            LockerPeriod::query()->count() > 0
        ) {
            $this->command->info(
                'locker_periods ya contiene datos.'
            );

            return;
        }

        LockerPeriod::create([
            'code' => '2026-B',

            'name' => 'Agosto - Diciembre 2026',

            'starts_at' => '2026-08-24 00:00:00',

            'ends_at' => '2026-12-18 23:59:59',

            'prices_cents' => [
                'small' => 15000,
                'medium' => 22000,
                'large' => 30000,
            ],

            'status' => 'active',
        ]);

        LockerPeriod::create([
            'code' => '2027-A',

            'name' => 'Enero - Junio 2027',

            'starts_at' => '2027-01-18 00:00:00',

            'ends_at' => '2027-06-11 23:59:59',

            'prices_cents' => [
                'small' => 15000,
                'medium' => 22000,
                'large' => 30000,
            ],

            'status' => 'active',
        ]);

        $this->command->info(
            'Periodos de lockers creados.'
        );
    }

    private function seedLockers(): void
    {
        if (
            Locker::query()->count() > 0
        ) {
            $this->command->info(
                'lockers ya contiene datos.'
            );

            return;
        }

        $layout = [
            [
                'building' => 'Edificio A',
                'zone' => 'Planta baja',
                'prefix' => 'A-PB',
                'size' => 'small',
                'count' => 6,
            ],

            [
                'building' => 'Edificio A',
                'zone' => 'Primer piso',
                'prefix' => 'A-P1',
                'size' => 'medium',
                'count' => 4,
            ],

            [
                'building' => 'Edificio B',
                'zone' => 'Planta baja',
                'prefix' => 'B-PB',
                'size' => 'small',
                'count' => 6,
            ],

            [
                'building' => 'Edificio B',
                'zone' => 'Primer piso',
                'prefix' => 'B-P1',
                'size' => 'large',
                'count' => 4,
            ],
        ];

        foreach ($layout as $group) {
            for (
                $i = 1;
                $i <= $group['count'];
                $i++
            ) {
                $code =
                    'LKR-'
                    .$group['prefix']
                    .'-'
                    .str_pad(
                        (string) $i,
                        3,
                        '0',
                        STR_PAD_LEFT
                    );

                Locker::create([
                    'code' => $code,

                    'qr_code' => 'QR-'.$code,

                    'building' => $group['building'],

                    'zone' => $group['zone'],

                    'size' => $group['size'],

                    'status' => 'available',

                    'notes' => null,
                ]);
            }
        }

        $maintenanceLocker =
            Locker::query()
                ->where(
                    'code',
                    'LKR-B-P1-004'
                )
                ->first();

        if (
            $maintenanceLocker !== null
        ) {
            $maintenanceLocker->update([
                'status' => 'maintenance',

                'notes' => 'Puerta atorada, pendiente de revisión.',
            ]);
        }

        $this->command->info(
            '20 lockers de ejemplo creados.'
        );
    }
}
