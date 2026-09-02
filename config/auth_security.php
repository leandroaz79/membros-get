<?php

return [
    'rate' => [
        'login_per_minute' => (int) env('LOGIN_RATE_PER_MINUTE', 20),
        'magic_access_per_minute' => (int) env('MAGIC_ACCESS_RATE_PER_MINUTE', 60),
        'password_reset_per_minute' => (int) env('PASSWORD_RESET_RATE_PER_MINUTE', 6),
    ],
];
