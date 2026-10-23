<?php

return [

    // Push to the Android app through Firebase Cloud Messaging (docs/PLAN.md, D36; free). The service account
    // key: Firebase console → Project settings → Service accounts → Generate new private key. It lives only on
    // the server, like .env; with no file there, nothing is pushed and the bot keeps every notification.
    'credentials' => env('FIREBASE_CREDENTIALS') ?: storage_path('app/private/firebase-service-account.json'),

    // Empty: the project from the key.
    'project_id' => env('FIREBASE_PROJECT_ID'),

    // The notification channel the app creates (app/src/lib/push.ts).
    'android_channel' => env('FCM_ANDROID_CHANNEL', 'kompas'),

];
