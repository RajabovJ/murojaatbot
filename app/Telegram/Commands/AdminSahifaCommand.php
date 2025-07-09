<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

class AdminSahifaCommand extends Command
{
    protected string $name = 'adminsahifa';
    protected string $description = 'Admin panel bosh sahifasi';

    public function handle()
    {
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "👨‍💼 *Admin panelga xush kelibsiz!*\nQuyidagi bo‘limlardan birini tanlang:",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    // Yangi murojaatlar bo‘limi
                    [
                        ['text' => '🆕 Barcha yangilar', 'callback_data' => 'admin_new_appeals_all'],
                    ],
                    [
                        ['text' => '👪 Ota-onalar yangilari', 'callback_data' => 'admin_new_appeals_parent'],
                        ['text' => '👨‍💼 Xodimlar yangilari', 'callback_data' => 'admin_new_appeals_employee'],
                        ['text' => '❓ Boshqalar', 'callback_data' => 'admin_new_appeals_other'],
                    ],
                    // Boshqa bo‘limlar
                    [
                        ['text' => '📊 Statistika', 'callback_data' => 'admin_stats'],
                        ['text' => '📢 Xabar yuborish', 'callback_data' => 'admin_broadcast'],
                    ],
                    [
                        ['text' => '📋 Barcha murojaatlar', 'callback_data' => 'admin_all_appeals'],
                        ['text' => '⚙️ Sozlamalar', 'callback_data' => 'admin_settings'],
                    ]
                ]
            ])
        ]);
    }

}
