<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound WhatsApp opt-in keywords
    |--------------------------------------------------------------------------
    |
    | When a contact sends one of these keywords on the WhatsApp channel
    | (case-insensitive, matched on the first word of the message body,
    | including button-reply titles and list-reply titles), the contact is
    | opted in for WhatsApp marketing. This is the WhatsApp-channel consent
    | path — it does NOT, and legally cannot, grant SMS consent.
    |
    | Add or remove keywords here without redeploying the driver — the
    | driver reads from config() each time it processes a message.
    |
    */

    'optin_keywords' => [
        // English
        'start', 'yes', 'y', 'subscribe', 'subs', 'optin', 'opt-in', 'unstop', 'on',
        // Arabic (whitespace-tolerant matching inside the driver)
        'اشتراك', 'نعم', 'موافق', 'ابدأ', 'ابدا', 'تفعيل',
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound WhatsApp opt-out keywords
    |--------------------------------------------------------------------------
    |
    | When a contact sends one of these keywords on the WhatsApp channel,
    | the contact is opted out of WhatsApp marketing only. SMS consent is a
    | separate channel and is never affected by what happens here.
    |
    | We mirror the CTIA / carrier-mandated STOP family for consistency,
    | even though WhatsApp Business API has its own opt-out surface — being
    | conservative here keeps the legal posture identical across channels.
    |
    */

    'optout_keywords' => [
        // English — the CTIA / carrier-mandated set.
        'stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit',
        'optout', 'opt-out', 'no', 'off',
        // Arabic
        'إيقاف', 'ايقاف', 'الغاء', 'إلغاء', 'توقف', 'لا',
    ],

];