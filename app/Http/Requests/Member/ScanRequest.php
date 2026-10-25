<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class ScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Either what the member scanned — the kiosk's code or a lock's sticker —
     * or the ticket from a confirmation_required answer together with the
     * member's explicit acceptance.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr_value' => ['required_without:scan_ticket', 'prohibits:scan_ticket', 'string', 'max:255'],
            'scan_ticket' => ['required_without:qr_value', 'string'],
            'accept_uncovered' => ['exclude_without:scan_ticket', 'accepted'],
        ];
    }
}
