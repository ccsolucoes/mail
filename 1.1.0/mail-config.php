<?php
/**
 * CCMail config
 * - mode: 'mail' (default) or 'smtp'
 * - allowed_origins: the websites allowed to call your endpoint
 */
return [
  'mode' => 'mail', // or 'smtp'

  'defaults' => [
    'from_email' => 'no-reply@yourdomain.com',
    'from_name'  => 'Your Website',
    'text_only'  => true, // set false if you plan to send HTML
  ],

  // Contact-form target
  'contact' => [
    'to_email' => 'you@yourdomain.com',
    'default_subject' => 'New contact form message',
  ],

  // If you set mode=smtp, fill these:
  'smtp' => [
    'host' => 'smtp.yourdomain.com',
    'port' => 587,
    'secure' => 'tls', // tls | ssl | none
    'username' => 'you@yourdomain.com',
    'password' => 'YOUR_SMTP_PASSWORD',
    'helo' => '', // optional; defaults to server name
    'timeout' => 12,
  ],

  'security' => [
    // Example: allow your site + www + a staging subdomain
    'allowed_origins' => [
      'https://yourdomain.com',
      'https://www.yourdomain.com',
      'https://staging.yourdomain.com',
      // Wildcards allowed:
      // 'https://*.yourdomain.com',
    ],
    // Rate limit storage directory (must be writable)
    'rate_limit_dir' => sys_get_temp_dir() . '/ccmail_rl',
  ],

  'logging' => [
    'enabled' => true,
    'file' => sys_get_temp_dir() . '/ccmail.log',
  ],
];
