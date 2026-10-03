<?php

declare(strict_types=1);

return [
    // Laravel's own keys (used by Fortify for login errors).
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many attempts. Please try again in :seconds seconds.',

    'fields' => [
        'email' => 'Email address',
        'password' => 'Password',
        'new_password' => 'New password',
        'confirm_password' => 'Confirm new password',
        'current_password' => 'Current password',
        'remember' => 'Keep me signed in on this device',
        'code' => 'Authentication code',
        'recovery_code' => 'Recovery code',
    ],

    'login' => [
        'title' => 'Sign in',
        'intro' => 'Sign in with your administrator account.',
        'submit' => 'Sign in',
        'forgot' => 'Forgotten your password?',
    ],

    'forgot' => [
        'title' => 'Reset your password',
        'intro' => 'Enter your email address and we will send you a link to choose a new password. The link works for 60 minutes.',
        'submit' => 'Email me a reset link',
        'back' => 'Back to sign in',
    ],

    'reset' => [
        'title' => 'Choose a new password',
        'rules' => 'At least 12 characters, with upper and lower case letters and a number.',
        'submit' => 'Save new password',
    ],

    'confirm_password' => [
        'title' => 'Confirm your password',
        'intro' => 'For your security, enter your password to continue.',
        'submit' => 'Continue',
    ],

    'verify_email' => [
        'title' => 'Verify your email address',
        'intro' => 'Follow the link we emailed you to verify your address.',
        'resend' => 'Send the link again',
        'sent' => 'A new verification link has been sent.',
    ],

    'two_factor' => [
        'required' => 'Set up two-factor authentication to continue.',
        'challenge' => [
            'title' => 'Two-factor authentication',
            'intro_code' => 'Enter the 6-digit code from your authenticator app.',
            'intro_recovery' => 'Enter one of your recovery codes. Each code works once.',
            'submit' => 'Continue',
            'use_recovery' => 'Use a recovery code instead',
            'use_code' => 'Use an authentication code instead',
        ],
        'setup' => [
            'title' => 'Set up two-factor authentication',
            'intro' => 'Two-factor authentication adds a 6-digit code to every sign-in. You will need an authenticator app on your phone, such as Google Authenticator or Microsoft Authenticator.',
            'start' => 'Start setup',
            'scan' => 'Scan this QR code with your authenticator app.',
            'qr_alt' => 'QR code for your authenticator app',
            'manual' => 'Can\'t scan it? Enter this key in the app instead:',
            'confirm_intro' => 'Then enter the 6-digit code the app shows.',
            'confirm' => 'Confirm and turn on',
            'codes_title' => 'Save your recovery codes',
            'codes_intro' => 'If you lose your phone, each of these codes lets you sign in once. They are shown only now: store them somewhere safe, such as a password manager.',
            'codes_list' => 'Recovery codes',
            'done' => 'I have saved my codes. Continue',
        ],
    ],

    'settings' => [
        'title' => 'Security',
        'password_title' => 'Change password',
        'password_submit' => 'Save password',
        'password_saved' => 'Password changed.',
        'two_factor_title' => 'Two-factor authentication',
        'two_factor_on' => 'Two-factor authentication is on.',
        'two_factor_off' => 'Two-factor authentication is off. Turning it on adds a 6-digit code from an authenticator app to every sign-in.',
        'two_factor_set_up' => 'Set up two-factor authentication',
        'regenerate' => 'Replace my recovery codes',
        'regenerate_intro' => 'This makes your old recovery codes stop working and shows new ones once.',
    ],
];
