<?php

use App\Models\User;

if (!function_exists('isAdmin')) {
    /**
     * Berilgan Telegram chat ID asosida foydalanuvchi admin ekanligini aniqlaydi.
     *
     * @param int $chatId Telegram foydalanuvchisining ID raqami
     * @return bool
     */
    function isAdmin(int $chatId): bool
    {
        return User::where('id', $chatId)->where('role', 'admin')->exists();
    }
}
if (!function_exists('escapeMarkdownV2')) {
    function escapeMarkdownV2(string $text): string
    {
        // MarkdownV2 uchun escape qilinishi kerak bo‘lgan belgilar
        $escapeChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($escapeChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }
}

