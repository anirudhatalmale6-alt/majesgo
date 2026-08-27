<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_KEY'),
    ],

    'webpush' => [
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject'     => env('VAPID_SUBJECT', 'mailto:soporte@majesgo.pe'),
    ],

    // Firebase Cloud Messaging (push nativo para las apps de Play Store)
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS', storage_path('app/firebase/serviceAccount.json')),
        'project_id'  => env('FCM_PROJECT_ID', 'majesgo-8c09e'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    /*
     | Llave de los mapas base de CARTO. Desde 2026 los mosaicos sin llave se sirven
     | con la marca de agua "API KEY REQUIRED" encima. Es gratis (5 M de mosaicos al
     | mes) y se pide en carto.com/basemaps/apikey. Si está vacía la app sigue
     | funcionando igual, solo que con la marca de agua: nunca romper el mapa por esto.
     */
    'carto' => [
        'basemaps_key' => env('CARTO_BASEMAPS_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
