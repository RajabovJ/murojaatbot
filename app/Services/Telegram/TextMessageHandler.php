<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Models\Appeal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Exceptions\TelegramResponseException;

class TextMessageHandler
{
    public function handle(string $text, int $chatId): void
    {
        $update = Telegram::getWebhookUpdate();
        $message = $update->getMessage();


        $replyToMessage = $message->getReplyToMessage();

        // 1. Admin reply orqali foydalanuvchiga javob yuborilsa

        if ($replyToMessage && isAdmin($chatId)) {
            $replyText = $replyToMessage->getText();

            if (preg_match('/id(\d+)\./', $replyText, $matches)) {
                $appealId = $matches[1] ?? null;
                $appeal = \App\Models\Appeal::find($appealId);

                if ($appeal) {
                    try {
                        // Javob yuborish
                        Telegram::sendMessage([
                            'chat_id' => $appeal->user_id,
                            'text' => "💬 Sizga admin javob berdi:\n\n{$text}",
                            'reply_to_message_id' => $appeal->telegram_message_id
                        ]);

                        $appeal->update(['is_reviewed' => true]);

                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => "✅ Javob foydalanuvchiga yuborildi va murojaat ko‘rib chiqilgan deb belgilandi."
                        ]);

                        return;
                    } catch (TelegramResponseException $e) {
                        $errorMessage = $e->getMessage();

                        if (str_contains($errorMessage, 'message to be replied not found')) {
                            Telegram::sendMessage([
                                'chat_id' => $appeal->user_id,
                                'text' => "💬 [‼️ O‘chirilgan murojaatingizga javob]\n\n{$text}"
                            ]);

                            $appeal->update(['is_reviewed' => true]);

                            Telegram::sendMessage([
                                'chat_id' => $chatId,
                                'text' => "⚠️ Asl murojaat o‘chirilgan, ammo foydalanuvchiga oddiy javob yuborildi. Murojaat ko‘rib chiqilgan deb belgilandi."
                            ]);

                            return;
                        } else {
                            Telegram::sendMessage([
                                'chat_id' => $chatId,
                                'text' => "❗️ Javob yuborishda xatolik yuz berdi:\n" . $errorMessage
                            ]);

                            return;
                        }
                    }
                } else {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => "❗ Murojaat topilmadi. Ehtimol, noto‘g‘ri ID."
                    ]);
                    return;
                }
            } else {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "⚠️ Reply qilingan xabarda 'idXXX.' formatida appeal ID topilmadi."
                ]);
                return;
            }
        }





        $step = Cache::get("appeal_step_$chatId");

        if (mb_strtolower($text) === '🆕 yangi murojaat yuborish') {
            Cache::forget("appeal_step_$chatId");

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "Iltimos, qanday rol asosida murojaat yubormoqchisiz?",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '👨‍💼 Xodim sifatida murojaat', 'callback_data' => 'appeal_as_employee']],
                        [['text' => '👪 Ota-ona sifatida murojaat', 'callback_data' => 'appeal_as_parent']],
                        [['text' => '❓ Boshqa', 'callback_data' => 'appeal_as_other']],
                    ]
                ])
            ]);
            return;
        }

        if (!$step) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "❗ Iltimos, /start buyrug‘i bilan qayta boshlang."
            ]);
            return;
        }

        $from = $message->getFrom();
        $user = User::updateOrCreate(
            ['id' => $chatId],
            [
                'first_name'    => $from->getFirstName(),
                'username'      => $from->getUsername(),
                'language_code' => $from->getLanguageCode(),
            ]
        );

        if (in_array($step, ['employee_name', 'parent_name', 'other_name'])) {
            $role = match($step) {
                'employee_name' => 'employee',
                'parent_name'   => 'parent',
                'other_name'    => 'other',
            };

            $telegramMessageId = $message->getMessageId();

            $appeal = Appeal::create([
                'user_id'             => $user->id,
                'role'                => $role,
                'message'             => $text,
                'telegram_message_id' => $telegramMessageId,
                'is_reviewed'         => false,
            ]);

            if (!isAdmin($chatId)) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ Murojaatingiz muvaffaqiyatli qabul qilindi. Rahmat!",
                    'reply_markup' => json_encode([
                        'keyboard' => [
                            [['text' => '🆕 Yangi murojaat yuborish']]
                        ],
                        'resize_keyboard' => true,
                        'one_time_keyboard' => true
                    ])
                ]);
            } else {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ Murojaatingiz muvaffaqiyatli qabul qilindi. Rahmat!"
                ]);
            }


            foreach (User::where('role', 'admin')->get() as $admin) {
                // Foydalanuvchi username tayyorlash
                $usernameTag = $user->username ? '@' . $user->username : 'no_username';

                // Murojaat matni xavfsizlashtirish (HTML special chars)
                $safeMessage = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $roleUz = match ($appeal->role) {
                    'employee' => 'Xodim',
                    'parent' => 'Ota-ona',
                    'other' => 'Boshqa',
                };
                Telegram::sendMessage([
                    'chat_id' => $admin->id,
                    'text' => "📨 <b>Yangi murojaat:</b>\n"
                        . "<b>ID: {$appeal->id}</b>\n"
                        . "👤 <b>Ismi:</b> <i>{$user->first_name}</i>\n"
                        . "🔗 <b>Username:</b> <i>{$usernameTag}</i>\n\n"
                        . "🎭 <b>Rol:</b> <i>{$roleUz}</i>\n"
                        . "📝 {$safeMessage}",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                ['text' => '✅ Ko‘rib chiqildi', 'callback_data' => "mark_reviewed_{$appeal->id}"]
                            ]
                        ]
                    ])
                ]);

            }


            Cache::forget("appeal_step_$chatId");
        }
    }
}

if (!function_exists('escapeMarkdownV2')) {
    function escapeMarkdownV2(string $text): string
    {
        return preg_replace('/([_*\[\]()~`>#+=|{}.!\\\-])/', '\\\\$1', $text);
    }
}
