<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')]
        );

        $this->call([
            PlebSeeder::class,
            ProjectProposalSeeder::class,
            NostrSeeder::class,
            MeetupSeeder::class,
            CourseSeeder::class,
            NotificationSeeder::class,
            SecuritySeeder::class,
        ]);
    }
}
