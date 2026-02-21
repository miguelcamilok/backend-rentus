<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Property;
use App\Models\User;

class PropertySeeder extends Seeder
{
    public function run(): void
    {
        if (User::count() === 0) {
            $this->command->error("No se pueden crear propiedades porque no existen usuarios. Ejecuta primero UserSeeder.");
            return;
        }

        User::all()->each(function ($user) {
            Property::factory(rand(1, 3))->create([
                'user_id' => $user->id
            ]);
        });

        $this->command->info("Propiedades creadas correctamente.");
    }
}
