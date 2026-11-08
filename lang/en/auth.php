<?php

declare(strict_types=1);

return [
    // Laravel's own keys (used by Fortify for login errors).
    // The same for an unknown email, a wrong password, a disabled or a locked account (SECURITY.md §2).
    'failed' => 'These details did not let you sign in. After repeated failures an account is locked for a while: try again later, or reset your password.',
    'password_reused' => 'Choose a password you have not used before. It cannot be any of your last :count.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many attempts. Please try again in :seconds seconds.',

    'fields' => [
        'email' => 'Email address',
        'password' => 'Password',
        'new_password' => 'New password',
        'confirm_password' => 'Confirm new password',
        'current_password' => 'Current password',
    ],

    'login' => [
        'title' => 'Sign in',
        'intro' => 'Sign in with your administrator account.',
        'submit' => 'Sign in',
        'forgot' => 'Forgotten your password?',
        'session_expired' => 'Your session has expired. Sign in again to carry on.',
    ],

    'forgot' => [
        'title' => 'Reset your password',
        'intro' => 'Enter your email address and we will send you a link to choose a new password. The link works for 60 minutes.',
        'submit' => 'Email me a reset link',
        'back' => 'Back to sign in',
    ],

    'reset' => [
        'title' => 'Choose a new password',
        'rules' => 'At least 14 characters, with upper and lower case letters, a number and a symbol. Not one of your last 5 passwords.',
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
        'log_out' => 'Log out',
    ],

    // The new-device sign-in email (SECURITY.md §2, App\Notifications\NewDeviceLogin).
    'new_device' => [
        'subject' => 'eDQA portal: :name signed in from a new device',
        'intro_self' => 'Your administrator account signed in from a device not seen in the last :days days.',
        'intro_other' => ':name signed in to the eDQA portal from a device not seen in the last :days days. Every administrator gets this email.',
        'time' => 'Time: :time',
        'ip' => 'IP address: :ip',
        'browser' => 'Browser: :browser',
        'browser_on' => ':browser on :system',
        'unknown_browser' => 'An unrecognised browser',
        'not_you_self' => 'If this was not you, change your password now: that signs out every other session. Then tell the other administrators.',
        'not_you_other' => 'If you do not expect this, check with :name. If it was not them, disable the account with `php artisan edqa:admin:disable` and reset its password.',
    ],

    'settings' => [
        'title' => 'Security',
        'password_title' => 'Change password',
        'password_submit' => 'Save password',
        'password_saved' => 'Password changed. You have been signed out everywhere else.',
    ],
];
