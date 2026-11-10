<?php

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

    'sheets' => [
        'service_account_path' => env('GOOGLE_SERVICE_ACCOUNT_PATH'),
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
    ],

    'crm' => [
        'initial_admin_email' => env('INITIAL_ADMIN_EMAIL'),
        'initial_admin_name' => env('INITIAL_ADMIN_NAME', 'Administrador TSF'),
    ],
];
