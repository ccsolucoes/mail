# CCMail v1.1

A tiny, client-ship-ready mail endpoint for simple website forms.

## What you get
- **mail() mode** (default): zero dependencies.
- **smtp mode**: authenticated SMTP via `AUTH LOGIN` with optional `STARTTLS` / `SSL` (still zero deps).
- CORS allowlist (including wildcards like `https://*.example.com`)
- Honeypot field
- File-based rate limit (per IP + Origin)
- Safe headers (anti header-injection)
- LGPD-friendly logging (no message body stored)

---

## Files
- `CCMail.php` — mailer class
- `mail-config.php` — config
- `mail-api.php` — JSON API endpoint for your forms
- `contact-form-example.html` — integration example

---

## Install
Upload the folder to somewhere that runs PHP, for example:

`https://cdn.yourdomain.com/ccmail/`

Make sure your server can write to:
- `sys_get_temp_dir()` (default for logs/rate-limit), or customize paths in `mail-config.php`.

---

## Configure
Edit `mail-config.php`:
- Set `contact.to_email`
- Set `defaults.from_email` and `defaults.from_name`
- Add your allowed origins in `security.allowed_origins`

### mail() mode (default)
Keep:
```php
'mode' => 'mail',
```

### smtp mode (recommended for deliverability)
Set:
```php
'mode' => 'smtp',
```
and fill `smtp.host`, `smtp.username`, `smtp.password`, etc.

---

## Frontend integration
Use the included `contact-form-example.html` or post JSON like:

```js
fetch("https://cdn.yourdomain.com/ccmail/mail-api.php", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({
    name: "Luan",
    email: "luan@example.com",
    phone: "",
    subject: "Hello",
    message: "Testing",
    website: "" // honeypot
  })
})
```

---

## Notes for production
- For best inbox placement, use:
  - a domain email (not gmail in From)
  - proper SPF/DKIM on your sending domain
  - SMTP mode if your host's `mail()` is unreliable
