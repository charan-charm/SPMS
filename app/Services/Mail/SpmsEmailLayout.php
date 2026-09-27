<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Support\Config;

/**
 * Reusable SPMS Transactional Email Visual System.
 *
 * Implements the unified Pastel Green + Off-White visual language across
 * all SPMS transactional emails (verification, recovery, notifications).
 *
 * Visual tokens:
 *   - Primary canvas: #F7FAF7 (very light warm off-white green)
 *   - Main card: #FFFFFF (soft white with subtle border)
 *   - Primary pastel green: #DDF7E8 / #F0FDF4
 *   - SPMS brand green: #22C55E / #16A34A
 *   - Deep navy: #081B33
 *   - Soft body text: #64748B / #475569
 *   - Clean borders: #E2E8F0 / #BBF7D0
 *
 * Logo delivery:
 *   - Uses an email-compatible approved SPMS PNG asset (logo-primary.png).
 *   - Renders with explicit width and height.
 *   - Avoids raw inline SVG for the primary brand mark (Gmail-safe).
 *   - Framed with subtle botanical greenery.
 */
final class SpmsEmailLayout
{
    /** Default public HTTPS URL for the approved SPMS logo-primary.png asset */
    public const DEFAULT_LOGO_URL = 'https://iili.io/nT3wFMg.png';

