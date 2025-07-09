<?php

namespace App\Providers;

use App\Telegram\Commands\AdminSahifaCommand;
use Illuminate\Support\ServiceProvider;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Telegram\Commands\StartCommand;

class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Telegram::addCommand(StartCommand::class);
        Telegram::addCommand(AdminSahifaCommand::class);
    }
}
