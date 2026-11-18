<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * OTP channel — final decision (see docs/decisions/otp-channel.md): WhatsApp
 * only. ERD v2.0 and PRD v0.7.1 both specified MTN/Syriatel carrier SMS;
 * this is an explicit reversal back to what the code already implements,
 * not an oversight to "fix" toward the documents. Every OTP-touching file
 * must stay carrier-agnostic.
 *
 * Scoped to the OTP domain only — "mtn"/"syriatel" are legitimate
 * `payment_methods.code` values from Phase 4 onward (mobile-money payment
 * gateways, unrelated to identity verification) and must not be banned
 * repo-wide.
 */
class OtpChannelIsWhatsappOnlyTest extends TestCase
{
    use ScansSourceFiles;

    private const OTP_DIRS = [
        'app/Services/Otp',
    ];

    public function test_otp_verifications_provider_enum_is_whatsapp_only(): void
    {
        $path = database_path('migrations/2026_07_29_113337_create_otp_verifications_table.php');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertMatchesRegularExpression(
            "/enum\\('provider',\\s*\\['whatsapp'\\]\\)/",
            $contents,
            'otp_verifications.provider must remain enum(\'provider\', [\'whatsapp\']) — see docs/decisions/otp-channel.md.'
        );
    }

    public function test_no_otp_source_file_references_a_carrier_sms_channel(): void
    {
        $violations = [];

        foreach (self::OTP_DIRS as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path => $contents) {
                if (preg_match('/\b(mtn|syriatel)\b/i', $contents, $match)) {
                    $violations[] = "{$path} references \"{$match[1]}\" as an OTP channel";
                }
            }
        }

        $this->assertSame([], $violations, "OTP channel is WhatsApp-only, final decision:\n".implode("\n", $violations));
    }
}
