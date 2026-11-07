<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-79: the implementation plan is public, so an owner is a role (a unit such as "LGA M&E
 * officer"), never a person. plan_actions.assigned_to (free text) becomes owner_role, one of the
 * role keys (config edqa.plan.roles, labels in lang/en/plan.php owners). A stored role, by label or
 * key, keeps it; anything else was a person, kept in owner_contact (admin-only, never public), and
 * the row takes the dimension's default role. down() puts assigned_to back: the contact if any,
 * else the role's label.
 */
return new class extends Migration
{
    /** The roles as they stand at this migration: key => label. */
    private const ROLES = [
        'lga_me_officer' => 'LGA M&E officer',
        'state_me_team' => 'State M&E team',
        'programme_manager' => 'Programme manager',
    ];

    /** The dimension's default role (config edqa.plan.default_owners at this migration). */
    private const DEFAULTS = [
        'availability' => 'lga_me_officer',
        'consistency' => 'state_me_team',
        'validity' => 'programme_manager',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE plan_actions ADD COLUMN owner_role varchar(40)');
        DB::statement('ALTER TABLE plan_actions ADD COLUMN owner_contact text');

        foreach (self::ROLES as $key => $label) {
            DB::table('plan_actions')->whereIn('assigned_to', [$key, $label])->update(['owner_role' => $key]);
        }
        foreach (self::DEFAULTS as $dimension => $role) {
            DB::statement('UPDATE plan_actions SET owner_role = ?, owner_contact = NULLIF(TRIM(assigned_to), \'\') WHERE owner_role IS NULL AND dimension = ?', [$role, $dimension]);
        }

        DB::statement('ALTER TABLE plan_actions ALTER COLUMN owner_role SET NOT NULL');
        DB::statement('ALTER TABLE plan_actions ADD CONSTRAINT plan_actions_owner_role_valid CHECK (owner_role IN (\''.implode("', '", array_keys(self::ROLES)).'\'))');
        DB::statement('ALTER TABLE plan_actions DROP COLUMN assigned_to');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE plan_actions ADD COLUMN assigned_to varchar(255)');
        foreach (self::ROLES as $key => $label) {
            DB::table('plan_actions')->where('owner_role', $key)->update(['assigned_to' => DB::raw('COALESCE(owner_contact, '.DB::getPdo()->quote($label).')')]);
        }
        DB::statement('ALTER TABLE plan_actions ALTER COLUMN assigned_to SET NOT NULL');
        DB::statement('ALTER TABLE plan_actions DROP CONSTRAINT IF EXISTS plan_actions_owner_role_valid');
        DB::statement('ALTER TABLE plan_actions DROP COLUMN owner_contact');
        DB::statement('ALTER TABLE plan_actions DROP COLUMN owner_role');
    }
};
