@php
    $messages = [
        'confirmed' => [
            'fa' => 'عضویت شما تأیید شد. به باشگاه وب پیانو خوش آمدید!',
            'en' => 'Your sign-up is confirmed. Welcome to the Web Piano Club!',
        ],
        'unsubscribed' => [
            'fa' => 'عضویت شما لغو شد و ایمیل شما از لیست حذف شد.',
            'en' => 'You have been unsubscribed and your email was removed from the list.',
        ],
        'invalid' => [
            'fa' => 'این لینک معتبر نیست یا قبلاً استفاده شده است.',
            'en' => 'This link is invalid or has already been used.',
        ],
    ];
    $m = $messages[$state] ?? $messages['invalid'];
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Web Piano</title>
</head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f5f4;font-family:Tahoma,Arial,sans-serif;color:#1c1917;">
    <main style="max-width:420px;margin:24px;background:#ffffff;border-radius:12px;padding:28px;text-align:center;line-height:1.9;">
        <p style="font-size:18px;margin:0 0 8px;">{{ $m['fa'] }}</p>
        <p dir="ltr" style="color:#78716c;margin:0;">{{ $m['en'] }}</p>
    </main>
</body>
</html>