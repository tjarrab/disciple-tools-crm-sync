<?php
/**
 * Connector subsystem index — direct access guard only.
 *
 * All connector classes are loaded explicitly by the main plugin class.
 * This file exists solely to block direct web requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