    /**
     * Render a transactional email using the unified SPMS visual system.
     *
     * @param array{
     *   subject: string,
     *   title: string,
     *   subtitle: string,
     *   code: string,
     *   expiryText: string,
     *   instruction: string,
     *   securityNote: string
     * } $data
     */
    public static function renderHtml(array $data): string
    {
        $safeCode = preg_replace('/[^0-9]/', '', $data['code']);
        $safeCode = str_pad(substr($safeCode, 0, 6), 6, '0', STR_PAD_LEFT);

        $logoUrl = Config::get('MAIL_LOGO_URL');
        if (!is_string($logoUrl) || trim($logoUrl) === '') {
            $logoUrl = self::DEFAULT_LOGO_URL;
        }

        $title = htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars($data['subtitle'], ENT_QUOTES, 'UTF-8');
        $expiryText = htmlspecialchars($data['expiryText'], ENT_QUOTES, 'UTF-8');
        $instruction = htmlspecialchars($data['instruction'], ENT_QUOTES, 'UTF-8');
        $securityNote = htmlspecialchars($data['securityNote'], ENT_QUOTES, 'UTF-8');
        $subject = htmlspecialchars($data['subject'], ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="light" />
  <meta name="supported-color-schemes" content="light" />
  <title>{$subject}</title>
  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <![endif]-->
  <style type="text/css">
    :root {
      color-scheme: light !important;
      supported-color-schemes: light !important;
    }
    /* Prevent webmail dark-mode color inversions where CSS is supported */
    @media (prefers-color-scheme: dark) {
      body, .email-canvas {
        background-color: #f8fafc !important;
        color: #081b33 !important;
      }
      .email-card {
        background-color: #ffffff !important;
        border-color: #e2e8f0 !important;
      }
      .email-header, .email-body {
        background-color: #ffffff !important;
      }
      .email-text-navy, .email-title {
        color: #081b33 !important;
      }
      .email-text-body {
        color: #475569 !important;
      }
      .email-text-muted {
        color: #64748b !important;
      }
      .email-code-box {
        background-color: #f0fdf4 !important;
        border-color: #bbf7d0 !important;
      }
      .email-code-text {
        color: #081b33 !important;
      }
      .email-note-box {
        background-color: #f7faf7 !important;
        border-left-color: #22c55e !important;
      }
      .email-footer {
        background-color: #f8fafc !important;
        border-top-color: #e2e8f0 !important;
      }
    }
    /* Gmail Android / iOS dark mode preservation hooks */
    [data-ogsc] body, [data-ogsc] .email-canvas,
    [data-ogsb] body, [data-ogsb] .email-canvas {
      background-color: #f8fafc !important;
    }
    [data-ogsc] .email-card, [data-ogsb] .email-card {
      background-color: #ffffff !important;
      border-color: #e2e8f0 !important;
    }
    [data-ogsc] .email-header, [data-ogsb] .email-header,
    [data-ogsc] .email-body, [data-ogsb] .email-body {
      background-color: #ffffff !important;
    }
    [data-ogsc] .email-text-navy, [data-ogsb] .email-text-navy,
    [data-ogsc] .email-title, [data-ogsb] .email-title {
      color: #081b33 !important;
    }
    [data-ogsc] .email-text-body, [data-ogsb] .email-text-body {
      color: #475569 !important;
    }
    [data-ogsc] .email-text-muted, [data-ogsb] .email-text-muted {
      color: #64748b !important;
    }
    [data-ogsc] .email-code-box, [data-ogsb] .email-code-box {
      background-color: #f0fdf4 !important;
      border-color: #bbf7d0 !important;
    }
    [data-ogsc] .email-code-text, [data-ogsb] .email-code-text {
      color: #081b33 !important;
    }
    [data-ogsc] .email-note-box, [data-ogsb] .email-note-box {
      background-color: #f7faf7 !important;
      border-left-color: #22c55e !important;
    }
    [data-ogsc] .email-footer, [data-ogsb] .email-footer {
      background-color: #f8fafc !important;
    }
  </style>
</head>
<body class="email-canvas" bgcolor="#f8fafc" style="margin:0;padding:0;background-color:#f8fafc;color:#081b33;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
  <table role="presentation" class="email-canvas" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8fafc"
         style="background-color:#f8fafc;min-height:100vh;padding:40px 16px;">
    <tr>
      <td align="center" valign="top" bgcolor="#f8fafc">

        <!-- Main Card Container -->
        <table role="presentation" class="email-card" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff"
               style="max-width:540px;width:100%;background-color:#ffffff;border-radius:14px;
                      border:1px solid #e2e8f0;box-shadow:0 4px 20px rgba(8,27,51,0.04);overflow:hidden;">

          <!-- Brand Header with Approved SPMS PNG Logo & Subtle Botanical Greenery -->
          <tr>
            <td align="center" class="email-header" bgcolor="#ffffff"
                style="padding:36px 32px 24px;text-align:center;border-bottom:1px solid #f1f5f9;background-color:#ffffff;color:#081b33;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="margin:0 auto;background-color:#ffffff;">
                <tr>
                  <!-- Left subtle botanical leaf accent -->
                  <td valign="middle" bgcolor="#ffffff" style="padding-right:14px;background-color:#ffffff;">
                    <svg width="24" height="24" viewBox="-50 -140 100 150" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;display:inline-block;" aria-hidden="true">
                      <path d="M0 0 C -22 -34 -20 -86 0 -130 C 20 -86 22 -34 0 0 Z" fill="#16a34a" />
                    </svg>
                  </td>

                  <!-- Central Approved SPMS Brand Mark (PNG with explicit dimensions) -->
                  <td align="center" valign="middle" bgcolor="#ffffff" style="background-color:#ffffff;">
                    <img src="{$logoUrl}"
                         width="200"
                         height="67"
                         alt="SPMS"
                         style="display:block;margin:0 auto;width:200px;height:auto;max-width:100%;border:0;outline:none;text-decoration:none;" />
                  </td>

                  <!-- Right subtle botanical leaf accent -->
                  <td valign="middle" bgcolor="#ffffff" style="padding-left:14px;background-color:#ffffff;">
                    <svg width="24" height="24" viewBox="-50 -140 100 150" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;display:inline-block;transform:scaleX(-1);" aria-hidden="true">
                      <path d="M0 0 C -22 -34 -20 -86 0 -130 C 20 -86 22 -34 0 0 Z" fill="#16a34a" />
                    </svg>
                  </td>
                </tr>
              </table>

              <p class="email-text-navy" style="margin:12px 0 0;font-size:11px;font-weight:700;color:#081b33;letter-spacing:0.16em;text-transform:uppercase;">
                SOLAR POWER MANAGEMENT SYSTEM
              </p>
            </td>
          </tr>

          <!-- Message Body -->
          <tr>
            <td class="email-body" bgcolor="#ffffff" style="padding:34px 38px 28px;background-color:#ffffff;color:#475569;">

              <h1 class="email-title" style="margin:0 0 10px;font-size:22px;font-weight:700;color:#081b33;letter-spacing:-0.02em;line-height:1.3;">
                {$title}
              </h1>

              <p class="email-text-body" style="margin:0 0 24px;font-size:15px;color:#475569;line-height:1.6;">
                {$subtitle}
              </p>

              <!-- 6-Digit Code Block -->
              <table role="presentation" class="email-code-table" width="100%" cellpadding="0" cellspacing="0" border="0"
                     style="margin-bottom:24px;">
                <tr>
                  <td align="center" class="email-code-box" bgcolor="#f0fdf4"
                      style="background-color:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;
                             padding:24px 18px;text-align:center;color:#081b33;">
                    <div class="email-code-text"
                         style="font-size:38px;font-weight:800;letter-spacing:0.32em;
                                color:#081b33;font-family:'SF Mono',Consolas,'Liberation Mono',Menlo,'Courier New',Courier,monospace;
                                line-height:1.1;margin-left:0.32em;">
                      {$safeCode}
                    </div>
                    <p class="email-text-muted" style="margin:12px 0 0;font-size:12px;font-weight:600;color:#64748b;">
                      {$expiryText}
                    </p>
                  </td>
                </tr>
              </table>

              <!-- Instruction -->
              <p class="email-text-body" style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.6;">
                {$instruction}
              </p>

              <!-- Subtle Security Note -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td class="email-note-box" bgcolor="#f7faf7"
                      style="background-color:#f7faf7;border-left:3px solid #22c55e;
                             border-radius:4px;padding:12px 16px;color:#64748b;">
                    <p class="email-text-muted" style="margin:0;font-size:13px;color:#64748b;line-height:1.5;">
                      {$securityNote}
                    </p>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- Clean Footer -->
          <tr>
            <td class="email-footer" bgcolor="#f8fafc"
                style="background-color:#f8fafc;border-top:1px solid #e2e8f0;
                       padding:22px 38px;text-align:center;color:#081b33;">
              <p class="email-text-navy" style="margin:0;font-size:11px;font-weight:700;color:#081b33;letter-spacing:0.12em;text-transform:uppercase;">
                SOLAR POWER MANAGEMENT SYSTEM
              </p>
              <p style="margin:6px 0 0;font-size:11px;color:#94a3b8;">
                This is an automated security notification. Please do not reply.
              </p>
            </td>
          </tr>

        </table>
        <!-- /Main Card Container -->

      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Render a plain-text fallback for email clients that do not render HTML.
     *
     * @param array{
     *   title: string,
     *   subtitle: string,
     *   code: string,
     *   expiryText: string,
     *   instruction: string,
     *   securityNote: string
     * } $data
     */
    public static function renderText(array $data): string
    {
        $safeCode = preg_replace('/[^0-9]/', '', $data['code']);
        $safeCode = str_pad(substr($safeCode, 0, 6), 6, '0', STR_PAD_LEFT);

        return implode("\r\n", [
            'SOLAR POWER MANAGEMENT SYSTEM',
            str_repeat('=', 40),
            '',
            $data['title'],
            '',
            $data['subtitle'],
            '',
            '    ' . $safeCode,
            '',
            $data['expiryText'],
            '',
            $data['instruction'],
            '',
            '----------------------------------------',
            $data['securityNote'],
            '----------------------------------------',
            '',
            'SOLAR POWER MANAGEMENT SYSTEM',
        ]);
    }
}
