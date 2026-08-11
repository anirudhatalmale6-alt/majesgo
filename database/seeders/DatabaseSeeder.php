<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Administrador principal (super admin)
        $admin = User::updateOrCreate(
            ['email' => 'rosmel_gh11@hotmail.com'],
            [
                'name'     => 'Administrador MajesGo',
                'password' => Hash::make('MajesGo2026'),
                'role'     => 'super_admin',
                'active'   => true,
            ]
        );

        // Configuración inicial de la plataforma
        $defaults = [
            'app_name'         => 'MajesGo',
            'app_slogan'       => 'Tu taxi en un toque.',
            'city'             => 'Majes - El Pedregal, Arequipa',
            'currency'         => 'S/',
            'fare_per_min'     => '1.00',   // tarifa: S/ por minuto de viaje
            'fare_min'         => '10.00',  // tarifa mínima (carreras de hasta 10 min)
            'commission_pct'   => '5',      // % de la tarifa que se lleva la app
            'commission_min'   => '0.00',   // piso opcional de comisión (0 = sin piso)
            'saldo_tiers'      => '20,50,100',
            // Datos de pago que ve el conductor al recargar (los llena la central en Configuración)
            'yape_number'      => '',
            'yape_holder'      => '',
            'plin_number'      => '',
            'plin_holder'      => '',
            'bank_name'        => '',
            'bank_account'     => '',
            'bank_cci'         => '',
            'bank_holder'      => '',
            'recharge_note'    => '',
            'min_saldo_alert'  => '5.00',
            'dispatch_radius_km' => '3.0',   // radio para avisar a conductores cercanos
            'demo_enabled'     => '1',       // conductor demo cuando no hay conductores reales conectados
            'require_photos'   => '1',       // exigir foto de rostro y vehículo aprobadas para conectarse
        ];
        foreach ($defaults as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }

        // Conductores de ejemplo (para la demostración del panel)
        $demo = [
            ['Carlos Ramírez Quispe', '982114455', 'Toyota', 'Yaris', 'V7A-482', 'Blanco', 'disponible', 'activo', 24.50, 4.9, 312],
            ['Luis Mamani Choque',     '984220117', 'Kia',    'Rio',   'A2C-108', 'Plateado', 'ocupado', 'activo', 8.00, 4.8, 190],
            ['Rosa Huamán Ccama',      '986550231', 'Hyundai','Accent','V1B-771', 'Blanco', 'desconectado', 'activo', 51.00, 5.0, 87],
            ['Jorge Ticona Apaza',     '981009922', 'Suzuki', 'Dzire', 'B4D-320', 'Negro',  'desconectado', 'suspendido', 0.00, 4.5, 45],
            ['Miguel Ccahua Flores',   '988771200', 'Toyota', 'Etios', 'C9F-654', 'Blanco', 'disponible', 'activo', 100.00, 4.7, 5],
        ];
        $i = 1;
        foreach ($demo as $d) {
            Driver::updateOrCreate(
                ['phone' => $d[1]],
                [
                    'code'           => 'MG-' . str_pad($i, 4, '0', STR_PAD_LEFT),
                    'full_name'      => $d[0],
                    'password'       => Hash::make('conductor123'),
                    'vehicle_make'   => $d[2],
                    'vehicle_model'  => $d[3],
                    'vehicle_plate'  => $d[4],
                    'vehicle_color'  => $d[5],
                    'status'         => $d[6],
                    'account_status' => $d[7],
                    'saldo'          => $d[8],
                    'rating'         => $d[9],
                    'total_trips'    => $d[10],
                    'created_by'     => $admin->id,
                    'last_active_at' => now()->subMinutes($i * 7),
                ]
            );
            $i++;
        }
    }
}
