<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound SMS opt-in keywords
    |--------------------------------------------------------------------------
    |
    | When a contact sends one of these keywords (case-insensitive, matched
    | on the first word of the message body), the contact is opted in for
    | SMS marketing from the WhatsApp inbox. This is the consent path used
    | by Twilio, MessageBird and most US carriers for "text-to-join".
    |
    | Add or remove keywords here without redeploying the driver — the
    | driver reads from config() each time it processes a message.
    |
    */

    'sms_optin_keywords' => [
        // English
        'start', 'yes', 'y', 'subscribe', 'subs', 'optin', 'opt-in', 'unstop', 'on',
        // Arabic (whitespace-tolerant matching inside the driver)
        'اشتراك', 'نعم', 'موافق', 'ابدأ', 'ابدا', 'تفعيل',
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound SMS opt-out keywords
    |--------------------------------------------------------------------------
    |
    | Carriers (especially US carriers) require that STOP, STOPALL,
    | UNSUBSCRIBE, CANCEL, END and QUIT are honoured across every text.
    | We mirror that list here. Adding the standard set is non-negotiable;
    | removal may put you in violation of carrier and CTIA guidelines.
    |
    */

    'sms_optout_keywords' => [
        // English — the CTIA / carrier-mandated set.
        'stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit',
        'optout', 'opt-out', 'no', 'off',
        // Arabic
        'إيقاف', 'ايقاف', 'الغاء', 'إلغاء', 'توقف', 'لا',
    ],

];