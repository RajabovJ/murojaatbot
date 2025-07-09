<?php

namespace App\Http\Controllers;

use App\Models\Appeal;
use App\Models\User;
use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\Telegram\TextMessageHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Keyboard\Keyboard;

class TelegramBotController extends Controller
{
    private array $requiredChannels = [
        [
            'username' => '@informatikarajabov',
            'link' => 'https://t.me/informatikarajabov'
        ],
        [
            'username' => '@ic3globalstandart',
            'link' => 'https://t.me/ic3globalstandart'
        ]
    ];
    // public function handle()
    // {
    //     $update = Telegram::getWebhookUpdate();

    //     // 1. Chat ID ni aniqlaymiz
    //     $chatId = null;

    //     if ($update->isType('message')) {
    //         $chatId = $update->getMessage()->getChat()->getId();
    //     } elseif ($update->isType('callback_query')) {
    //         $chatId = $update->getCallbackQuery()->getMessage()->getChat()->getId();
    //     }

    //     if (!$chatId) {
    //         return; // Noto‘g‘ri yoki kerakmas update
    //     }

    //     // 2. Kanalga a'zo bo'lishni tekshiramiz
    //     if (!$this->checkMembership($chatId)) {
    //         $this->askToJoinChannels($chatId);
    //         return;
    //     }

    //     // 3. CALLBACK tugmalar
    //     if ($update->isType('callback_query')) {
    //         $callbackData = $update->getCallbackQuery()->getData();

    //         // Admin uchun maxsus callbacklar
    //         if (
    //             str_starts_with($callbackData, 'admin_') ||
    //             str_starts_with($callbackData, 'mark_reviewed_')
    //         ) {
    //             if (!isAdmin($chatId)) {
    //                 Telegram::sendMessage([
    //                     'chat_id' => $chatId,
    //                     'text' => "⛔ Sizda bu amalni bajarish huquqi yo‘q.",
    //                 ]);
    //                 return;
    //             }
    //         }

    //         $this->handleCallback($callbackData, $chatId);
    //         return;
    //     }

    //     // 4. Komandalarni tekshirish (masalan: /start, /adminsahifa)
    //     if ($update->isType('message') && str_starts_with($update->getMessage()->getText(), '/')) {
    //         $command = strtolower($update->getMessage()->getText());

    //         // faqat adminlar uchun maxsus komanda
    //         if ($command === '/adminsahifa' && !isAdmin($chatId)) {
    //             Telegram::sendMessage([
    //                 'chat_id' => $chatId,
    //                 'text' => "⛔ Ushbu bo‘lim faqat administratorlar uchun.",
    //             ]);
    //             return;
    //         }

    //         Telegram::commandsHandler(true);
    //         return;
    //     }

    //     // 5. Odatdagi matnli xabarlar
    //     if ($update->isType('message')) {
    //         $text = $update->getMessage()->getText();
    //         app(TextMessageHandler::class)->handle($text, $chatId);
    //     }
    // }
    public function handle()
    {
        $update = Telegram::getWebhookUpdate();

        $chatId = null;

        if ($update->isType('message')) {
            $chatId = $update->getMessage()->getChat()->getId();
        } elseif ($update->isType('callback_query')) {
            $chatId = $update->getCallbackQuery()->getMessage()->getChat()->getId();
        }

        if (!$chatId) {
            return;
        }
        if (!$this->checkMembership($chatId)) {
            $this->askToJoinChannels($chatId);
            return; // A’zo bo‘lmagani uchun to‘xtaymiz
        }


        // 1. Agar admin forward uchun post yuborgan bo‘lsa
        if (isAdmin($chatId)  && Cache::get("broadcast_waiting_message_from_$chatId")) {
            $messageId = $update->getMessage()->getMessageId();

            Cache::forget("broadcast_waiting_message_from_$chatId");

            $users = User::where('role', '!=', 'admin')->pluck('id');

            foreach ($users as $userId) {
                try {
                    Telegram::copyMessage([
                        'chat_id' => $userId,
                        'from_chat_id' => $chatId, // admindan olamiz
                        'message_id' => $messageId,
                    ]);
                } catch (\Exception $e) {
                    Log::warning("📛 Foydalanuvchiga forward qilinmadi ($userId): " . $e->getMessage());
                }
            }

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ Reklama barcha foydalanuvchilarga forward qilindi."
            ]);

