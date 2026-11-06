<?php

declare(strict_types=1);

// Minimal iTop substitutes: capture the real module's registration and DB writes.
class SetupWebPage
{
    public static array $module;

    public static function AddModule($file, $id, $settings): void
    {
        self::$module = [$id, $settings];
    }
}
class ModuleInstallerAPI {}
class Config {}
class SetupLog
{
    public static function Info($message): void {}
}
class LandscapeTestObject
{
    public function __construct(public string $class, public array $values) {}
    public function Set($key, $value): void { $this->values[$key] = $value; }
    public function GetKey(): int { return array_search($this, MetaModel::$objects, true) + 1; }
    public function DBWrite(): void { MetaModel::$objects[] = $this; }
}
class MetaModel
{
    public static array $objects = [];
    public static array $available = ['AuditRule', 'AuditDomain'];
    public static function IsValidClass($class): bool { return in_array($class, self::$available, true); }
    public static function NewObject($class, $values): LandscapeTestObject
    {
        return new LandscapeTestObject($class, $values);
    }
}
class DBObjectSearch
{
    public static function FromOQL($query): string { return $query; }
}
class DBObjectSet
{
    public function __construct(private string $query) {}
    public function Fetch(): ?LandscapeTestObject
    {
        foreach (MetaModel::$objects as $object) {
            if ($this->query === 'SELECT ' . $object->class . ' WHERE name = "' . $object->values['name'] . '"') {
                return $object;
            }
        }
        return null;
    }
}
class Dict
{
    public static array $entries = [];
    public static function Add($locale, $language, $native, $entries): void { self::$entries[$locale] = $entries; }
}

require __DIR__ . '/../br-landscape/module.br-landscape.php';
require __DIR__ . '/../br-landscape/dictionaries/de.dict.br-landscape.php';
require __DIR__ . '/../br-landscape/dictionaries/en.dict.br-landscape.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$model = simplexml_load_file(__DIR__ . '/../br-landscape/datamodel.br-landscape.xml', SimpleXMLElement::class, LIBXML_NONET);
if ($model === false) {
    throw new RuntimeException('Cannot load landscape data model.');
}
$install = static function (string $previous, array $available = ['AuditRule', 'AuditDomain']): array {
    MetaModel::$objects = [];
    MetaModel::$available = $available;
    SystemLandscapeInstaller::AfterDatabaseCreation(new Config(), $previous, '3.2.1');
    return MetaModel::$objects;
};

return [
    'landscape field exposes development, QA and production with production default' => static function () use ($model, $check): void {
        $fields = $model->xpath('/itop_design/classes/class[@id="FunctionalCI"]/fields/field[@id="system_landscape"]');
        $check(count($fields) === 1, 'Expected one inherited landscape field.');
        $field = $fields[0];
        $check((string) $field->attributes('http://www.w3.org/2001/XMLSchema-instance')->type === 'AttributeEnum', 'Landscape must be an enum.');
        $codes = array_map(static fn ($value): string => (string) $value->code, iterator_to_array($field->values->value, false));
        $check($codes === ['dev', 'qas', 'prd'], 'Unexpected landscape values or order.');
        $check((string) $field->default_value === 'prd' && (string) $field->is_null_allowed === 'true', 'Unexpected default or null policy.');
    },
    'landscape is visible in supported CI details and lists' => static function () use ($model, $check): void {
        foreach (['Server', 'NetworkDevice', 'VirtualMachine', 'Hypervisor', 'Farm', 'ApplicationSolution'] as $class) {
            foreach (['details', 'list'] as $view) {
                $items = $model->xpath('/itop_design/classes/class[@id="' . $class . '"]/presentation/' . $view . '//item[@id="system_landscape"]');
                $check(count($items) === 1, "Missing or duplicate landscape in $class/$view.");
            }
        }
    },
    'German and English dictionaries cover the landscape field and every value' => static function () use ($model, $check): void {
        $prefix = 'Class:FunctionalCI/Attribute:system_landscape';
        $keys = [$prefix, $prefix . '+'];
        foreach ($model->xpath('/itop_design/classes/class[@id="FunctionalCI"]/fields/field[@id="system_landscape"]/values/value') as $value) {
            $keys[] = $prefix . '/Value:' . $value['id'];
            $keys[] = $prefix . '/Value:' . $value['id'] . '+';
        }
        foreach (['DE DE', 'EN US'] as $locale) {
            foreach ($keys as $key) {
                $check(trim(Dict::$entries[$locale][$key] ?? '') !== '', "Missing $locale translation: $key.");
            }
        }
    },
    'module registers the landscape installer and required dependencies' => static function () use ($check): void {
        [$id, $settings] = SetupWebPage::$module;
        $manifest = simplexml_load_file(__DIR__ . '/../extension.xml');
        $check($id === 'br-landscape/' . trim((string) $manifest->version), 'Registration differs from manifest.');
        $check($settings['installer'] === SystemLandscapeInstaller::class, 'Wrong installer registered.');
        $check($settings['dependencies'] === ['itop-structure/3.2.0', 'itop-virtualization-mgmt/0.2.0'], 'Required dependencies changed.');
    },
    'fresh install creates three mismatch rules and links their category to the domain' => static function () use ($install, $check): void {
        $objects = $install('0.0.0');
        $classes = array_column($objects, 'class');
        $check($classes === ['AuditCategory', 'AuditRule', 'AuditRule', 'AuditRule', 'AuditDomain', 'lnkAuditCategoryToAuditDomain'], 'Unexpected installation objects.');
        $comparisons = ['h.system_landscape != s.system_landscape', 'h.system_landscape != f.system_landscape', 'a.system_landscape != f.system_landscape'];
        foreach ($comparisons as $index => $comparison) {
            $rule = $objects[$index + 1]->values;
            $check($rule['category_id'] === $objects[0]->GetKey() && $rule['valid_flag'] === 'false', 'Mismatch rule has wrong category or polarity.');
            $check(str_contains($rule['query'], $comparison), 'Mismatch comparison missing.');
        }
        $check($objects[5]->values === ['domain_id' => $objects[4]->GetKey(), 'category_id' => $objects[0]->GetKey()], 'Wrong audit domain link.');
    },
    'upgrade to audit domains preserves existing category and rules' => static function () use ($install, $check): void {
        $objects = $install('0.0.0');
        MetaModel::$objects = array_slice($objects, 0, 4);
        SystemLandscapeInstaller::AfterDatabaseCreation(new Config(), '3.1.0', '3.2.1');
        $check(count(MetaModel::$objects) === 6 && MetaModel::$objects[0] === $objects[0], 'Upgrade recreated rules or lost the category.');
        $before = MetaModel::$objects;
        SystemLandscapeInstaller::AfterDatabaseCreation(new Config(), '3.2.0', '3.2.1');
        $check(MetaModel::$objects === $before, 'Already migrated installation was changed.');
    },
    'installer skips unavailable audit classes' => static function () use ($install, $check): void {
        $check($install('0.0.0', []) === [], 'Unavailable audit classes should not create objects.');
    },
];
