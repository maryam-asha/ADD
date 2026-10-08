<?php

namespace App\Http\Requests\Provider;

use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\CompanyInvitation;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Http\Middleware\EnsureActiveProvider;
use App\Rules\PhoneNumber;
use App\Support\Plans\PlanRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Everything a provider request must satisfy, checked when it is
 * submitted so the reviewer never sees a malformed one; approval checks
 * again under a lock (ProviderRequestService::approve). A member request's
 * answer never depends on whether the phone already has an account.
 */
class StoreProviderRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function type(): ?ProviderRequestType
    {
        return ProviderRequestType::tryFrom((string) $this->input('type'));
    }

    public function provider(): Provider
    {
        return $this->attributes->get(EnsureActiveProvider::ATTRIBUTE);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = ['company_id' => ['required', 'integer']];
        $payer = ['payer' => ['required', Rule::enum(Payer::class)]];

        return ['type' => ['required', Rule::enum(ProviderRequestType::class)]] + match ($this->type()) {
            ProviderRequestType::CompanyMember => $company + [
                'phone' => ['required', 'string', new PhoneNumber],
                'name' => ['required', 'string', 'max:255'],
                'door_access_enabled' => ['sometimes', 'boolean'],
            ],
            ProviderRequestType::CompanyAdmin => $company + [
                'user_id' => ['required', 'integer'],
            ],
            ProviderRequestType::PlanAssignment => $company + $payer + [
                'plan_id' => ['required', 'integer'],
                'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                'seats' => ['required', 'integer', 'min:1', 'max:1000'],
            ],
            ProviderRequestType::WalletTopUp => $company + $payer + [
                'points' => ['required', 'integer', 'min:1'],
            ],
            ProviderRequestType::PlanDesign => Arr::except(PlanRules::rules(), PlanRules::PROVIDER_EXCLUDED),
            null => [],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->type();

            if ($type === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $company = null;

            if ($type->needsCompany()) {
                $company = Company::query()->find($this->integer('company_id'));

                if ($company === null || $company->provider_id !== $this->provider()->id || $company->status->value !== 'active') {
                    $validator->errors()->add('company_id', __('api.provider.company_unavailable'));

                    return;
                }
            }

            match ($type) {
                ProviderRequestType::PlanAssignment => $this->checkAssignment($validator, $company),
                ProviderRequestType::CompanyMember => $this->checkMember($validator, $company),
                ProviderRequestType::CompanyAdmin => $this->checkAdmin($validator, $company),
                default => null,
            };

            // validated() is not available inside an after() hook, so the
            // duplicate check reads the same keys straight from the input.
            if ($validator->errors()->isEmpty() && app(ProviderRequestService::class)->duplicateExists($type, $company?->id, $this->only(['phone', 'user_id']))) {
                $validator->errors()->add($this->duplicateField($type), __('api.provider.duplicate_request'));
            }
        });
    }

    /** What the request carries beyond its type and company. */
    public function payload(): array
    {
        return Arr::except($this->validated(), ['type', 'company_id']);
    }

    private function checkAssignment(Validator $validator, Company $company): void
    {
        $plan = Plan::query()->find($this->integer('plan_id'));

        if ($plan === null || ! $plan->is_active || (! $plan->is_public && $plan->provider_id !== $this->provider()->id)) {
            $validator->errors()->add('plan_id', __('api.provider.plan_unavailable'));
        }

        if (app(MembershipResolver::class)->activeForOwner(OwnerType::Company, $company->id) !== null) {
            $validator->errors()->add('company_id', __('api.admin.membership_already_active'));
        }
    }

    /**
     * Already an employee, or already invited: the same refusal as a
     * duplicate pending request, and nothing about other accounts.
     */
    private function checkMember(Validator $validator, Company $company): void
    {
        $phone = (string) $this->input('phone');

        $taken = $company->members()->where('users.phone', $phone)->exists()
            || CompanyInvitation::query()->open()->where('company_id', $company->id)->where('phone', $phone)->exists();

        if ($taken) {
            $validator->errors()->add('phone', __('api.provider.duplicate_request'));
        }
    }

    private function checkAdmin(Validator $validator, Company $company): void
    {
        $member = $company->members()->where('users.id', $this->integer('user_id'))->first();

        if ($member === null) {
            $validator->errors()->add('user_id', __('api.provider.not_a_company_member'));
        } elseif ($member->pivot->is_admin) {
            $validator->errors()->add('user_id', __('api.provider.already_company_admin'));
        }
    }

    private function duplicateField(ProviderRequestType $type): string
    {
        return match ($type) {
            ProviderRequestType::CompanyMember => 'phone',
            ProviderRequestType::CompanyAdmin => 'user_id',
            default => 'company_id',
        };
    }
}
