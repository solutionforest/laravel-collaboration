<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Alice Chan', 'Bob Lee', 'Chloe Wong'] as $name) {
            User::factory()->create(['name' => $name, 'email' => strtolower(strtok($name, ' ')).'@example.com']);
        }

        Post::create([
            'title' => 'Launching our new booking system',
            'summary' => 'What changes for customers, and when.',
            'body' => '<h2>Why we are changing</h2><p>Customers asked for <strong>online booking</strong> that works on any phone.</p><ul><li><p>Book in under a minute</p></li><li><p>Reminders by SMS</p></li></ul>',
            'status' => 'draft',
        ]);
    }
}
