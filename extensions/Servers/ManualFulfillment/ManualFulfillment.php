<?php

namespace Paymenter\Extensions\Servers\ManualFulfillment;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;

/**
 * ManualFulfillment - A server extension for manual provisioning.
 *
 * After a user pays, the admin manually purchases the resource and fills in
 * credential fields (e.g. IP, username, password) via the admin panel.
 * Those credentials are then displayed to the user on the service detail page.
 *
 * The credential fields are NOT hard-coded. Admins define them through the
 * extension-level config ("Credential Fields") using a tag input.
 */
#[ExtensionMeta(
    name: 'Manual Fulfillment',
    description: 'Allows admins to manually fill in service credentials after purchase.',
    version: '1.0.0',
    author: 'Paymenter',
)]
class ManualFulfillment extends Server
{
    /**
     * Extension-level config.
     *
     * "mf_credential_fields" defines which credential fields the admin wants
     * to use, via a tag input (e.g. "IP", "Username", "Password").
     *
     * "mf_auto_activate" controls whether the service is automatically marked
     * as active on createServer, or stays pending until the admin fills
     * in the credentials.
     */
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'mf_credential_fields',
                'label' => 'Credential Fields',
                'type' => 'tags',
                'required' => true,
                'database_type' => 'array',
                'description' => 'Define the credential field names to show for each service (e.g. IP, Username, Password).',
            ],
            [
                'name' => 'mf_auto_activate',
                'label' => 'Auto Activate on Creation',
                'type' => 'checkbox',
                'default' => false,
                'database_type' => 'boolean',
                'description' => 'If enabled, the service is immediately activated on creation. Otherwise it stays pending until the admin fills in credentials.',
            ],
        ];
    }

    /**
     * No product-level config needed for this extension.
     */
    public function getProductConfig($values = []): array
    {
        return [];
    }

    /**
     * No test needed - there is no external API to connect to.
     */
    public function testConfig(): bool|string
    {
        return true;
    }

    /**
     * Parse the configured field names from the "mf_credential_fields" setting.
     *
     * Depending on how the value was stored/retrieved, it may arrive as:
     *   - a PHP array  (normal path via Retrieved event json_decode)
     *   - a JSON string (if type metadata was missing)
     *   - a comma-separated string (legacy fallback)
     *
     * @return array<string> e.g. ['IP', 'Username', 'Password']
     */
    private function parseFields(): array
    {
        $raw = $this->config('mf_credential_fields');

        if (is_array($raw)) {
            return array_values(array_filter(array_map('trim', $raw)));
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map('trim', $decoded)));
            }

            // Fallback: comma-separated
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        return [];
    }

    /**
     * Convert a human-readable field name to a storage key.
     *
     * e.g. "IP Address" -> "mf_ip_address"
     */
    private function fieldKey(string $name): string
    {
        return 'mf_' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name)));
    }

    /**
     * Called when the service is created (after payment).
     *
     * We initialize empty property placeholders for each configured field
     * so the admin can see them in the properties relation manager and fill
     * them in later.
     */
    public function createServer(Service $service, $settings, $properties)
    {
        $fields = $this->parseFields();

        foreach ($fields as $fieldName) {
            $service->properties()->updateOrCreate(
                ['key' => $this->fieldKey($fieldName)],
                ['name' => $fieldName, 'value' => '']
            );
        }

        return true;
    }

    /**
     * Suspend - nothing to do externally.
     */
    public function suspendServer(Service $service, $settings, $properties)
    {
        return true;
    }

    /**
     * Unsuspend - nothing to do externally.
     */
    public function unsuspendServer(Service $service, $settings, $properties)
    {
        return true;
    }

    /**
     * Terminate - remove the credential properties from the service.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $fields = $this->parseFields();

        foreach ($fields as $fieldName) {
            $service->properties()->where('key', $this->fieldKey($fieldName))->delete();
        }

        return true;
    }

    /**
     * Display the filled-in credentials as text fields on the user's
     * service detail page. Only show fields that have a non-empty value.
     */
    public function getActions(Service $service, $settings = null, $properties = null): array
    {
        $fields = $this->parseFields();
        $actions = [];

        foreach ($fields as $fieldName) {
            $key = $this->fieldKey($fieldName);
            $value = $properties[$key] ?? '';

            if ($value !== '') {
                $actions[] = [
                    'type' => 'text',
                    'label' => $fieldName,
                    'text' => $value,
                ];
            }
        }

        return $actions;
    }
}
