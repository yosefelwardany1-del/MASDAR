<?php

/*
| Documents as PDF, sent by email or WhatsApp (spec §5.8, §8). The printed pages (PI, quotation,
| purchase contract, packing list, commercial invoice, credit and debit notes) are turned into
| PDF by a headless Chromium on the server: the Docker image installs it; elsewhere set
| PDF_CHROME_BINARY. Without one the PDF and send buttons say so, and printing from the browser
| still works.
*/

return [
    'chrome_binary' => env('PDF_CHROME_BINARY'),

    // Searched in order when PDF_CHROME_BINARY is not set.
    // A pattern with * is globbed (Playwright's headless shell, on a developer's machine).
    'chrome_candidates' => [
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
        '/opt/pw-browsers/chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
    ],

    'timeout' => (int) env('PDF_TIMEOUT', 30),

    // Email from the system needs a real mailer (MAIL_MAILER smtp, ses, postmark …). In
    // production the log mailer is refused, so nobody thinks a message left that never did.
    'email_requires_real_mailer' => (bool) env('DOCUMENTS_EMAIL_REQUIRES_REAL_MAILER', env('APP_ENV') === 'production'),
];
