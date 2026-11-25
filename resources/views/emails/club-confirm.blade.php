<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:Tahoma,Arial,sans-serif;color:#1c1917;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;padding:24px;line-height:1.8;">
        <h2 style="margin:0 0 12px;">باشگاه وب پیانو</h2>
        <p>برای تکمیل عضویت، روی دکمه‌ی زیر بزنید:</p>
        <p>
            <a href="{{ $confirmUrl }}" style="display:inline-block;background:#292524;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:8px;">تأیید عضویت</a>
        </p>
        <p style="font-size:13px;color:#78716c;">اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید؛ بدون تأیید شما عضو نمی‌شوید.</p>
        <hr style="border:none;border-top:1px solid #e7e5e4;margin:20px 0;">
        <div dir="ltr" style="text-align:left;">
            <p>To finish joining the Web Piano Club, please confirm your email:</p>
            <p>
                <a href="{{ $confirmUrl }}" style="display:inline-block;background:#292524;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:8px;">Confirm my sign-up</a>
            </p>
            <p style="font-size:13px;color:#78716c;">If you didn't request this, just ignore this email; you won't be subscribed without confirming.</p>
        </div>
        <p style="font-size:12px;color:#a8a29e;margin-top:20px;">
            لغو عضویت / Unsubscribe: <a href="{{ $unsubscribeUrl }}" style="color:#a8a29e;">{{ $unsubscribeUrl }}</a>
        </p>
    </div>
</body>
</html>