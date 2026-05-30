<?php
/**
 * Unit tests for Disciple_Tools_CRM_Sync_Field_Mapper.
 *
 * Covers core field mapping and custom field type dispatch.
 */

use Brain\Monkey\Functions;

class FieldMapperTest extends BrainMonkeyTestCase {

    private Disciple_Tools_CRM_Sync_Field_Mapper $mapper;

    protected function setUp(): void {
        parent::setUp();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_email' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();

        $connector    = new Disciple_Tools_CRM_Sync_Connector_Respond_IO( [
            'api_url'   => 'https://api.respond.io',
            'api_token' => 'token',
        ] );
        $this->mapper = new Disciple_Tools_CRM_Sync_Field_Mapper( $connector );
    }

// Core field mapping

    public function test_map_core_fields_constructs_title_from_first_and_last_name(): void {
        $fields = $this->mapper->map_core_fields(
            [ 'firstName' => 'Jane', 'lastName' => 'Doe' ],
            true
        );

        $this->assertSame( 'Jane Doe', $fields['title'] );
    }

    public function test_map_core_fields_phone_fallback(): void {
        $fields = $this->mapper->map_core_fields(
            [ 'firstName' => '', 'lastName' => '', 'phone' => '+1234567890' ],
            true
        );

        $this->assertSame( '+1234567890', $fields['title'] );
    }

    public function test_map_core_fields_email_fallback(): void {
        $fields = $this->mapper->map_core_fields(
            [ 'firstName' => '', 'lastName' => '', 'email' => 'jane@example.com' ],
            true
        );

        $this->assertSame( 'jane@example.com', $fields['title'] );
    }

    public function test_map_core_fields_maps_phone_to_dt_contact_phone_format(): void {
        $fields = $this->mapper->map_core_fields(
            [ 'firstName' => 'A', 'phone' => '+15555550100' ],
            true
        );

        $this->assertArrayHasKey( 'contact_phone', $fields );
        $this->assertSame( '+15555550100', $fields['contact_phone']['values'][0]['value'] );
        $this->assertArrayHasKey( 'title', $fields );
        $this->assertSame( 'A', $fields['title'] );
        $this->assertArrayHasKey( 'sources', $fields );
        $this->assertSame( 'respond_io', $fields['sources']['values'][0]['value'] );
    }

    public function test_map_core_fields_omits_empty_phone(): void {
        $fields = $this->mapper->map_core_fields(
            [ 'firstName' => 'A', 'phone' => '' ],
            true
        );

        $this->assertArrayNotHasKey( 'contact_phone', $fields );
    }

    public function test_map_core_fields_maps_email_to_dt_contact_email_format(): void {
        $fields = $this->mapper->map_core_fields(
            [ 'firstName' => 'A', 'email' => 'a@example.com' ],
            true
        );

        $this->assertArrayHasKey( 'contact_email', $fields );
        $this->assertSame( 'a@example.com', $fields['contact_email']['values'][0]['value'] );
    }

    public function test_map_core_fields_maps_tags_as_multiselect_values(): void {
        $fields = $this->mapper->map_core_fields(
            [ 'firstName' => 'A', 'tags' => [ 'vip', 'new-lead' ] ],
            true
        );

        $this->assertArrayHasKey( 'tags', $fields );
        $this->assertSame( 'vip', $fields['tags']['values'][0]['value'] );
        $this->assertSame( 'new-lead', $fields['tags']['values'][1]['value'] );
    }

    public function test_map_core_fields_includes_type_access_on_create_only(): void {
        $create_fields = $this->mapper->map_core_fields( [ 'firstName' => 'A' ], true );
        $update_fields = $this->mapper->map_core_fields( [ 'firstName' => 'A' ], false );

        $this->assertArrayHasKey( 'type', $create_fields );
        $this->assertArrayNotHasKey( 'type', $update_fields );
    }

// Custom field type dispatch

    /** @dataProvider custom_fields_provider */
    public function test_map_custom_fields( string $type, $input_value, $expected_value ): void {
        Functions\when( 'get_option' )->justReturn( [
            'test_field' => [ 'dt_key' => 'dt_test_key', 'dt_type' => $type ],
        ] );

        $fields = $this->mapper->map_custom_fields( [
            'custom_fields' => [ [ 'name' => 'test_field', 'value' => $input_value ] ],
        ] );

        $this->assertArrayHasKey( 'dt_test_key', $fields );
        $this->assertSame( $expected_value, $fields['dt_test_key'] );
    }

    public static function custom_fields_provider(): array {
        return [
            'text'         => [ 'text', 'High', 'High' ],
            'multi_select' => [ 'multi_select', 'sports', [ 'values' => [ [ 'value' => 'sports' ] ] ] ],
            'date'         => [ 'date', '1990-06-15T00:00:00Z', '1990-06-15' ],
            'number'       => [ 'number', '42', 42 ],
            'boolean_true' => [ 'boolean', 'true', true ],
            'boolean_false' => [ 'boolean', '0', false ],
            'textarea'     => [ 'textarea', 'Some notes here', 'Some notes here' ],
        ];
    }
}
