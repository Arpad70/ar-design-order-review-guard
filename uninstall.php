<?php
/**
 * Uninstall hook for AR Design Order Review Guard.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Data zatím nemažeme automaticky.
// Secure Bin a auditní záznamy mají zůstat zachované do zavedení retenční politiky.
