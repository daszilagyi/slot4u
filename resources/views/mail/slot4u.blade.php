{{--
    The slot4u mail theme (SLO-244). Selected by `mail.markdown.theme`; the
    Markdown renderer inlines it into every system email. A Blade view rather
    than a static .css file so the colours come from MailBrand — the one place
    the superadmin brand settings (SLO-245) will change.

    ⚠️ Email CSS, not web CSS: it is inlined attribute by attribute, so no
    custom properties, no flex/grid, and every colour spelled out.
--}}
@php($brand = app(\App\Support\Mail\MailBrand::class))
/* Base */

body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: 'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    position: relative;
}

body {
    -webkit-text-size-adjust: none;
    background-color: {{ $brand->canvas }};
    color: {{ $brand->ink }};
    height: 100%;
    line-height: 1.5;
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p,
ul,
ol,
blockquote {
    line-height: 1.6;
    text-align: start;
}

ul,
ol {
    color: {{ $brand->ink }};
    font-size: 16px;
    margin-top: 0;
    padding-left: 22px;
}

li {
    margin-bottom: 6px;
}

a {
    color: {{ $brand->link }};
}

a img {
    border: none;
}

strong {
    color: {{ $brand->ink }};
}

/* Typography */

h1 {
    color: {{ $brand->ink }};
    font-size: 22px;
    font-weight: 800;
    line-height: 1.3;
    margin-top: 0;
    margin-bottom: 16px;
    text-align: start;
}

h2 {
    color: {{ $brand->ink }};
    font-size: 18px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h3 {
    color: {{ $brand->ink }};
    font-size: 16px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

p {
    color: {{ $brand->ink }};
    font-size: 16px;
    margin-top: 0;
    margin-bottom: 16px;
    text-align: start;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

/* Layout */

.wrapper {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: {{ $brand->canvas }};
    margin: 0;
    padding: 32px 12px;
    width: 100%;
}

.content {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    margin: 0 auto;
    padding: 0;
    width: 570px;
}

/* Header */

.header {
    background-color: {{ $brand->headerBackground }};
    border-radius: 14px 14px 0 0;
    padding: 20px 32px;
    text-align: start;
}

.header a,
.header .brand-name {
    color: {{ $brand->headerText }};
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.2px;
    text-decoration: none;
    vertical-align: middle;
}

.logo {
    border-radius: 11px;
    height: 40px;
    margin-right: 12px;
    max-height: 40px;
    vertical-align: middle;
    width: 40px;
}

/* Body */

.body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    background-color: {{ $brand->surface }};
    border-left: 1px solid {{ $brand->line }};
    border-right: 1px solid {{ $brand->line }};
    border-bottom: 1px solid {{ $brand->line }};
    border-radius: 0 0 14px 14px;
    margin: 0;
    padding: 0;
    width: 570px;
}

.inner-body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 0 auto;
    padding: 0;
    width: 100%;
}

.inner-body a {
    word-break: break-word;
}

/* Subcopy */

.subcopy {
    border-top: 1px solid {{ $brand->line }};
    margin-top: 24px;
    padding-top: 20px;
}

.subcopy p {
    color: {{ $brand->inkMuted }};
    font-size: 13px;
}

/* Footer */

.footer {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    margin: 0 auto;
    padding: 0;
    text-align: center;
    width: 570px;
}

.footer p {
    color: {{ $brand->inkMuted }};
    font-size: 12px;
    line-height: 1.6;
    margin-bottom: 6px;
    text-align: center;
}

.footer a {
    color: {{ $brand->inkMuted }};
    text-decoration: underline;
}

.footer .content-cell {
    padding: 20px 32px 8px;
}

/* Tables */

.table table {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 24px auto;
    width: 100%;
}

.table th {
    border-bottom: 1px solid {{ $brand->line }};
    margin: 0;
    padding-bottom: 8px;
}

.table td {
    color: {{ $brand->ink }};
    font-size: 15px;
    line-height: 18px;
    margin: 0;
    padding: 10px 0;
}

.content-cell {
    max-width: 100vw;
    padding: 32px;
}

/* Buttons */

.action {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 28px auto;
    padding: 0;
    text-align: center;
    width: 100%;
    float: unset;
}

.button {
    -webkit-text-size-adjust: none;
    border-radius: 10px;
    display: inline-block;
    font-size: 16px;
    font-weight: 800;
    overflow: hidden;
    text-decoration: none;
}

.button-blue,
.button-primary {
    background-color: {{ $brand->buttonBackground }};
    border-bottom: 12px solid {{ $brand->buttonBackground }};
    border-left: 26px solid {{ $brand->buttonBackground }};
    border-right: 26px solid {{ $brand->buttonBackground }};
    border-top: 12px solid {{ $brand->buttonBackground }};
    color: {{ $brand->buttonText }};
}

.button-green,
.button-success {
    background-color: #1E9E6A;
    border-bottom: 12px solid #1E9E6A;
    border-left: 26px solid #1E9E6A;
    border-right: 26px solid #1E9E6A;
    border-top: 12px solid #1E9E6A;
    color: #FFFFFF;
}

.button-red,
.button-error {
    background-color: #D33A3A;
    border-bottom: 12px solid #D33A3A;
    border-left: 26px solid #D33A3A;
    border-right: 26px solid #D33A3A;
    border-top: 12px solid #D33A3A;
    color: #FFFFFF;
}

/* Panels */

.panel {
    border-left: {{ $brand->link }} solid 4px;
    margin: 21px 0;
}

.panel-content {
    background-color: {{ $brand->canvas }};
    color: {{ $brand->ink }};
    padding: 16px;
}

.panel-content p {
    color: {{ $brand->ink }};
}

.panel-item {
    padding: 0;
}

.panel-item p:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
}

/* Utilities */

.break-all {
    word-break: break-all;
}
