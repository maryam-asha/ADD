# OTP channel: WhatsApp only — MTN/Syriatel reversed

**Status:** resolved 2026-08-08, **final**. **Owner:** Maryam Asha.

## What each source said

- **PRD v0.7.1** §2.1 and §5.1 state the OTP channel is **MTN and Syriatel**
  carrier SMS, calling it "محسوم وغير معلّق" (settled, not pending).
- **Master ERD v2.0** follows the PRD: `otp_verifications.provider` is
  `enum: mtn | syriatel`.
- **Document 4** also lists MTN/Syriatel, but as `channel[mtn_cash|syriatel_cash]`
  — those are payment-gateway codes (see `payment_methods.code` in both ERD
  documents), not carriers. This looks like it was copied from the payments
  section by mistake, and was one of the two blocking conflicts named in the
  original build-plan review.
- **Existing code**, predating both documents, implements **WhatsApp**:
  `otp_verifications.provider` is `enum('whatsapp')`, and `OtpService` /
  `OtpServiceProvider` / `MockOtpProvider` are built entirely around a
  WhatsApp Business API driver landing later behind the `OtpProvider`
  interface.

## Decision

**WhatsApp stays the sole OTP channel. This is not a bug fix toward the
documents — it is an explicit reversal of the MTN/Syriatel decision that PRD
v0.7.1 and ERD v2.0 both state.** Nothing in the codebase assumes MTN/Syriatel
as an identity-verification channel from this point on.

## What was given up

Carrier SMS OTP would have removed the dependency on a specific messaging
app being installed, and would have matched what PRD v0.7.1 currently
documents as settled. That is real: this reversal means the PRD is now
stale on one of its own §7.1 "locked" decisions, and whoever owns the PRD
document should update it to avoid a future reader trusting §5.1 over this
record.

## What this changed in code

- `app/Services/Otp/OtpProvider.php` — removed the doc-comment naming
  MTN/Syriatel as example carriers; points here instead.
- No migration or model change: `otp_verifications.provider` was already
  `enum('whatsapp')` and stays that way.

## Guard

[`OtpChannelIsWhatsappOnlyTest`](../../tests/Guards/OtpChannelIsWhatsappOnlyTest.php)
asserts the `provider` enum stays `['whatsapp']` and that no file under
`app/Services/Otp` names a carrier SMS channel. Scoped to that directory
only — `mtn`/`syriatel` are legitimate `payment_methods.code` values from
Phase 4 onward and must not be banned repo-wide.
