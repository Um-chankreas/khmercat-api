<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Email Verification</title>
</head>

<body style="font-family: Arial, sans-serif; background-color: #f4f4f7; color: #51545e; margin: 0; padding: 20px;">
    <div
        style="max-width: 500px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h2 style="color: #333333; margin-bottom: 10px;">Hello {{ $userName }},</h2>
        <p style="font-size: 16px; line-height: 1.5;">Thank you for registering. Use the code below to verify your
            account:</p>

        <div style="text-align: center; margin: 30px 0;">
            <span
                style="font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #1a202c; background-color: #edf2f7; padding: 12px 24px; border-radius: 6px; display: inline-block;">
                {{ $otpCode }}
            </span>
        </div>

        <p style="font-size: 14px; color: #718096;">This verification code expires in <strong>10 minutes</strong>.</p>
    </div>
</body>

</html>
