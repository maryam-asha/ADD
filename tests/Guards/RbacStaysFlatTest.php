<?php

namespace Tests\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * D.8 (docs/decisions/rbac-scoping.md): flat spatie roles stay flat. The
 * one scoped capability — a company's shared door code — is a boolean on
 * one pivot row plus one Policy method, not a general scope_type/scope_id
 * system. If either shows up, D.8 has been quietly walked back.
 */
class RbacStaysFlatTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES_THAT_MUST_STAY_SCOPE_FREE = [
        'roles', 'permissions', 'model_has_roles', 'company_user',
    ];

    public function test_no_scope_type_or_scope_id_column_exists_on_any_role_related_table(): void
    {
        $violations = [];

        foreach (self::TABLES_THAT_MUST_STAY_SCOPE_FREE as $table) {
            foreach (['scope_type', 'scope_id'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $violations[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame([], $violations, "D.8 — no scoped-role system; only a flag on company_user:\n".implode("\n", $violations));
    }

    public function test_company_policy_is_the_only_policy_class_in_the_app(): void
    {
        $policyFiles = [];

        foreach (glob(app_path('Domain/*/Policies/*.php')) as $file) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            $policyFiles[] = str_replace('\\', '/', $relative);
        }

        $this->assertSame(
            ['app/Domain/Identity/Policies/CompanyPolicy.php'],
            $policyFiles,
            'D.8 — CompanyPolicy is the one deliberate exception to flat RBAC; a second Policy needs its own decision record, not a silent addition.'
        );
    }
}
