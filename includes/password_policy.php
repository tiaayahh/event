<?php
if (!function_exists('password_policy_message')) {
    function password_policy_message(): string
    {
        return 'Use at least 8 characters with uppercase and lowercase letters, a number, and a special character.';
    }
}

if (!function_exists('password_policy_is_strong')) {
    function password_policy_is_strong(string $password, string $email = '', string $name = ''): bool
    {
        if (strlen($password) < 8) {
            return false;
        }

        if (!preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/\d/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)) {
            return false;
        }

        $lowerPassword = strtolower($password);
        $commonPasswords = [
            'password',
            'password1',
            'qwerty',
            'qwerty123',
            'admin123',
            'letmein',
            'welcome',
            'instagram',
            'planora',
            '12345678',
        ];

        foreach ($commonPasswords as $commonPassword) {
            if (str_contains($lowerPassword, $commonPassword)) {
                return false;
            }
        }

        $emailLocalPart = strtolower(strtok($email, '@') ?: '');
        if ($emailLocalPart !== '' && strlen($emailLocalPart) >= 4 && str_contains($lowerPassword, $emailLocalPart)) {
            return false;
        }

        foreach (preg_split('/\s+/', strtolower($name)) ?: [] as $namePart) {
            if (strlen($namePart) >= 4 && str_contains($lowerPassword, $namePart)) {
                return false;
            }
        }

        return true;
    }
}
?>
