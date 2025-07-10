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
        try {
            $update = Telegram::getWebhookUpdate();
            $message = $update->getMessage();

            if (!$message) return;

            $chatId = null;
            $chat = $message->getChat();
            if ($chat) {
                $chatId = $chat->getId();
            }
            $text = $message->getText();
            $replyToMessage = $message->getReplyToMessage();

            // Faqat admin reply qilgan bo‘lsa va reply mavjud bo‘lsa
            if ($replyToMessage && isAdmin($chatId)) {
                $replyText = $replyToMessage->getText();

                // ID: 123. formatidan appeal ID ni olish
                if (preg_match('/ID:\s*(\d+)\./i', $replyText, $matches)) {
                    $appealId = $matches[1] ?? null;
                    $appeal = \App\Models\Appeal::find($appealId);

                    if (!$appeal) {
                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => "❗ Murojaat topilmadi. Ehtimol, noto‘g‘ri ID."
                        ]);
                        return;
                    }

                    try {
                        // Asl reply mavjud bo‘lsa, reply bilan javob beriladi
                        Telegram::sendMessage([
                            'chat_id' => $appeal->user_id,
                            'text' => "💬 Sizga admin javob berdi:\n\n{$text}",
                            'reply_to_message_id' => $appeal->telegram_message_id
                        ]);
                    } catch (TelegramResponseException $e) {
                        $error = $e->getMessage();

                        if (stripos($error, 'bot was blocked by the user') !== false) {
                            Telegram::sendMessage([
                                'chat_id' => $chatId,
                                'text' => "❌ Foydalanuvchi botni bloklagan. Javob yuborilmadi."
                            ]);
                            return;
                        }

                        if (stripos($error, 'message to be replied not found') !== false) {
                            // Asl reply topilmasa oddiy xabar yuboriladi
                            Telegram::sendMessage([
                                'chat_id' => $appeal->user_id,
                                'text' => "💬 [‼️ O‘chirilgan murojaatingizga javob]\n\n{$text}"
                            ]);

                            Telegram::sendMessage([
                                'chat_id' => $chatId,
                                'text' => "⚠️ Asl murojaat o‘chirilgan, ammo foydalanuvchiga oddiy javob yuborildi."
                            ]);
                        } else {
                            // Boshqa xatolik
                            Telegram::sendMessage([
                                'chat_id' => $chatId,
                                'text' => "❗️ Javob yuborishda xatolik:\n{$error}"
                            ]);
                            return;
                        }
                    }

                    // Har qanday holatda murojaatni ko‘rib chiqilgan deb belgilash
                    $appeal->update(['is_reviewed' => true]);

                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => "✅ Javob yuborildi va murojaat ko‘rib chiqilgan deb belgilandi."
                    ]);
                    return;

                } else {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => "⚠️ Reply qilingan xabarda 'ID: XXX.' formatida appeal ID topilmadi."
                    ]);
                    return;
                }
            }
        } catch (\Throwable $e) {
            // Har qanday istisnolarni tutib logga yozish yoki xatolikni xabar qilish
            Log::error('Telegram handle() xatolik: ' . $e->getMessage());

            // Ixtiyoriy: admin chatga xatolik yuborish
            // Telegram::sendMessage([
            //     'chat_id' => 'ADMIN_CHAT_ID',
            //     'text' => "❌ Ichki xatolik: " . $e->getMessage()
            // ]);
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
            if (isAdmin($chatId)) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "🔐 Siz adminsiz. Davom etish uchun /adminsahifa buyrug‘idan foydalaning."
                ]);
            } else {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❗ Iltimos, /start buyrug‘i bilan qayta boshlang."
                ]);
            }
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
                        . "<b>ID: {$appeal->id}</b>.\n"
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