            return;
        }

        // 2. Callback querylar
        if ($update->isType('callback_query')) {
            $callbackData = $update->getCallbackQuery()->getData();

            // Admin uchun maxsus
            if (str_starts_with($callbackData, 'admin_') && !isAdmin($chatId)) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "⛔ Sizda bu amalni bajarish huquqi yo‘q."
                ]);
                return;
            }

            $this->handleCallback($callbackData, $chatId);
            return;
        }

        // 3. Komandalar
        if ($update->isType('message') && str_starts_with($update->getMessage()->getText(), '/')) {
            $command = strtolower($update->getMessage()->getText());

            if ($command === '/adminsahifa' && !isAdmin($chatId)) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "⛔ Bu buyruq faqat adminlar uchun."
                ]);
                return;
            }

            Telegram::commandsHandler(true);
            return;
        }

        // 4. Oddiy matnli xabarlar
        if ($update->isType('message')) {
            $text = $update->getMessage()->getText();
            if ($text) {
                app(TextMessageHandler::class)->handle($text, $chatId);
            }
        }
    }





        public function handleCallback(string $callbackData, int $chatId): void
        {
            // ➤ Foydalanuvchi murojaat rolini tanlasa
            if (str_starts_with($callbackData, 'appeal_as_')) {
                $this->handleRoleSelection($callbackData, $chatId);
                return;
            }

            // ➤ Kanalga obuna bo‘lganlikni tekshirish
            if ($callbackData === 'check_subscription') {
                $this->handleSubscriptionCheck($chatId);
                return;
            }

            // ➤ Murojaat ko‘rib chiqildi tugmasi
            if (str_starts_with($callbackData, 'mark_reviewed_')) {
                $this->handleMarkReviewed($callbackData, $chatId);
                return;
            }

            // ➤ Admin tugmalari
            if (isAdmin($chatId)) {
                $this->handleAdminCallbacks($callbackData, $chatId);
                return;
            }

            // ➤ Noma’lum callback
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "⚠️ Noma’lum amal tanlandi. Iltimos, qaytadan urinib ko‘ring."
            ]);
        }

        protected function handleRoleSelection(string $callbackData, int $chatId): void
        {
            $roles = [
                'appeal_as_employee' => [
                    'step' => 'employee_name',
                    'text' => '📝 Xodim sifatida murojaat qilish tanlandi.'
                ],
                'appeal_as_parent' => [
                    'step' => 'parent_name',
                    'text' => '📝 Ota-ona sifatida murojaat qilish tanlandi.'
                ],
                'appeal_as_other' => [
                    'step' => 'other_name',
                    'text' => '📝 Boshqa turdagi murojaat tanlandi.'
                ],
            ];

            if (isset($roles[$callbackData])) {
                $step = $roles[$callbackData]['step'];
                $message = $roles[$callbackData]['text'];

                Cache::put("appeal_step_$chatId", $step);

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message . "\n\n✏️ Iltimos, murojaatingizni bitta xabarda yozib yuboring.",
                    'reply_markup' => json_encode([
                        'remove_keyboard' => true
                    ])
                ]);
            } else {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "⚠️ Noma’lum murojaat turi tanlandi."
                ]);
            }
        }


        protected function handleSubscriptionCheck(int $chatId): void
        {
            $update = Telegram::getWebhookUpdate();
            $from = $update->getCallbackQuery()?->getFrom();

            if ($from) {
                $telegramId = $from->getId();
                $username = $from->getUsername();
                $firstName = $from->getFirstName();
                $lang = $from->getLanguageCode();

                // Foydalanuvchini bazaga yozamiz yoki yangilaymiz
                $user = User::firstOrNew(['id' => $telegramId]);
                $user->username = $username;
                $user->first_name = $firstName;
                $user->language_code = $lang;

                if (!$user->exists) {
                    $user->role = 'user';
                }

                $user->save();
            }

            // Kanalga a'zo bo‘lganligini tekshiramiz
            if (!$this->checkMembership($chatId)) {
                $this->askToJoinChannels($chatId);
            } else {
                if (isAdmin($chatId)) {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => "✅ Siz barcha kanallarga a’zo bo‘lgansiz. Admin paneldan foydalanishingiz mumkin.",
                        'reply_markup' => json_encode([
                            'keyboard' => [
                                [['text' => '/adminsahifa']]
                            ],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => false
                        ])
                    ]);
                } else {
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => "✅ Siz barcha kanallarga a’zo bo‘lgansiz. Endi botdan foydalanishingiz mumkin.\n\nQuyidagilardan birini tanlang:",
                        'reply_markup' => json_encode([
                            'inline_keyboard' => [
                                [['text' => '👨‍💼 Xodim sifatida murojaat', 'callback_data' => 'appeal_as_employee']],
                                [['text' => '👪 Ota-ona sifatida murojaat', 'callback_data' => 'appeal_as_parent']],
                                [['text' => '❓ Boshqa', 'callback_data' => 'appeal_as_other']],
                            ]
                        ])
                    ]);
                }
            }
        }



        protected function handleMarkReviewed(string $callbackData, int $chatId): void
        {
            $appealId = (int)str_replace('mark_reviewed_', '', $callbackData);
            $appeal = Appeal::find($appealId);

            if ($appeal && !$appeal->is_reviewed) {
                $appeal->is_reviewed = true;
                $appeal->save();

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ <b>id{$appealId}</b> murojaat ko‘rib chiqilgan sifatida belgilandi.",
                    'parse_mode' => 'HTML',
                ]);
            } else {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "⚠️ <b>id{$appealId}</b> murojaat allaqachon ko‘rib chiqilgan yoki topilmadi.",
                    'parse_mode' => 'HTML',
                ]);
            }
        }


        protected function handleAdminCallbacks(string $callbackData, int $chatId): void
        {
            switch ($callbackData) {
                case 'admin_new_appeals_all':
                    $this->sendNewAppeals($chatId); // barcha yangilar
                    break;

                case 'admin_new_appeals_parent':
                    $this->sendNewAppeals($chatId, 'parent');
                    break;

                case 'admin_new_appeals_employee':
                    $this->sendNewAppeals($chatId, 'employee');
                    break;

                case 'admin_new_appeals_other':
                    $this->sendNewAppeals($chatId, 'other');
                    break;

                case 'admin_stats':
                    $this->sendStats($chatId); // statistikani yuborish uchun funksiya
                    break;

                case 'admin_broadcast':
                    $this->startBroadcast($chatId); // admin xabar yuborish bosqichi
                    break;

                case 'admin_all_appeals':
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => "📋 Barcha murojaatlarni ko‘rish uchun web saytdan foydalanish mumkin."
                    ]);
                    break;

                case 'admin_settings':
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => "⚙️ Sozlamalar bo‘limi hozircha mavjud emas."
                    ]);
                    break;

                default:
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => "❗ Noma'lum admin buyruq: $callbackData"
                    ]);
                    break;
            }
        }
        protected function sendNewAppeals(int $chatId, ?string $role = null): void
        {
            $query = \App\Models\Appeal::where('is_reviewed', false);

            if ($role) {
                $query->where('role', $role);
            }

            $appeals = $query->latest()->take(10)->get();

            if ($appeals->isEmpty()) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ Hozircha yangi murojaatlar yo‘q."
                ]);
                return;
            }

            foreach ($appeals as $appeal) {
                $user = $appeal->user;
                $usernameTag = $user->username ? '@' . $user->username : 'no username';
                $safeMessage = htmlspecialchars($appeal->message, ENT_QUOTES, 'UTF-8');
                $roleUz = match ($appeal->role) {
                    'employee' => 'Xodim',
                    'parent' => 'Ota-ona',
                    'other' => 'Boshqa',
                };

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "📨 <b>Yangi murojaat:</b>\n"
                        . "<b>ID: {$appeal->id}</b>\n"
                        . "👤 <b>Ismi:</b> <i>{$user->first_name}</i>\n"
                        . "🔗 <b>Username:</b> <i>{$usernameTag}</i>\n\n"
                        . "🎭 <b>Rol:</b> <i>{$roleUz}</i>\n"
                        . "📝 {$safeMessage}",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '✅ Ko‘rib chiqildi', 'callback_data' => "mark_reviewed_{$appeal->id}"]]
                        ]
                    ])
                ]);
            }
        }

        protected function sendStats(int $chatId): void
        {
            $now = now(); // Hozirgi vaqt

            // Umumiy statistikalar
            $totalUsers = User::count();
            $totalAppeals = Appeal::count();

            // Rollar bo‘yicha murojaatlar
            $employeeTotal = Appeal::where('role', 'employee')->count();
            $employeeReviewed = Appeal::where('role', 'employee')->where('is_reviewed', true)->count();
            $employeePending = $employeeTotal - $employeeReviewed;

            $parentTotal = Appeal::where('role', 'parent')->count();
            $parentReviewed = Appeal::where('role', 'parent')->where('is_reviewed', true)->count();
            $parentPending = $parentTotal - $parentReviewed;

            $otherTotal = Appeal::where('role', 'other')->count();
            $otherReviewed = Appeal::where('role', 'other')->where('is_reviewed', true)->count();
            $otherPending = $otherTotal - $otherReviewed;

            // Vaqt bo‘yicha statistikalar
            $todayCount = Appeal::where('created_at', '>=', $now->copy()->startOfDay())->count();
            $last24hCount = Appeal::where('created_at', '>=', $now->copy()->subHours(24))->count();
            $last7dCount = Appeal::where('created_at', '>=', $now->copy()->subDays(7))->count();
            $last1yCount = Appeal::where('created_at', '>=', $now->copy()->subYear())->count();

            // Statistika vaqti formatlangan
            $timestamp = $now->format('Y-m-d H:i:s');

            // Xabarni yig’amiz
            $message = "📊 <b>Bot statistikasi</b>\n"
                . "🕒 <i>Statistika olindi:</i> <code>{$timestamp}</code>\n\n"

                . "👥 <b>Foydalanuvchilar soni:</b> {$totalUsers}\n"
                . "📨 <b>Jami murojaatlar:</b> {$totalAppeals}\n\n"

                . "🎭 <b>Rollar bo‘yicha murojaatlar:</b>\n"
                . "👨‍💼 <b>Xodimlar:</b> {$employeeTotal} ta\n"
                . " ✅ Ko‘rib chiqilgan: {$employeeReviewed}\n"
                . " 🕓 Kutayotgan: {$employeePending}\n\n"

                . "👪 <b>Ota-onalar:</b> {$parentTotal} ta\n"
                . " ✅ Ko‘rib chiqilgan: {$parentReviewed}\n"
                . " 🕓 Kutayotgan: {$parentPending}\n\n"

                . "❓ <b>Boshqalar:</b> {$otherTotal} ta\n"
                . " ✅ Ko‘rib chiqilgan: {$otherReviewed}\n"
                . " 🕓 Kutayotgan: {$otherPending}\n\n"

                . "📅 <b>Vaqt bo‘yicha murojaatlar:</b>\n"
                . "📆 Bugun: {$todayCount}\n"
                . "⏱️ Oxirgi 24 soat: {$last24hCount}\n"
                . "🗓️ Oxirgi 7 kun: {$last7dCount}\n"
                . "📈 Oxirgi 1 yil: {$last1yCount}";

            // Yuborish
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML'
            ]);
        }



        protected function startBroadcast(int $chatId): void
        {
            Cache::put("broadcast_waiting_message_from_$chatId", true, now()->addMinutes(10));

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "📢 Reklama postini yuboring. U matn, rasm yoki boshqa ko‘rinishda bo‘lishi mumkin — biz uni barcha foydalanuvchilarga forward qilamiz.",
            ]);
        }












        /**
         * Kanallarga a’zolikni tekshiradi
         */
        private function checkMembership(int $chatId): bool
        {
            foreach ($this->requiredChannels as $channel) {
                try {
                    $member = Telegram::getChatMember([
                        'chat_id' => $channel['username'],
                        'user_id' => $chatId
                    ]);
                    $status = $member->get('status');

                    if (!in_array($status, ['member', 'administrator', 'creator'])) {
                        return false;
                    }

                } catch (\Exception $e) {
                    Log::error("Telegram membership check failed for {$channel['username']}: " . $e->getMessage());
                    return false;
                }
            }

            return true;
        }










        /**
         * A’zo bo‘lmagan foydalanuvchiga kanalga o‘tish uchun tugma bilan xabar jo‘natadi
         */
        private function askToJoinChannels(int $chatId): void
        {
        $text = "🛑 Botdan foydalanish uchun quyidagi kanallarga a’zo bo‘ling va Tekshirish tugmasini bosing";

        $buttons = [];

        foreach ($this->requiredChannels as $channel) {
            $text .= "\n📢 " . $channel['username'];
            $buttons[] = [
                [
                    'text' => "🔗 A’zo bo‘lish: " . $channel['username'],
                    'url' => $channel['link']
                ]
            ];
        }

        // Tekshirish tugmasini alohida qatorga qo‘shamiz
        $buttons[] = [
            [
                'text' => "✅ Tekshirish",
                'callback_data' => "check_subscription"
            ]
        ];

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode([
            'inline_keyboard' => $buttons
            ])
        ]);
        }



    }
