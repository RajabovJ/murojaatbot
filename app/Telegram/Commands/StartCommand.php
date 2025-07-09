<?php

namespace App\Telegram\Commands;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected string $description = 'Boshlanish komandasi';

    public function handle()
    {
        $message = Telegram::getWebhookUpdate()->getMessage();
        $from = $message->getFrom();

        $telegramId = $from->getId();
        $chatId = $message->getChat()->getId();
        $username = $from->getUsername();
        $firstName = $from->getFirstName();
        $lang = $from->getLanguageCode();

        // Foydalanuvchini bazaga yozamiz yoki yangilaymiz
        $user = User::firstOrNew(['id' => $telegramId]);

        $user->username      = $username;
        $user->first_name    = $firstName;
        $user->language_code = $lang;

        if (!$user->exists) {
            $user->role = 'user';
        }

        $user->save();

        // Keshni tozalaymiz
        Cache::forget("appeal_step_$chatId");
        Cache::forget("reply_step_$chatId");
        Cache::forget("user_message_id_$chatId");

        // 🛑 1. AGAR ADMIN BO'LSA
        if (isAdmin($chatId)) {
            // Oldingi inline xabarlarni o‘chirishga harakat qilamiz (agar bo‘lsa)
            try {
                $oldMsgId = Cache::get("admin_last_inline_msg_$chatId");
                if ($oldMsgId) {
                    Telegram::deleteMessage([
                        'chat_id' => $chatId,
                        'message_id' => $oldMsgId
                    ]);
                    Cache::forget("admin_last_inline_msg_$chatId");
                }
            } catch (\Exception $e) {
                // Log qilsa ham bo‘ladi, ammo xatolik berilmasin
            }

            // Admin uchun /adminsahifa tugmasi (doimiy reply keyboard)
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "👋 Salom, administrator! Siz admin paneldan foydalanishingiz mumkin.",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '/adminsahifa']],
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => false,
                ])
            ]);

            return;
        }

        // 👤 2. ODDIY FOYDALANUVCHIGA INLINE TUGMALAR
        $sentMsg = Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "👋 Salom, hurmatli foydalanuvchi! Botdan foydalanishingiz mumkin.\n\nMurojaat yuborish toifangizni tanlang!",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '👨‍💼 Xodim sifatida murojaat', 'callback_data' => 'appeal_as_employee']],
                    [['text' => '👪 Ota-ona sifatida murojaat', 'callback_data' => 'appeal_as_parent']],
                    [['text' => '❓ Boshqa', 'callback_data' => 'appeal_as_other']],
                ]
            ])
        ]);

        // Bu xabarni keyin o‘chirish uchun saqlab qo‘yamiz (admin bo‘lsa)
        if (isset($sentMsg['message_id'])) {
            Cache::put("admin_last_inline_msg_$chatId", $sentMsg['message_id'], now()->addMinutes(30));
        }
    }


}
