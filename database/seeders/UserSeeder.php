<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([ 'name' => 'John Doe', 'email' => 'john@example.com', 'email_verified_at' => now(), 'password' => Hash::make('password'), ]);
        User::create([ 'name' => 'Jane Doe', 'email' => 'jane@example.com', 'email_verified_at' => now(), 'password' => Hash::make('password'), ]);
    }
}
